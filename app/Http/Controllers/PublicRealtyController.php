<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Realty;
use Illuminate\Http\JsonResponse;

/** What anyone may see: the realties that are live (home page) and one realty's name + logo (its login page). */
class PublicRealtyController extends Controller
{
    public function index(): JsonResponse
    {
        // The platform page lists developers; the realties accredited under one have their own login, not a page here.
        $realties = Realty::where('status', Realty::STATUS_ACTIVE)->where('kind', Realty::KIND_DEVELOPER)
            ->orderBy('registered_at')
            ->get()
            ->map(fn (Realty $r) => $r->publicArray() + ['registered_at' => $r->registered_at]);

        return response()->json($realties);
    }

    /** The realty's published project pages, lightest form (for /projects and the landing). */
    public function projects(string $slug): JsonResponse
    {
        $realty = Realty::where('slug', $slug)->where('status', Realty::STATUS_ACTIVE)->firstOrFail();
        $projects = $realty->projects()->where('is_public', true)->with(['unitTypes', 'updates'])->orderBy('name')->get()
            ->map(fn (Project $p) => [
                'slug' => $p->slug, 'name' => $p->name, 'location' => $p->location, 'region' => $p->region, 'stage' => $p->stage,
                'hero' => $p->hero_urls ?: array_values(array_filter([$p->cover_url])),
                'unit_types' => $p->unitTypes->pluck('name')->values(),
                'amenities_count' => count($p->amenities ?? []),
                'total_photos' => $p->updates->sum(fn ($u) => count($u->photos)),
            ]);

        return response()->json($projects);
    }

    public function project(string $slug, string $projectSlug): JsonResponse
    {
        $realty = Realty::where('slug', $slug)->where('status', Realty::STATUS_ACTIVE)->firstOrFail();
        $project = $realty->projects()->where('is_public', true)->where('slug', $projectSlug)->firstOrFail();

        return response()->json($project->publicArray());
    }

    public function show(string $slug): JsonResponse
    {
        $realty = Realty::where('slug', $slug)->where('status', Realty::STATUS_ACTIVE)->firstOrFail();

        return response()->json($realty->publicArray());
    }
}
