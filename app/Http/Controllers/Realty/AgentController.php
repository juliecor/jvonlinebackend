<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\AgentInvitationMail;
use App\Models\AgentInvitation;
use App\Models\Realty;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** A realty's Agents section: who's in, who's been invited, invite and resend. Staff only. */
class AgentController extends Controller
{
    /** Active agents first, then open invitations — one list for the page. */
    public function index(Request $request): JsonResponse
    {
        $realty = $request->user()->realty;

        $agents = $realty->agents()->orderBy('name')->get()->map(fn (User $u) => [
            'kind' => 'agent',
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'joined_at' => $u->created_at,
        ]);

        $invited = $realty->agentInvitations()->whereNull('accepted_at')->latest()->get()->map(fn (AgentInvitation $i) => [
            'kind' => 'invitation',
            'id' => $i->id,
            'name' => $i->name,
            'email' => $i->email,
            'invited_at' => $i->created_at,
            'expires_at' => $i->expires_at,
            'expired' => $i->expires_at->isPast(),
        ]);

        return response()->json(['agents' => $agents, 'invitations' => $invited]);
    }

    public function store(Request $request): JsonResponse
    {
        $realty = $request->user()->realty;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'There is already an account with this email.', 'errors' => ['email' => ['There is already an account with this email.']]], 422);
        }

        $invitation = DB::transaction(function () use ($realty, $data, $request) {
            // One open invite per email: a new one replaces the old link.
            $realty->agentInvitations()->where('email', $data['email'])->whereNull('accepted_at')->delete();

            return $this->sendInvite($realty, $data['name'], $data['email'], $request->user());
        });

        return response()->json($invitation, 201);
    }

    public function resend(Request $request, AgentInvitation $invitation): JsonResponse
    {
        $realty = $request->user()->realty;
        if ($invitation->realty_id !== $realty->id) {
            abort(404);
        }
        if ($invitation->accepted_at) {
            return response()->json(['message' => "{$invitation->name} has already joined."], 409);
        }

        // Same row, new link — so the page's list keeps the same entry.
        $token = $invitation->refresh();
        Mail::to($invitation->email)->send(new AgentInvitationMail($realty, $invitation->name, AgentInvitation::url($realty, $token), $invitation->expires_at));

        return response()->json($invitation->fresh());
    }

    private function sendInvite(Realty $realty, string $name, string $email, User $by): AgentInvitation
    {
        [$invitation, $token] = AgentInvitation::issue($realty, $name, $email, $by);
        Mail::to($email)->send(new AgentInvitationMail($realty, $name, AgentInvitation::url($realty, $token), $invitation->expires_at));

        return $invitation;
    }
}
