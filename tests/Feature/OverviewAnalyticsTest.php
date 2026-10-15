<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Overview's statistics: inventory by status, six months of offers and deals, the buyer funnel,
 * projects, top sellers, reservations and latest activity. An agent only ever sees their own offers.
 */
class OverviewAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_see_the_whole_realty_and_agents_only_their_own_offers(): void
    {
        $realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $realty->id]);
        $ana = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $realty->id, 'name' => 'Ana Agent']);
        $ben = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $realty->id, 'name' => 'Ben Agent']);
        $project = Project::create(['realty_id' => $realty->id, 'name' => 'Astana Davao', 'status' => 'active']);
        $units = collect(range(1, 4))->map(fn (int $i) => Unit::create(['realty_id' => $realty->id, 'project_id' => $project->id, 'name' => "Block 1 · Lot {$i}", 'category' => 'Residential', 'price' => 3000000, 'status' => 'available']));
        $offer = fn (User $agent, Unit $unit, array $extra = []) => tap(Offer::create(array_diff_key($extra, ['views' => 1]) + ['realty_id' => $realty->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $agent->id, 'code' => Offer::newCode(), 'buyer_name' => 'Buyer '.$unit->id, 'purchase_date' => now()->toDateString(), 'price' => 3000000, 'schedule' => [], 'status' => 'active']), fn (Offer $o) => $o->forceFill(['views' => $extra['views'] ?? 0])->save());

        $rosa = $offer($ana, $units[0], ['views' => 3, 'reservation_fee' => 10000, 'reservation_status' => 'confirmed', 'reservation_confirmed_at' => now()]);
        OfferResponse::create(['offer_id' => $rosa->id, 'realty_id' => $realty->id, 'kind' => 'interested', 'name' => 'Rosa', 'phone' => '09170000000']);
        $units[0]->update(['status' => 'reserved', 'status_offer_id' => $rosa->id, 'status_at' => now()]);
        $offer($ana, $units[1], ['views' => 1]);
        $offer($ben, $units[2], ['reservation_fee' => 15000, 'reservation_status' => 'pending', 'reservation_method' => 'proof', 'reservation_requested_at' => now()]);

        Sanctum::actingAs($admin);
        $a = $this->getJson('/api/realty/overview')->assertOk()->json('analytics');
        $this->assertSame(['available' => 3, 'reserved' => 1, 'sold' => 0], $a['inventory']);
        $this->assertSame([3, 2, 1, 1, 1], array_column($a['funnel'], 'n'));
        $this->assertSame(['offers' => 3, 'reserved' => 1, 'sold' => 0], array_intersect_key(end($a['trend']), array_flip(['offers', 'reserved', 'sold'])));
        $this->assertCount(6, $a['trend']);
        $this->assertEquals(['to_confirm' => 1, 'confirmed' => 1, 'fees_confirmed' => 10000, 'value_held' => 3000000], $a['reservations']);
        $this->assertSame(['Ana Agent', 2, 1, 1], [$a['agents'][0]['name'], $a['agents'][0]['offers'], $a['agents'][0]['interested'], $a['agents'][0]['deals']]);
        $this->assertSame([['name' => 'Astana Davao', 'units' => 4, 'available' => 3, 'reserved' => 1, 'sold' => 0]], array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'units', 'available', 'reserved', 'sold'])), $a['projects']));
        $this->assertNotEmpty($a['recent']);

        Sanctum::actingAs($ben);
        $mine = $this->getJson('/api/realty/overview')->assertOk()->json('analytics');
        $this->assertSame([1, 0, 0, 0, 0], array_column($mine['funnel'], 'n'));
        $this->assertSame([], $mine['agents']);
        $this->assertEquals(['to_confirm' => 1, 'confirmed' => 0, 'fees_confirmed' => 0, 'value_held' => 0], $mine['reservations']);
        $this->assertStringNotContainsString('Buyer '.$units[0]->id, json_encode($mine['recent']));
    }
}
