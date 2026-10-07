<?php

namespace App\Http\Controllers;

use App\Models\AgentInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * An agent accepting a realty's invite (jvconline.ph/<realty>/join/<token>): they pick their
 * password and send a contact number, then wait for the realty's staff to approve them.
 */
class AgentJoinController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        return response()->json([
            'realty' => $invitation->realty->publicArray(),
            'name' => $invitation->name,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            // Shown on the join page so the agent knows who sent it and until when.
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    /** The agent's application: their login and contact number. Staff approve it before they can sign in. */
    public function store(Request $request, string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            // A Philippine mobile number: 11 digits starting with 09, digits only.
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => 'There is already an account with this email.',
            'phone.required' => 'Enter your contact number.',
            'phone.regex' => 'Enter an 11-digit mobile number starting with 09, numbers only, like 09171234567.',
        ]);

        $user = DB::transaction(function () use ($data, $invitation) {
            $invitation->update(['accepted_at' => now(), 'email' => $data['email']]);

            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => User::ROLE_AGENT,
                'status' => User::STATUS_PENDING,
                'realty_id' => $invitation->realty_id,
            ]);
        });

        return response()->json(['realty' => $invitation->realty->publicArray(), 'user' => ['id' => $user->id, 'email' => $user->email, 'status' => $user->status]], 201);
    }

    private function usable(string $token): AgentInvitation
    {
        $invitation = AgentInvitation::with(['realty', 'inviter:id,name'])->where('token_hash', hash('sha256', $token))->first();
        if (! $invitation) {
            abort(404, 'This invitation link is not valid.');
        }
        if ($invitation->accepted_at) {
            abort(410, 'This invitation has already been used.');
        }
        if ($invitation->expires_at->isPast()) {
            abort(410, 'This invitation link has expired. Ask your realty to send it again.');
        }

        return $invitation;
    }
}
