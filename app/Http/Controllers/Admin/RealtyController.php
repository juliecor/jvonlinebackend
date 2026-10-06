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

    /** One realty in full: profile, people, projects and latest offers. */
    public function show(Realty $realty): JsonResponse
    {
        $realty->load(['latestInvitation', 'users' => fn ($q) => $q->orderBy('role')->orderBy('name')])
            ->loadCount(['users', 'agents', 'projects', 'offers']);
        $projects = $realty->projects()->withCount(['units', 'paymentPlans', 'offers'])->orderBy('name')->get();
        $offers = $realty->offers()->with(['project:id,name', 'unit:id,name,unit_type', 'agent:id,name'])->latest()->take(50)->get()->map(fn ($o) => [
            'id' => $o->id, 'code' => $o->code, 'status' => $o->status, 'buyer_name' => $o->buyer_name, 'price' => (float) $o->price, 'views' => $o->views,
            'created_at' => $o->created_at, 'project' => $o->project?->name, 'unit' => $o->unit?->name, 'agent' => $o->agent?->name, 'url' => \App\Models\Offer::url($o->code),
        ]);

        return response()->json([
            'realty' => $realty,
            'people' => $realty->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'joined_at' => $u->created_at]),
            'projects' => $projects,
            'offers' => $offers,
            'offer_views' => (int) $realty->offers()->sum('views'),
        ]);
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

        $url = null;
        $realty = DB::transaction(function () use ($data, $slug, &$url) {
            $realty = Realty::create([
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'status' => Realty::STATUS_INVITED,
                'invited_at' => now(),
            ]);
            $url = $this->sendInvite($realty, $data['email']);

            return $realty;
        });

        return response()->json($realty->load('latestInvitation')->loadCount('users')->toArray() + ['registration_url' => $url], 201);
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

        $url = DB::transaction(function () use ($realty, $email) {
            $realty->invitations()->whereNull('accepted_at')->delete();
            $realty->update(['email' => $email, 'invited_at' => now()]);

            return $this->sendInvite($realty, $email);
        });

        return response()->json($realty->fresh()->load('latestInvitation')->loadCount('users')->toArray() + ['registration_url' => $url]);
    }

    /** Emails the link and returns it, so the admin can also pass it on by hand. */
    private function sendInvite(Realty $realty, string $email): string
    {
        [$invitation, $token] = RealtyInvitation::issue($realty, $email);
        $url = RealtyInvitation::url($token);
        Mail::to($email)->send(new RealtyInvitationMail($realty, $url, $invitation->expires_at));

        return $url;
    }
}
