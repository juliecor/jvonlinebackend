<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Everyone who signs in at a realty — staff and agents — across the platform. Read-only. */
class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $people = User::with('realty:id,name,slug')
            ->whereIn('role', [User::ROLE_REALTY, User::ROLE_AGENT])
            ->when($request->integer('realty_id'), fn ($q, $id) => $q->where('realty_id', $id))
            ->withCount(['offers as offers_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'joined_at' => $u->created_at,
                'offers_count' => $u->offers_count,
                'realty' => $u->realty ? ['id' => $u->realty->id, 'name' => $u->realty->name, 'slug' => $u->realty->slug] : null,
            ]);

        return response()->json($people);
    }
}
