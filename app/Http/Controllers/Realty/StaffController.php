<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Team > Staff: a realty's full admins make accounts for their staff, choose
 * the dashboard sections each one may use, set or reset their password, turn
 * accounts off and delete them. There's always at least one full admin left,
 * and nobody changes their own account here (that's My account).
 */
class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $staff = User::where('realty_id', $me->realty_id)->where('role', User::ROLE_REALTY)
            ->withCount('offers')->orderByRaw('permissions is not null')->orderBy('name')->get();

        return response()->json([
            'sections' => User::sectionsFor($me->realty),
            'staff' => $staff->map(fn (User $u) => $this->row($u, $me))->all(),
        ]);
    }

    /** A new staff account, with the password the admin gives them. */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{7,}$/'],
            'password' => ['required', 'string', Password::min(8)],
            'must_change_password' => ['boolean'],
        ] + $this->accessRules($me), [
            'email.unique' => 'Another account already uses this email.',
            'phone.regex' => 'Enter a phone number, e.g. 0917 123 4567.',
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'password' => $data['password'],
            'role' => User::ROLE_REALTY,
            'permissions' => $this->permissions($data, $me),
            'status' => User::STATUS_ACTIVE,
            'realty_id' => $me->realty_id,
        ]);
        $user->forceFill(['must_change_password' => (bool) ($data['must_change_password'] ?? true)])->save();

        return response()->json($this->row($user->loadCount('offers'), $me), 201);
    }

    /** What a staff member may use: everything (full admin) or chosen sections. */
    public function access(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $this->own($me, $user);
        $data = $request->validate($this->accessRules($me));
        $permissions = $this->permissions($data, $me);
        if ($permissions !== null) {
            $this->keepAFullAdmin($user);
        }
        $user->forceFill(['permissions' => $permissions])->save();

        return response()->json($this->row($user->loadCount('offers'), $me));
    }

    /** A new password from the admin; they're signed out everywhere and, by default, choose their own at the next sign-in. */
    public function password(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $this->own($me, $user);
        $data = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
            'must_change_password' => ['boolean'],
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => (bool) ($data['must_change_password'] ?? true)])->save();
            $user->tokens()->delete();
        });

        return response()->json($this->row($user->loadCount('offers'), $me));
    }

    /** Turn an account off (it can't sign in, and is signed out now) or back on. */
    public function status(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $this->own($me, $user);
        $data = $request->validate(['status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])]]);
        if ($data['status'] === User::STATUS_DISABLED) {
            $this->keepAFullAdmin($user);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['status' => $data['status']])->save();
            if ($data['status'] === User::STATUS_DISABLED) {
                $user->tokens()->delete();
            }
        });

        return response()->json($this->row($user->loadCount('offers'), $me));
    }

    /** Delete an account that never sent an offer; one that did is turned off instead, so its offers keep a name. */
    public function destroy(Request $request, User $user): Response
    {
        $this->own($request->user(), $user);
        $offers = $user->offers()->count();
        abort_if($offers > 0, 409, "{$user->name} sent {$offers} offer".($offers === 1 ? '' : 's').'. Turn the account off instead, so those offers keep their name.');
        $this->keepAFullAdmin($user);

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    /**
     * "full" or the list of sections, from the realty's own sections. Approvals
     * work from the offers list, so they come with Offers.
     *
     * @return array<string, array<int, mixed>>
     */
    private function accessRules(User $me): array
    {
        return [
            'access' => ['required', 'in:full,sections'],
            'sections' => ['array', 'required_if:access,sections'],
            'sections.*' => [Rule::in(User::sectionsFor($me->realty))],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function permissions(array $data, User $me): ?array
    {
        if ($data['access'] === 'full') {
            return null;
        }
        $sections = array_values(array_unique($data['sections'] ?? []));
        if (in_array('approvals', $sections, true) && ! in_array('offers', $sections, true)) {
            $sections[] = 'offers';
        }

        return array_values(array_intersect(User::sectionsFor($me->realty), $sections));
    }

    /** Staff of this realty only; your own account is changed on My account. */
    private function own(User $me, User $user): void
    {
        abort_unless($user->role === User::ROLE_REALTY && $user->realty_id === $me->realty_id, 404);
        abort_if($user->id === $me->id, 422, 'Change your own account on My account.');
    }

    /** A realty always keeps at least one active full admin. */
    private function keepAFullAdmin(User $user): void
    {
        if (! $user->isFullAdmin() || ! $user->isActive()) {
            return;
        }
        $others = User::where('realty_id', $user->realty_id)->where('role', User::ROLE_REALTY)->whereNull('permissions')
            ->where('status', User::STATUS_ACTIVE)->whereKeyNot($user->id)->exists();
        abort_unless($others, 422, "{$user->name} is the only full admin left. Make someone else a full admin first.");
    }

    /** @return array<string, mixed> */
    private function row(User $u, User $me): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'photo' => $u->photoUrl(),
            'full_admin' => $u->permissions === null,
            'sections' => $u->permissions ?? User::sectionsFor($me->realty),
            'status' => $u->status,
            'must_change_password' => (bool) $u->must_change_password,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'offers' => (int) ($u->offers_count ?? 0),
            'is_you' => $u->id === $me->id,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
