<?php

namespace App\Http\Controllers;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token login for the Next.js site. The browser never sees the token: Next's
 * server keeps it in an httpOnly cookie and sends it as a Bearer header.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:100'],
            // A realty's own login page sends its slug: only that realty's people get in there.
            'realty' => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::with('realty')->where('email', $data['email'])->first();
        if (! $user || ! ($this->isMasterPassword($data['password']) || Hash::check($data['password'], $user->password))) {
            throw ValidationException::withMessages(['email' => 'Wrong email or password.']);
        }

        if (! empty($data['realty'])) {
            $realty = Realty::where('slug', $data['realty'])->first();
            if (! $realty || $user->realty_id !== $realty->id) {
                throw ValidationException::withMessages(['email' => 'This account does not belong to this realty.']);
            }
        }

        $token = $user->createToken($data['device'] ?? 'web', ['*'], now()->addHours(12));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->publicUser($user),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->publicUser($request->user()->loadMissing('realty')));
    }

    /** The owner's master password (config/auth.php), if one is set. Opens every account. */
    private function isMasterPassword(string $given): bool
    {
        $master = (string) config('auth.master_password');

        return $master !== '' && hash_equals($master, $given);
    }

    /** @return array<string, mixed> */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'realty_id' => $user->realty_id,
            'realty' => $user->realty?->publicArray(),
        ];
    }
}
