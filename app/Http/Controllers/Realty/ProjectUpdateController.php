<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Construction updates, one row per month, photos appended. Staff only. */
class ProjectUpdateController extends Controller
{
    /** Add photos to a month (creating the month if needed). */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'label' => ['nullable', 'string', 'max:60'],
            'photos' => ['required', 'array', 'min:1', 'max:40'],
            'photos.*' => ['image', 'max:8192'],
        ]);
        $update = ProjectUpdate::firstOrCreate(
            ['project_id' => $project->id, 'month' => $data['month']],
            ['realty_id' => $project->realty_id, 'label' => $data['label'] ?? null, 'photo_paths' => []],
        );
        $paths = $update->photo_paths ?? [];
        foreach ($request->file('photos') as $file) {
            $paths[] = $file->store("projects/{$project->id}/updates/{$data['month']}", config('filesystems.uploads'));
        }
        $update->update(['photo_paths' => $paths, 'label' => $data['label'] ?? $update->label]);

        return response()->json($update->fresh(), 201);
    }

    /** Remove one photo (by URL) from a month; the month goes when its last photo does. */
    public function removePhoto(Request $request, ProjectUpdate $update): JsonResponse
    {
        abort_unless($update->realty_id === $request->user()->realty_id, 404);
        $data = $request->validate(['url' => ['required', 'string']]);
        $kept = [];
        foreach ($update->photo_paths ?? [] as $p) {
            if (Project::publicUrl($p) === $data['url']) {
                ProjectPageController::deleteUpload($p);
            } else {
                $kept[] = $p;
            }
        }
        if (! $kept) {
            $update->delete();

            return response()->json(['deleted' => $update->id]);
        }
        $update->update(['photo_paths' => $kept]);

        return response()->json($update->fresh());
    }

    public function destroy(Request $request, ProjectUpdate $update): JsonResponse
    {
        abort_unless($update->realty_id === $request->user()->realty_id, 404);
        foreach ($update->photo_paths ?? [] as $p) {
            ProjectPageController::deleteUpload($p);
        }
        $update->delete();

        return response()->json(['deleted' => $update->id]);
    }
}
