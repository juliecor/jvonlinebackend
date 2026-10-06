<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\UnitType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The models shown on a project's public page. Staff only. */
class UnitTypeController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
        $data = $this->validated($request);
        $paths = [];
        foreach ($request->file('images', []) as $file) {
            $paths[] = $file->store("projects/{$project->id}/models", config('filesystems.uploads'));
        }
        $ut = UnitType::create([
            'realty_id' => $project->realty_id,
            'project_id' => $project->id,
            'name' => $data['name'],
            'specs' => $this->specs($data),
            'image_paths' => $paths,
            'sort' => ($project->unitTypes()->max('sort') ?? 0) + 1,
        ]);

        return response()->json($ut, 201);
    }

    public function update(Request $request, UnitType $unitType): JsonResponse
    {
        abort_unless($unitType->realty_id === $request->user()->realty_id, 404);
        $data = $this->validated($request);
        $paths = $unitType->image_paths ?? [];
        foreach ($request->file('images', []) as $file) {
            $paths[] = $file->store("projects/{$unitType->project_id}/models", config('filesystems.uploads'));
        }
        // Remove images the form unticked (sent as their URLs).
        foreach ((array) $request->input('remove', []) as $url) {
            $paths = array_values(array_filter($paths, function ($p) use ($url) {
                if (Project::publicUrl($p) === $url) {
                    ProjectPageController::deleteUpload($p);

                    return false;
                }

                return true;
            }));
        }
        $unitType->update(['name' => $data['name'], 'specs' => $this->specs($data), 'image_paths' => $paths]);

        return response()->json($unitType->fresh());
    }

    public function destroy(Request $request, UnitType $unitType): JsonResponse
    {
        abort_unless($unitType->realty_id === $request->user()->realty_id, 404);
        foreach ($unitType->image_paths ?? [] as $p) {
            ProjectPageController::deleteUpload($p);
        }
        $unitType->delete();

        return response()->json(['deleted' => $unitType->id]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'usable_floor_area' => ['nullable', 'string', 'max:30'],
            'typical_floor_area' => ['nullable', 'string', 'max:30'],
            'bedrooms' => ['nullable', 'string', 'max:30'],
            'baths' => ['nullable', 'string', 'max:30'],
            'floors' => ['nullable', 'string', 'max:30'],
            'parking' => ['nullable', 'string', 'max:30'],
            'images' => ['nullable', 'array', 'max:12'],
            'images.*' => ['image', 'max:8192'],
            'remove' => ['nullable', 'array'],
        ]);
    }

    private function specs(array $data): array
    {
        $out = [];
        foreach (UnitType::SPEC_KEYS as $k) {
            $out[$k] = isset($data[$k]) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
        }

        return $out;
    }
}
