<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Realty;
use App\Models\SiteRow;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Load a site map saved by site-map:export into this server's project of the same web address: each row
 * is drawn the way the dashboard draws it, its lots getting their model, areas, price and tags. Rows
 * already on the map are skipped, so running it again changes nothing. New lots start available.
 */
class ImportSiteMap extends Command
{
    protected $signature = 'site-map:import
        {project : The project\'s web address, e.g. astana-davao}
        {--realty=johndorf : The developer\'s web address}
        {--file= : The saved map (default database/seeders/data/site-maps/{project}.json)}
        {--dry-run : Check the file and say what would happen, without saving anything}';

    protected $description = 'Draw a project\'s site map from a file saved by site-map:export';

    public function handle(): int
    {
        $project = Project::where('realty_id', Realty::where('slug', $this->option('realty'))->value('id'))->where('slug', $this->argument('project'))->first();
        if (! $project) {
            $this->error("No project {$this->argument('project')} for {$this->option('realty')}.");

            return self::FAILURE;
        }
        $file = $this->option('file') ?: database_path("seeders/data/site-maps/{$project->slug}.json");
        $map = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($map) || ! is_array($map['rows'] ?? null)) {
            $this->error("No site map file at {$file} (save one with site-map:export).");

            return self::FAILURE;
        }

        DB::beginTransaction();
        // The rows fit the plan they were drawn on: use that one when the project has it.
        $image = $map['image'] ?? null;
        if ($image && $image !== ($project->site_map_path ?? ($project->site_plan_paths[0] ?? null))) {
            if (in_array($image, $project->site_plan_paths ?? [], true)) {
                $project->update(['site_map_path' => $image]);
            } else {
                $this->warn("The lots were drawn on {$image}, which this project doesn't have. Check that they line up with its plan.");
            }
        }
        if ($project->siteMap() === null) {
            DB::rollBack();
            $this->error("{$project->name} has no site development plan. Upload it in the Website tab first.");

            return self::FAILURE;
        }

        $drawn = $lots = $skipped = 0;
        $problems = [];
        foreach ($map['rows'] as $i => $row) {
            $where = 'Row '.($i + 1).(isset($row['block']) ? " (Block {$row['block']})" : '');
            $check = Validator::make($row, SiteRow::newRowRules());
            if ($check->fails()) {
                $problems[] = "{$where}: {$check->errors()->first()}";

                continue;
            }
            $data = $check->validated();
            $numbers = array_map('strval', range($data['first_lot'], $data['first_lot'] + $data['lot_count'] - 1));
            $onMap = Unit::where('project_id', $project->id)->whereNotNull('site_row_id')->where('block', trim($data['block']))->whereIn('lot', $numbers)->count();
            if ($onMap === count($numbers)) {
                $skipped++;

                continue;
            }
            if ($onMap > 0) {
                $problems[] = "{$where}: some of lots {$data['first_lot']}–".end($numbers).' are already on the map. Draw the rest in the dashboard.';

                continue;
            }

            $own = [];
            foreach ($row['lots'] ?? [] as $lot) {
                $values = Validator::make($lot, SiteRow::lotRules() + ['index' => ['required', 'integer', 'min:0'], 'tags' => ['sometimes', 'array'], 'tags.*' => ['string', 'in:'.implode(',', array_keys(Unit::TAGS))]]);
                if ($values->fails()) {
                    $problems[] = "{$where}, lot ".($lot['lot'] ?? '?').": {$values->errors()->first()}";

                    continue;
                }
                $own[$values->validated()['index']] = $values->validated();
            }
            try {
                SiteRow::draw($project, $data, $own);
            } catch (ValidationException $e) {
                $problems[] = "{$where}: ".collect($e->errors())->flatten()->first();

                continue;
            }
            $drawn++;
            $lots += $data['lot_count'];
        }

        $this->option('dry-run') ? DB::rollBack() : DB::commit();
        $this->info(($this->option('dry-run') ? 'Dry run, nothing saved. Would draw' : 'Drew')." {$drawn} rows ({$lots} lots) on {$project->name}".($skipped ? "; {$skipped} rows were already on the map." : '.'));
        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
