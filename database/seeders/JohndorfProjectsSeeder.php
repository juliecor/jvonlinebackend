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
    }
}
