<?php

namespace App\Http\Controllers;

use App\Models\Realty;
use App\Models\RealtyInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The registration form a realty reaches from its invite email (jvconline.ph/register/<token>). */
class RegistrationController extends Controller
{
    /** Who this link is for — so the form can greet them and prefill the email. */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->usable($token);

        return response()->json([
            'realty' => $invitation->realty->publicArray(),
            'email' => $invitation->email,
        ]);
    }

    /** Fill in the realty's profile, create its first login, and switch it to active. */
    public function store(Request $request, string $token): JsonResponse
    {
        $invitation = $this->usable($token);
        $realty = $invitation->realty;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $logoPath = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;

        $user = DB::transaction(function () use ($data, $realty, $invitation, $logoPath) {
            $realty->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'about' => $data['about'] ?? null,
                'logo_path' => $logoPath ?? $realty->logo_path,
                'status' => Realty::STATUS_ACTIVE,
                'registered_at' => now(),
            ]);
            $invitation->update(['accepted_at' => now()]);

            return User::create([
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_REALTY,
                'realty_id' => $realty->id,
            ]);
        });

        return response()->json(['realty' => $realty->fresh()->publicArray(), 'user' => ['id' => $user->id, 'email' => $user->email]], 201);
    }

    private function usable(string $token): RealtyInvitation
    {
        $invitation = RealtyInvitation::with('realty')->where('token_hash', hash('sha256', $token))->first();
        if (! $invitation) {
            abort(404, 'This invitation link is not valid.');
        }
        if ($invitation->accepted_at) {
            abort(410, 'This invitation has already been used.');
        }
        if ($invitation->expires_at->isPast()) {
            abort(410, 'This invitation link has expired. Ask jvconline for a new one.');
        }

        return $invitation;
    }
}
