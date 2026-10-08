<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Each person's own account, whoever they are (platform admin, super admin,
 * realty admin, agent): their name, email and phone, and their password.
 * Only those columns are ever saved, so a super admin previewing a realty
 * (role and realty swapped in memory) keeps their real role.
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->details($request->user()));
    }

    /** Name, email and phone. A new email (it's the sign-in) needs the current password. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{7,}$/'],
            'current_password' => ['nullable', 'string'],
        ], [
            'email.unique' => 'Another account already uses this email.',
            'phone.regex' => 'Enter a phone number, e.g. 0917 123 4567.',
        ]);
        $email = strtolower(trim($data['email']));
        if ($email !== strtolower($user->email) && ! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Type your current password to change the email you sign in with.']);
        }

        $user->update(['name' => trim($data['name']), 'email' => $email, 'phone' => isset($data['phone']) ? trim($data['phone']) : null]);

        return response()->json($this->details($user));
    }

    /**
     * A new password; every other device signed in with the old one is signed out. It also ends the
     * temporary password an accepted realty was mailed: this is how they choose their own at first sign-in.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ], [
            'password.confirmed' => "The two new passwords don't match.",
            'password.different' => 'The new password has to be different from the current one.',
        ]);
        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => "That's not your current password."]);
        }

        // must_change_password is never mass assignable; the server clears it here and nowhere else.
        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();

        return response()->json(['message' => 'Password changed. Other devices were signed out.']);
    }

    /** @return array<string, mixed> */
    private function details(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'is_superadmin' => $user->isSuperAdmin(),
            'realty' => $user->realty?->name,
            'member_since' => $user->created_at?->toDateString(),
        ];
    }
}
