<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AgentInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A realty's Agents section. Inviting makes a link the realty sends however it
 * likes (Messenger, Viber, email); the link opens the join form. Staff only.
 */
class AgentController extends Controller
{
    /** Active agents, then open invitations — one list for the page. */
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

        return response()->json(['agents' => $agents, 'invitations' => $invited]);
    }

    /** Make an invitation and hand back its link. */
    public function store(Request $request): JsonResponse
    {
        $realty = $request->user()->realty;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        if (! empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'There is already an account with this email.', 'errors' => ['email' => ['There is already an account with this email.']]], 422);
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

    private function withLink(AgentInvitation $invitation, string $token): array
    {
        return $invitation->toArray() + ['join_url' => AgentInvitation::url($invitation->realty, $token)];
    }
}
