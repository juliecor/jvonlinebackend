<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SiteRow;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The developer's admins draw the project's lots on its site plan: pick the plan image, draw a row of
 * lots (four corners, block, first lot, how many), adjust it, give all its lots a model, area or price,
 * or take it off the map. Each lot is a unit; lots that already exist by name are linked, not copied.
 */
class SiteMapController extends Controller
{
    /** Which site plan image the map is drawn on. */
    public function image(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        $data = $request->validate(['image' => ['required', 'string', 'max:2048']]);
        $path = collect($project->site_plan_paths ?? [])->first(fn (string $p) => $p === $data['image'] || Project::publicUrl($p) === $data['image']);
        abort_if($path === null, 422, 'Choose one of the project\'s site plans. Upload a new one in the Website tab.');
        $project->update(['site_map_path' => $path]);

        return response()->json($project->siteMap());
    }

    /** A new row of lots: the row, and a unit for every lot in it (or the existing unit of that name). */
    public function storeRow(Request $request, Project $project): JsonResponse
    {
        $this->own($request, $project);
        abort_if($project->siteMap() === null, 422, 'Upload the site development plan first, in the Website tab.');
        $data = $request->validate($this->rowRules() + [
            'block' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'first_lot' => ['required', 'integer', 'min:1', 'max:99999'],
            'lot_count' => ['required', 'integer', 'min:1', 'max:'.SiteRow::MAX_LOTS],
        ] + $this->lotDefaults(), [
            'block.regex' => 'The block can only have letters, numbers, spaces and dashes.',
        ]);
        $block = trim($data['block']);
        $numbers = range($data['first_lot'], $data['first_lot'] + $data['lot_count'] - 1);

        $taken = Unit::where('project_id', $project->id)->whereNotNull('site_row_id')->where('block', $block)->whereIn('lot', array_map('strval', $numbers))->pluck('lot')->sortBy(fn ($l) => (int) $l)->values();
        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['first_lot' => "Block {$block} lot".($taken->count() === 1 ? ' ' : 's ').$taken->join(', ').($taken->count() === 1 ? ' is' : ' are').' already on the map.']);
        }

        DB::transaction(function () use ($project, $data, $block, $numbers) {
            $row = SiteRow::create(['realty_id' => $project->realty_id, 'project_id' => $project->id, 'block' => $block, 'corners' => $data['corners'], 'first_lot' => $data['first_lot'], 'lot_count' => $data['lot_count']]);
            foreach ($numbers as $i => $n) {
                $name = SiteRow::lotName($block, $n);
                $unit = Unit::where('project_id', $project->id)->whereNull('site_row_id')
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('block', $block)->where('lot', (string) $n))->orWhere('name', $name))
                    ->first();
                $place = ['block' => $block, 'lot' => (string) $n, 'site_row_id' => $row->id, 'row_index' => $i];
                if ($unit) {
                    // Keep what the unit already says; fill in only what it is missing.
                    $unit->update($place + array_filter($this->defaultsFrom($data), fn ($v, $k) => $unit->{$k} === null && $v !== null, ARRAY_FILTER_USE_BOTH));
                } else {
                    Unit::create($place + $this->defaultsFrom($data) + ['realty_id' => $project->realty_id, 'project_id' => $project->id, 'name' => $name, 'category' => $data['category'] ?? 'Residential', 'status' => 'available']);
                }
            }
        });

        return response()->json($project->fresh()->siteMap(), 201);
    }

    /** Move a row's corners (a better fit on the drawing). */
    public function updateRow(Request $request, SiteRow $row): JsonResponse
    {
        $project = $this->ownRow($request, $row);
        $row->update($request->validate($this->rowRules()));

        return response()->json($project->siteMap());
    }

    /** Give every lot in the row the same model, areas, price or category (only the fields sent). */
    public function applyToRow(Request $request, SiteRow $row): JsonResponse
    {
        $project = $this->ownRow($request, $row);
        $data = $request->validate($this->lotDefaults());
        $values = array_intersect_key($data, array_flip(['unit_type', 'lot_area_sqm', 'area_sqm', 'price', 'category']));
        abort_if($values === [], 422, 'Choose at least one thing to change.');
        $row->units()->update($values);

        return response()->json($project->siteMap());
    }

    /** Take a row off the map: its lots go, except those with offers, which stay as units off the map. */
    public function destroyRow(Request $request, SiteRow $row): JsonResponse
    {
        $project = $this->ownRow($request, $row);
        $kept = 0;
        $photos = $row->units->flatMap(fn (Unit $u) => $u->photo_paths ?? [])->all();
        DB::transaction(function () use ($row, &$kept) {
            foreach ($row->units()->withCount('offers')->get() as $unit) {
                if ($unit->offers_count > 0) {
                    $unit->update(['site_row_id' => null, 'row_index' => null]);
                    $kept++;

                    continue;
                }
                if ($unit->floor_plan_path && ! str_starts_with($unit->floor_plan_path, '/') && ! str_starts_with($unit->floor_plan_path, 'http')) {
                    Storage::disk(config('filesystems.uploads'))->delete($unit->floor_plan_path);
                }
                $unit->delete();
            }
            $row->delete();
        });
        Unit::forgetPhotos($project->id, $photos);

        return response()->json(['kept' => $kept, 'site_map' => $project->siteMap()]);
    }

    /** Photos of one lot (the actual house or lot). */
    public function addLotPhotos(Request $request, Unit $unit): JsonResponse
    {
        abort_unless($unit->realty_id === $request->user()->realty_id, 404);
        $files = $this->photoFiles($request);
        $paths = $unit->photo_paths ?? [];
        abort_if(count($paths) + count($files) > Unit::MAX_PHOTOS, 422, 'A lot keeps up to '.Unit::MAX_PHOTOS.' photos. Remove some first.');
        foreach ($files as $file) {
            $paths[] = $file->store("projects/{$unit->project_id}/lots", config('filesystems.uploads'));
        }
        $unit->update(['photo_paths' => $paths]);

        return response()->json(['photos' => $unit->photo_urls]);
    }

    /** Take one photo off a lot (by its URL); the file goes once no lot uses it. */
    public function removeLotPhoto(Request $request, Unit $unit): JsonResponse
    {
        abort_unless($unit->realty_id === $request->user()->realty_id, 404);
        $url = $request->validate(['url' => ['required', 'string']])['url'];
        $gone = array_values(array_filter($unit->photo_paths ?? [], fn ($p) => Unit::publicUrl($p) === $url));
        $unit->update(['photo_paths' => array_values(array_diff($unit->photo_paths ?? [], $gone))]);
        Unit::forgetPhotos($unit->project_id, $gone);

        return response()->json(['photos' => $unit->photo_urls]);
    }

    /** The same photos on every lot of a row (each file stored once). Lots that are full are skipped. */
    public function addRowPhotos(Request $request, SiteRow $row): JsonResponse
    {
        $project = $this->ownRow($request, $row);
        $files = $this->photoFiles($request);
        $paths = array_map(fn ($f) => $f->store("projects/{$project->id}/lots", config('filesystems.uploads')), $files);
        $skipped = 0;
        foreach ($row->units as $unit) {
            $mine = $unit->photo_paths ?? [];
            if (count($mine) + count($paths) > Unit::MAX_PHOTOS) {
                $skipped++;

                continue;
            }
            $unit->update(['photo_paths' => [...$mine, ...$paths]]);
        }
        Unit::forgetPhotos($project->id, $paths);

        return response()->json(['skipped' => $skipped, 'site_map' => $project->siteMap()]);
    }

    /** @return array<int, UploadedFile> */
    private function photoFiles(Request $request): array
    {
        $request->validate(['photos' => ['required', 'array', 'min:1', 'max:'.Unit::MAX_PHOTOS], 'photos.*' => ['image', 'max:8192']], [
            'photos.*.image' => 'Upload photos (JPG, PNG or WebP).',
            'photos.*.max' => 'Each photo can be up to 8 MB.',
        ]);

        return $request->file('photos');
    }

    /** @return array<string, array<int, mixed>> */
    private function rowRules(): array
    {
        return [
            'corners' => ['required', 'array', 'size:4'],
            'corners.*' => ['required', 'array', 'size:2'],
            'corners.*.*' => ['required', 'numeric', 'between:-0.05,1.05'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function lotDefaults(): array
    {
        return [
            'unit_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lot_area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function defaultsFrom(array $data): array
    {
        return ['unit_type' => $data['unit_type'] ?? null, 'lot_area_sqm' => $data['lot_area_sqm'] ?? null, 'area_sqm' => $data['area_sqm'] ?? null, 'price' => $data['price'] ?? null];
    }

    private function own(Request $request, Project $project): void
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
    }

    private function ownRow(Request $request, SiteRow $row): Project
    {
        abort_unless($row->realty_id === $request->user()->realty_id, 404);

        return $row->project;
    }
}
