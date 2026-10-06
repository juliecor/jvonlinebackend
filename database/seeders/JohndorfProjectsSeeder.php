<?php

namespace Database\Seeders;

use App\Models\PaymentPlan;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Johndorf's projects, from data/johndorf-projects.json: house models and
 * amenities read off Johndorf's own project pages, prices from published
 * listings and press (each unit's notes say where). Safe to run again —
 * it updates by project name and house model, and never touches units a
 * staff member added by hand.
 */
class JohndorfProjectsSeeder extends Seeder
{
    public function run(): void
    {
        $realty = Realty::where('slug', 'johndorf')->first();
        if (! $realty) {
            $this->command->error('Run DatabaseSeeder first: the Johndorf realty is missing.');

            return;
        }

        $rows = json_decode(file_get_contents(__DIR__.'/data/johndorf-projects.json'), true);
        foreach ($rows as $row) {
            $project = Project::updateOrCreate(
                ['realty_id' => $realty->id, 'name' => $row['name']],
                [
                    'location' => $row['location'],
                    'lat' => $row['lat'] ?? null,
                    'lng' => $row['lng'] ?? null,
                    'description' => $row['description'],
                    'cover_path' => $row['cover_path'],
                    'fee_notes' => $row['fee_notes'],
                    'status' => $row['status'],
                ],
            );
            foreach ($row['units'] as $u) {
                Unit::updateOrCreate(
                    ['project_id' => $project->id, 'name' => $u['name']],
                    [
                        'realty_id' => $realty->id,
                        'unit_type' => $u['unit_type'],
                        'category' => $u['category'],
                        'floor' => $u['floor'],
                        'area_sqm' => $u['area_sqm'],
                        'price' => $u['price'],
                        'status' => $u['status'],
                        'notes' => $u['notes'],
                    ],
                );
            }

            // One plan everyone understands; Johndorf's published Montierra scheme on Montierra.
            PaymentPlan::updateOrCreate(
                ['project_id' => $project->id, 'name' => 'Spot cash within 30 days'],
                ['realty_id' => $realty->id, 'milestones' => [['label' => 'Full payment', 'percent' => 100, 'days' => 30]]],
            );
            if ($row['slug'] === 'montierra') {
                // lionunion.com listing: ₱15,000 reservation, ₱185,000 equity over 24 months, balance financed — on ₱2.8M.
                PaymentPlan::updateOrCreate(
                    ['project_id' => $project->id, 'name' => 'Reservation, 24-month equity, balance via Pag-IBIG or bank'],
                    ['realty_id' => $realty->id, 'milestones' => [
                        ['label' => 'Reservation fee', 'percent' => 0.54, 'days' => 0],
                        ['label' => 'Equity, spread over 24 months', 'percent' => 6.61, 'days' => 730],
                        ['label' => 'Balance through Pag-IBIG or bank financing', 'percent' => 92.85, 'days' => null],
                    ]],
                );
            }
            $this->command->info("  {$row['name']}: ".count($row['units']).' model(s)');
        }

        $this->publicPages($realty);
    }

    /**
     * The public project pages, from data/johndorf-public.json (Johndorf's own
     * pages, images already on S3). Matched by project name; unit types by name,
     * update months by month — so a re-run refreshes rather than duplicates.
     */
    private function publicPages(Realty $realty): void
    {
        $pages = json_decode(file_get_contents(__DIR__.'/data/johndorf-public.json'), true);
        foreach ($pages as $pg) {
            $project = Project::where('realty_id', $realty->id)->where('name', $pg['name'])->first();
            if (! $project) {
                continue;
            }
            $project->update([
                'slug' => $pg['slug'],
                'region' => $pg['region'],
                'stage' => $pg['stage'],
                'is_public' => true,
                'hero_paths' => $pg['hero'],
                'site_plan_paths' => $pg['site_plans'],
                'amenities' => $pg['amenities'],
                'official_url' => $pg['official_url'],
                'lat' => $pg['map']['lat'] ?? $project->lat,
                'lng' => $pg['map']['lng'] ?? $project->lng,
            ]);
            foreach ($pg['unit_types'] as $i => $ut) {
                \App\Models\UnitType::updateOrCreate(
                    ['project_id' => $project->id, 'name' => $ut['name']],
                    ['realty_id' => $realty->id, 'specs' => $ut['specs'], 'image_paths' => $ut['images'], 'sort' => $i],
                );
            }
            foreach ($pg['updates'] as $up) {
                \App\Models\ProjectUpdate::updateOrCreate(
                    ['project_id' => $project->id, 'month' => $up['month']],
                    ['realty_id' => $realty->id, 'label' => $up['label'], 'photo_paths' => $up['photos']],
                );
            }
            $this->command->info("  public page: /projects/{$pg['slug']} (".count($pg['unit_types']).' models, '.count($pg['updates']).' months)');
        }
    }
}
