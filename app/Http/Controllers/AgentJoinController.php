<?php

namespace App\Http\Controllers;

use App\Models\AgentInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** An agent accepting a realty's invite (jvconline.ph/<realty>/join/<token>): they pick their password. */
class AgentJoinController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        return response()->json([
            'realty' => $invitation->realty->publicArray(),
            'name' => $invitation->name,
            'email' => $invitation->email,
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (User::where('email', $invitation->email)->exists()) {
            return response()->json(['message' => 'There is already an account with this email.'], 409);
        }

        $user = DB::transaction(function () use ($data, $invitation) {
            $invitation->update(['accepted_at' => now()]);

            return User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => User::ROLE_AGENT,
                'realty_id' => $invitation->realty_id,
            ]);
        });

        return response()->json(['realty' => $invitation->realty->publicArray(), 'user' => ['id' => $user->id, 'email' => $user->email]], 201);
    }

    private function usable(string $token): AgentInvitation
    {
        $invitation = AgentInvitation::with('realty')->where('token_hash', hash('sha256', $token))->first();
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
