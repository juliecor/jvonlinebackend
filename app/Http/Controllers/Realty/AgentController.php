<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AgentInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A realty's Agents section. Inviting makes a link the realty sends however it
 * likes (Messenger, Viber, email); the link opens the join form, where the agent
 * applies. Staff then approve or reject the application. Staff only.
 */
class AgentController extends Controller
{
    /** Active agents, applications waiting on staff (and rejected ones), then open invitations — one list for the page. */
    public function index(Request $request): JsonResponse
    {
        $realty = $request->user()->realty;

        $agents = $realty->agents()->withCount(['offers as offers_count' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'joined_at' => $u->created_at,
            'offers_count' => $u->offers_count,
        ]);

        $invited = $realty->agentInvitations()->whereNull('accepted_at')->latest()->get()->map(fn (AgentInvitation $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'email' => $i->email,
            'invited_at' => $i->created_at,
            'expires_at' => $i->expires_at,
            'expired' => $i->expires_at->isPast(),
        ]);

        $applications = $realty->agentApplications()->with('reviewer:id,name')
            ->orderByRaw('case when status = ? then 0 else 1 end', [User::STATUS_PENDING])->latest()->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'status' => $u->status,
                'applied_at' => $u->created_at,
                'reviewed_at' => $u->reviewed_at,
                'reviewed_by' => $u->reviewer?->name,
                'has_resume' => (bool) $u->resume_path,
                'resume_name' => $u->resume_name,
                'resume_size' => $u->resume_size,
            ]);

        return response()->json(['agents' => $agents, 'applications' => $applications, 'invitations' => $invited]);
    }

    /** Make an invitation and hand back its link. */
    public function store(Request $request): JsonResponse
    {
        $realty = $request->user()->realty;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $existing = ! empty($data['email']) ? User::where('email', $data['email'])->first() : null;
        if ($existing) {
            $message = match (true) {
                $existing->realty_id !== $realty->id => 'There is already an account with this email.',
                $existing->status === User::STATUS_PENDING => "{$existing->name} has already applied. Review them under Pending approval.",
                $existing->status === User::STATUS_REJECTED => "{$existing->name}'s application was rejected. Delete it to invite them again.",
                default => "{$existing->name} is already one of your agents.",
            };

            return response()->json(['message' => $message, 'errors' => ['email' => [$message]]], 422);
        }

        [$invitation, $token] = AgentInvitation::issue($realty, $data['name'], $data['email'] ?? null, $request->user());

        return response()->json($this->withLink($invitation, $token), 201);
    }

    /** A fresh link for an open invitation (the old one stops working). */
    public function resend(Request $request, AgentInvitation $invitation): JsonResponse
    {
        abort_unless($invitation->realty_id === $request->user()->realty_id, 404);
        if ($invitation->accepted_at) {
            return response()->json(['message' => "{$invitation->name} has already joined."], 409);
        }
        $token = $invitation->refresh();

        return response()->json($this->withLink($invitation->fresh(), $token));
    }

    /** Let an applicant in (also undoes a rejection). */
    public function approve(Request $request, User $agent): JsonResponse
    {
        $this->ownApplicant($request, $agent);
        if ($agent->isActive()) {
            return response()->json(['message' => "{$agent->name} is already approved."], 409);
        }
        $agent->update(['status' => User::STATUS_ACTIVE, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return response()->json(['id' => $agent->id, 'status' => $agent->status]);
    }

    /** Turn an application down. It stays listed (with the resume) until staff delete it. */
    public function reject(Request $request, User $agent): JsonResponse
    {
        $this->ownApplicant($request, $agent);
        if ($agent->status !== User::STATUS_PENDING) {
            return response()->json(['message' => 'Only a pending application can be rejected.'], 409);
        }
        $agent->update(['status' => User::STATUS_REJECTED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $agent->tokens()->delete();

        return response()->json(['id' => $agent->id, 'status' => $agent->status]);
    }

    /** Remove a rejected application and its resume, so the email can be invited again. */
    public function destroy(Request $request, User $agent): Response
    {
        $this->ownApplicant($request, $agent);
        abort_unless($agent->status === User::STATUS_REJECTED, 409, 'Only a rejected application can be deleted.');

        DB::transaction(function () use ($agent) {
            $agent->tokens()->delete();
            $agent->delete();
        });
        if ($agent->resume_path) {
            Storage::disk(User::resumeDisk())->delete($agent->resume_path);
        }

        return response()->noContent();
    }

    /** Opens an applicant's resume (the dashboard streams it through to the browser). */
    public function resume(Request $request, User $agent)
    {
        $this->ownApplicant($request, $agent);
        $disk = Storage::disk(User::resumeDisk());
        abort_unless($agent->resume_path && $disk->exists($agent->resume_path), 404, 'This agent has no resume on file.');

        return $disk->response($agent->resume_path, $agent->resume_name ?? 'resume.pdf', [
            'Content-Type' => 'application/pdf',
            // Never let an uploaded file run as a page.
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** Staff only reach their own realty's agents. */
    private function ownApplicant(Request $request, User $agent): void
    {
        abort_unless($agent->role === User::ROLE_AGENT && $agent->realty_id === $request->user()->realty_id, 404);
    }

    private function withLink(AgentInvitation $invitation, string $token): array
    {
        return $invitation->toArray() + ['join_url' => AgentInvitation::url($invitation->realty, $token)];
    }
}
