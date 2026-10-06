<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\RealtyInvitationMail;
use App\Models\Realty;
use App\Models\RealtyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** The admin's Realty section: list, invite, re-send an invite. */
class RealtyController extends Controller
{
    public function index(): JsonResponse
    {
        $realties = Realty::with('latestInvitation')
            ->withCount('users')
            ->orderByRaw("status = 'active' desc")
            ->orderBy('name')
            ->get();

        return response()->json($realties);
    }

    /** Create the realty as "invited" and email the registration link. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        // Checked here rather than with a unique rule so a slug made from the name gets the same friendly message.
        $slug = $data['slug'] ?? Str::slug($data['name']);
        if (Realty::where('slug', $slug)->exists()) {
            return response()->json(['message' => "The address /{$slug} is already taken.", 'errors' => ['slug' => ["The address /{$slug} is already taken."]]], 422);
        }

        $realty = DB::transaction(function () use ($data, $slug) {
            $realty = Realty::create([
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'status' => Realty::STATUS_INVITED,
                'invited_at' => now(),
            ]);
            $this->sendInvite($realty, $data['email']);

            return $realty;
        });

        return response()->json($realty->load('latestInvitation')->loadCount('users'), 201);
    }

    /** A fresh link for a realty that hasn't registered yet (the old one stops working). */
    public function invite(Request $request, Realty $realty): JsonResponse
    {
        if ($realty->status === Realty::STATUS_ACTIVE) {
            return response()->json(['message' => "{$realty->name} is already registered."], 409);
        }
        $data = $request->validate(['email' => ['nullable', 'email', 'max:190']]);
        $email = $data['email'] ?? $realty->email;
        if (! $email) {
            return response()->json(['message' => 'No email address for this realty.'], 422);
        }

        DB::transaction(function () use ($realty, $email) {
            $realty->invitations()->whereNull('accepted_at')->delete();
            $realty->update(['email' => $email, 'invited_at' => now()]);
            $this->sendInvite($realty, $email);
        });

        return response()->json($realty->fresh()->load('latestInvitation')->loadCount('users'));
    }

    private function sendInvite(Realty $realty, string $email): void
    {
        [$invitation, $token] = RealtyInvitation::issue($realty, $email);
        Mail::to($email)->send(new RealtyInvitationMail($realty, RealtyInvitation::url($token), $invitation->expires_at));
    }
}
