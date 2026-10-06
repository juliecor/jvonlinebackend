<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A realty's projects. Staff create and edit; agents may read (to pick a unit for an offer). */
class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->realty->projects()
            ->withCount(['units', 'paymentPlans', 'offers'])
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);

        return response()->json($project->load(['units', 'paymentPlans'])->loadCount('offers'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['realty_id'] = $request->user()->realty_id;
        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('projects', 'public');
        }
        $project = Project::create($data);

        return response()->json($project->load(['units', 'paymentPlans'])->loadCount('offers'), 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $this->validated($request);
        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('projects', 'public');
        }
        $project->update($data);

        return response()->json($project->fresh()->load(['units', 'paymentPlans'])->loadCount('offers'));
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
