<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The developer's projects. Its own admins create and edit; its agents and any
 * accredited broker's people may read (to pick a unit for an offer).
 */
class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $projects = $user->realty->inventoryProjects()
            ->withCount(['units', 'paymentPlans', 'offers' => $this->offersTheyMaySee($user), 'units as ready_units_count' => fn ($q) => $q->where('status', 'available')->whereNotNull('price')])
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->readable($request, $project);
        $user = $request->user();

        $project->load(['units.statusOffer:id,realty_id,broker_realty_id,code,buyer_name,agent_id', 'units.statusOffer.agent:id,name', 'units.statusBy:id,name', 'paymentPlans', 'unitTypes', 'updates'])
            ->loadCount(['offers' => $this->offersTheyMaySee($user)]);
        // Who each reserved or sold unit went to (buyers' names are for the developer's
        // admins), and a picture for each row: its house model's, else the project's.
        // A broker sees that a unit is taken, but only its own firm's sale behind it,
        // and never Johndorf's internal notes on a unit.
        $staff = $user->isDeveloperStaff();
        $broker = $user->isBrokerMember();
        $project->units->each(function (Unit $unit) use ($staff, $broker, $user, $project) {
            $unit->setAttribute('status_detail', $unit->statusDetail($staff, ! $broker || (bool) $unit->statusOffer?->isVisibleTo($user)));
            $unit->setAttribute('photo', $unit->photo($project->unitTypes, $project));
            $unit->unsetRelation('statusOffer')->unsetRelation('statusBy');
            if ($broker) {
                $unit->makeHidden('notes');
            }
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

    /**
     * A project with offers can't be deleted: they would go with it, buyers' files and all. Archive it instead.
     * Without offers, its units, plans, models and updates go too, and so do their uploaded pictures.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $offers = $project->offers()->count();
        if ($offers > 0) {
            return response()->json(['message' => "This project has {$offers} offer".($offers === 1 ? '' : 's')." and can't be deleted. Archive it instead."], 409);
        }

        $project->loadMissing(['unitTypes', 'updates']);
        $files = array_merge(
            [$project->cover_path],
            $project->hero_paths ?? [],
            $project->site_plan_paths ?? [],
            $project->units()->pluck('floor_plan_path')->all(),
            $project->unitTypes->flatMap(fn ($t) => $t->image_paths ?? [])->all(),
            $project->updates->flatMap(fn ($u) => $u->photo_paths ?? [])->all(),
        );
        DB::transaction(fn () => $project->delete());
        foreach ($files as $path) {
            ProjectPageController::deleteUpload($path);
        }

        return response()->json(['deleted' => $project->id]);
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

    /** Editing: only the realty that owns the project (the route guard already keeps brokers out). */
    private function own(Request $request, Project $project): void
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
    }

    /** Reading: the developer's inventory, which a broker works from too. */
    private function readable(Request $request, Project $project): void
    {
        abort_unless($project->realty_id === $request->user()->realty->inventoryId(), 404);
    }

    /** A project's offer count: everything for the developer's team, only their firm's for a broker. */
    private function offersTheyMaySee(User $user): Closure
    {
        return fn ($query) => $user->realty->isBroker() ? $query->visibleTo($user) : $query;
    }
}
