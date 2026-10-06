<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The public page of a project: settings, hero photos, site plans, amenities. Staff only. */
class ProjectPageController extends Controller
{
    /** Slug, region, stage, published flag, amenities (one per line or an array). */
    public function settings(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('projects', 'slug')->where('realty_id', $project->realty_id)->ignore($project->id)],
            'region' => ['nullable', 'string', 'max:60'],
            'stage' => ['nullable', 'string', 'max:40'],
            'is_public' => ['nullable', 'boolean'],
            'official_url' => ['nullable', 'url', 'max:255'],
            'amenities' => ['nullable'],
        ]);
        $amen = $data['amenities'] ?? null;
        if (is_string($amen)) {
            $amen = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $amen))));
        }
        $slug = $data['slug'] ?? $project->slug ?? Str::slug($project->name);
        $project->update([
            'slug' => $slug,
            'region' => $data['region'] ?? $project->region,
            'stage' => $data['stage'] ?? $project->stage,
            'is_public' => (bool) ($data['is_public'] ?? $project->is_public),
            'official_url' => array_key_exists('official_url', $data) ? $data['official_url'] : $project->official_url,
            'amenities' => $amen ?? $project->amenities,
        ]);

        return response()->json($project->fresh()->publicArray());
    }

    /** Add hero photos or site plans: field `kind` = hero|plan, files in `files[]`. */
    public function addMedia(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $request->validate([
            'kind' => ['required', 'in:hero,plan'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['image', 'max:8192'],
        ]);
        $col = $data['kind'] === 'hero' ? 'hero_paths' : 'site_plan_paths';
        $paths = $project->{$col} ?? [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("projects/{$project->id}/".($data['kind'] === 'hero' ? 'hero' : 'plans'), config('filesystems.uploads'));
        }
        $project->update([$col => $paths]);

        return response()->json($project->fresh()->publicArray());
    }

    /** Remove one hero photo or site plan by its URL (as the dashboard shows it). */
    public function removeMedia(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $request->validate(['kind' => ['required', 'in:hero,plan'], 'url' => ['required', 'string']]);
        $col = $data['kind'] === 'hero' ? 'hero_paths' : 'site_plan_paths';
        $kept = [];
        foreach ($project->{$col} ?? [] as $p) {
            if (Project::publicUrl($p) === $data['url']) {
                $this->deleteUpload($p);
            } else {
                $kept[] = $p;
            }
        }
        $project->update([$col => $kept]);

        return response()->json($project->fresh()->publicArray());
    }

    /** Only files we uploaded ourselves are deleted; seeded S3 URLs and frontend images are left alone. */
    public static function deleteUpload(?string $path): void
    {
        if ($path && ! str_starts_with($path, '/') && ! str_starts_with($path, 'http')) {
            Storage::disk(config('filesystems.uploads'))->delete($path);
        }
    }

    private function own(Request $request, Project $project): void
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
    }
}
