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

        // Agents who applied wait for their realty's staff; the master password doesn't skip this.
        if ($user->status === User::STATUS_PENDING) {
            throw ValidationException::withMessages(['email' => 'Your account is waiting for approval from '.($user->realty?->name ?? 'your realty').'.']);
        }
        if ($user->status === User::STATUS_REJECTED) {
            throw ValidationException::withMessages(['email' => 'Your application to '.($user->realty?->name ?? 'this realty')." wasn't approved."]);
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

    /** Where a super admin can switch to: every active realty, as its admin or as an agent. */
    public function viewAsOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Super admins only.');

        return response()->json([
            'realties' => Realty::where('status', Realty::STATUS_ACTIVE)->orderBy('name')->get(['id', 'slug', 'name'])->map->only(['slug', 'name']),
        ]);
    }

    /**
     * Switch a super admin's view: role "realty" or "agent" in a realty gives a
     * preview token for that dashboard; role "admin" goes back to the platform.
     * Either way earlier previews end, so there's only ever one.
     */
    public function viewAs(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403, 'Super admins only.');
        $data = $request->validate([
            'role' => ['required', 'in:admin,realty,agent'],
            'realty' => ['required_unless:role,admin', 'nullable', 'string', 'max:60'],
        ]);

        $user->tokens()->where('name', 'like', User::VIEW_AS_TOKEN.'%')->delete();
        if ($data['role'] === User::ROLE_ADMIN) {
            $token = $user->createToken('admin-web', ['*'], now()->addHours(12));

            return response()->json(['token' => $token->plainTextToken, 'user' => $this->publicUser($user->fresh())]);
        }

        $realty = Realty::where('slug', $data['realty'])->where('status', Realty::STATUS_ACTIVE)->first();
        if (! $realty) {
            throw ValidationException::withMessages(['realty' => 'That realty is not active.']);
        }
        $token = $user->createToken(User::VIEW_AS_TOKEN."{$realty->id}:{$data['role']}", ['*'], now()->addHours(12));

        return response()->json(['token' => $token->plainTextToken, 'realty' => $realty->slug]);
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
            'is_superadmin' => $user->isSuperAdmin(),
            'status' => $user->status,
            'realty_id' => $user->realty_id,
            'realty' => $user->realty?->publicArray(),
        ];
    }
}
