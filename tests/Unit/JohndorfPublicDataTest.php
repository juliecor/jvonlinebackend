<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The hand-kept seed data for Johndorf's public pages (database/seeders/data).
 * The dashboard and the offers take a house model's first image as the unit's
 * picture, so it must be a photo, never the floor plan; the plan is named per
 * model and stays in the model's gallery; no model borrows another project's
 * picture (Johndorf's own site had the Plumera 1BR plan on TierraNava Carcar).
 */
class JohndorfPublicDataTest extends TestCase
{
    private const S3 = 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/jvconline/johndorf/projects/';

    /** Models whose only Johndorf images are floor plans, so the project's cover photo leads. */
    private const PLAN_ONLY = [
        ['coral-village', 'Two-Storey Townhouse'],
        ['navona-court', 'Two-Storey Townhouse'],
        ['navona-davao', 'Two-Storey Townhouse'],
        ['plumera', 'Studio Unit'],
        ['plumera', '1BR Unit'],
        ['tierranava-carcar', 'Two-Storey Townhouse'],
        ['tierranava-lumbia', 'Two-Storey Townhouse'],
        ['tierranava-opol', 'Two-Storey Townhouse'],
        ['tierranava-tagoloan', 'Two-Storey Townhouse'],
    ];

    /** @return array<int, array<string, mixed>> */
    private function pages(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/johndorf-public.json'), true);
    }

    /** @return array<string, string> slug => cover_path */
    private function covers(): array
    {
        $rows = json_decode(file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/johndorf-projects.json'), true);

        return array_column($rows, 'cover_path', 'slug');
    }

    public function test_every_model_names_its_floor_plan_and_leads_with_a_picture_that_is_not_it(): void
    {
        foreach ($this->pages() as $page) {
            foreach ($page['unit_types'] as $ut) {
                $label = "{$page['slug']} / {$ut['name']}";
                $this->assertArrayHasKey('floor_plan', $ut, $label);
                $this->assertNotEmpty($ut['images'], $label);
                if ($ut['floor_plan'] !== null) {
                    $this->assertContains($ut['floor_plan'], $ut['images'], "$label: the plan stays in the gallery");
                    $this->assertNotSame($ut['floor_plan'], $ut['images'][0], "$label: the first image is the unit's picture, so it can't be the plan");
                }
            }
        }
    }

    public function test_plan_only_models_lead_with_their_projects_cover_photo(): void
    {
        $covers = $this->covers();
        $pages = array_column($this->pages(), null, 'slug');
        foreach (self::PLAN_ONLY as [$slug, $name]) {
            $ut = array_values(array_filter($pages[$slug]['unit_types'], fn ($u) => $u['name'] === $name))[0] ?? null;
            $this->assertNotNull($ut, "$slug / $name exists");
            $this->assertSame($covers[$slug], $ut['images'][0], "$slug / $name leads with the project's cover");
            $this->assertNotNull($ut['floor_plan'], "$slug / $name names its plan");
        }
    }

    public function test_no_model_borrows_another_projects_picture(): void
    {
        foreach ($this->pages() as $page) {
            foreach ($page['unit_types'] as $ut) {
                foreach ($ut['images'] as $url) {
                    if (str_starts_with($url, self::S3)) {
                        $this->assertStringStartsWith(self::S3.$page['slug'].'/', $url, "{$page['slug']} / {$ut['name']}: $url");
                    } else {
                        $this->assertStringStartsWith('/johndorf/site/', $url, "{$page['slug']} / {$ut['name']}: $url ships with the frontend");
                    }
                }
            }
        }
    }
}
