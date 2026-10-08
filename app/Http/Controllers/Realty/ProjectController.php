<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A realty's projects. Staff create and edit; agents may read (to pick a unit for an offer). */
class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->realty->projects()
            ->withCount(['units', 'paymentPlans', 'offers', 'units as ready_units_count' => fn ($q) => $q->where('status', 'available')->whereNotNull('price')])
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);

        $project->load(['units.statusOffer:id,code,buyer_name,agent_id', 'units.statusOffer.agent:id,name', 'units.statusBy:id,name', 'paymentPlans', 'unitTypes', 'updates'])->loadCount('offers');
        // Who each reserved or sold unit went to (buyers' names are for the realty's
        // admins), and a picture for each row: its house model's, else the project's.
        $staff = $request->user()->role === User::ROLE_REALTY;
        $project->units->each(function (Unit $unit) use ($staff, $project) {
            $unit->setAttribute('status_detail', $unit->statusDetail($staff));
            $unit->setAttribute('photo', $unit->photo($project->unitTypes, $project));
            $unit->unsetRelation('statusOffer')->unsetRelation('statusBy');
        });

        return response()->json($project);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['realty_id'] = $request->user()->realty_id;
        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('projects', config('filesystems.uploads'));
        }
        $project = Project::create($data);

        return response()->json($project->load(['units', 'paymentPlans'])->loadCount('offers'), 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $this->validated($request);
        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('projects', config('filesystems.uploads'));
        }
        $project->update($data);

        return response()->json($project->fresh()->load(['units', 'paymentPlans'])->loadCount('offers'));
    }

    /**
     * The status bar at the top of a project: open for offers or archived, its
     * stage, and whether it shows on the public site. Send only what changes.
     */
    public function status(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,archived'],
            'stage' => ['sometimes', 'nullable', Rule::in(Project::STAGES)],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        if (($data['is_public'] ?? false) && ! $project->slug) {
            $slug = Str::slug($project->name);
            $taken = Project::where('realty_id', $project->realty_id)->where('slug', $slug)->whereKeyNot($project->id)->exists();
            $data['slug'] = $taken ? "{$slug}-{$project->id}" : $slug;
        }
        $project->update($data);

        return response()->json($project->only(['id', 'status', 'stage', 'is_public', 'slug']));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'fee_notes' => ['nullable', 'string', 'max:2000'],
            'completion_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,archived'],
            'cover' => ['nullable', 'image', 'max:6144'],
        ]);
    }

    private function own(Request $request, Project $project): void
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
    }
}
