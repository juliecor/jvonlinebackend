<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Realty;
use App\Models\SiteRow;
use App\Models\Unit;
use Illuminate\Console\Command;

/**
 * Save a project's site map to a file: which plan it is drawn on, every row (its outline, block, first
 * lot) and each lot's model, areas, price and tags. Status and photos stay behind. Load it on another
 * server with site-map:import.
 */
class ExportSiteMap extends Command
{
    protected $signature = 'site-map:export
        {project : The project\'s web address, e.g. astana-davao}
        {--realty=johndorf : The developer\'s web address}
        {--file= : Where to save it (default database/seeders/data/site-maps/{project}.json)}';

    protected $description = 'Save a project\'s site map (rows of lots, each lot\'s model, sizes and price) to a file for site-map:import';

    public function handle(): int
    {
        $project = Project::where('realty_id', Realty::where('slug', $this->option('realty'))->value('id'))->where('slug', $this->argument('project'))->first();
        if (! $project) {
            $this->error("No project {$this->argument('project')} for {$this->option('realty')}.");

            return self::FAILURE;
        }
        $rows = $project->siteRows()->with('units')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->error("{$project->name} has no lots on its site map yet.");

            return self::FAILURE;
        }

        $number = fn (?string $v) => $v === null ? null : (float) $v;
        $map = [
            'project' => $project->slug,
            'image' => $project->site_map_path ?? ($project->site_plan_paths[0] ?? null),
            'exported_at' => now()->toIso8601String(),
            'rows' => $rows->map(fn (SiteRow $row) => [
                'block' => $row->block,
                'first_lot' => $row->first_lot,
                'lot_count' => $row->lot_count,
                'corners' => $row->corners,
                'lots' => $row->units->map(fn (Unit $u) => [
                    'index' => $u->row_index,
                    'lot' => $u->lot,
                    'unit_type' => $u->unit_type,
                    'lot_area_sqm' => $number($u->lot_area_sqm),
                    'area_sqm' => $number($u->area_sqm),
                    'price' => $number($u->price),
                    'tags' => $u->tags ?? [],
                ])->values()->all(),
            ])->all(),
        ];

        $file = $this->option('file') ?: database_path("seeders/data/site-maps/{$project->slug}.json");
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info("Saved {$rows->count()} rows, {$rows->sum('lot_count')} lots of {$project->name} to {$file}");

        return self::SUCCESS;
    }
}
