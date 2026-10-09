<?php

namespace App\Http\Controllers;

use App\Mail\AgentApplicationReceivedMail;
use App\Models\AgentInvitation;
use App\Models\Realty;
use App\Models\User;
use App\Support\QuietMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * An agent accepting a realty's invite (jvconline.ph/<realty>/join/<token>): they pick their
 * password and send a contact number, then wait for the realty's staff to approve them. The staff
 * are emailed, and the applicant stays signed in on a "pending" page that opens the dashboard
 * once they are approved. That session can reach nothing else: every dashboard route refuses an
 * account that is not approved.
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

        $realty = $invitation->realty->loadMissing('developer');
        $this->tellStaff($realty, $user);

        return response()->json([
            'realty' => $realty->publicArray(),
            'user' => ['id' => $user->id, 'email' => $user->email, 'status' => $user->status],
            // Same session length as a normal sign-in, so the pending page can follow the application.
            'token' => $user->createToken('realty-web:'.$realty->slug, ['*'])->plainTextToken,
        ], 201);
    }

    /** Every active admin of the realty hears about the application, each with a link to review it. */
    private function tellStaff(Realty $realty, User $applicant): void
    {
        User::where('realty_id', $realty->id)->where('role', User::ROLE_REALTY)->where('status', User::STATUS_ACTIVE)->pluck('email')
            ->each(fn (string $email) => QuietMail::send($email, new AgentApplicationReceivedMail($applicant, $realty), "agent application {$applicant->id}"));
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
