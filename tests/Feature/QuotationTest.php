<?php

namespace Tests\Feature;

use App\Models\PaymentPlan;
use App\Models\Project;
use App\Models\Realty;
use App\Models\SiteRow;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\Cards;
use App\Support\Assistant\RealtyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A quotation (sample computation) for a buyer: today's price and plan, the offers' own schedule math,
 * custom terms checked like an offer's, and only the realty's own units. The AI's payment card carries
 * the buyer's name to it.
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $agent;

    private Project $project;

    private Unit $unit;

    private PaymentPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'phone' => '(088) 123 4567']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent', 'phone' => '0917 111 2222']);
        $this->project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Astana Davao', 'location' => 'Davao', 'status' => 'active', 'fee_notes' => 'Transfer fees are paid by the buyer.']);
        $this->unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => 'Block 2 · Lot 5', 'category' => 'Residential', 'unit_type' => 'Two-Storey Townhouse', 'lot_area_sqm' => 48, 'area_sqm' => 60, 'tags' => ['corner'], 'price' => 3000000, 'status' => 'available']);
        $this->plan = PaymentPlan::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'name' => '20% down in 12 months', 'milestones' => [
            ['label' => 'Down payment', 'percent' => 20, 'days' => 30, 'months' => 12],
            ['label' => 'Balance', 'percent' => 80, 'days' => null, 'months' => null],
        ]]);
    }

    public function test_a_quotation_uses_todays_price_and_plan_from_today(): void
    {
        Sanctum::actingAs($this->agent);
        $q = $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id, 'plan_id' => $this->plan->id, 'purchase_date' => '2026-09-01', 'buyer' => ' Rosa Cruz '])->assertOk()->json();

        $this->assertSame(['Rosa Cruz', 3000000, '20% down in 12 months', '2026-10-16'], [$q['buyer'], (int) $q['price'], $q['terms'], $q['purchase_date']]);
        $this->assertEquals(3000000, $q['total']);
        $this->assertSame([12, 50000, '2026-11-15'], [$q['schedule'][0]['months'], (int) $q['schedule'][0]['monthly'], $q['schedule'][0]['date']]);
        $this->assertNull($q['schedule'][1]['date']);
        $this->assertSame(['Two-Storey Townhouse', 48, 60, ['Corner lot']], [$q['unit']['model'], (int) $q['unit']['lot_area_sqm'], (int) $q['unit']['floor_area_sqm'], $q['unit']['features']]);
        $this->assertSame(['Ana Agent', '0917 111 2222', 'Q-261016-'.$this->unit->id], [$q['prepared']['by'], $q['prepared']['phone'], $q['prepared']['reference']]);
        $this->assertSame(['Transfer fees are paid by the buyer.', '(088) 123 4567'], [$q['project']['fee_notes'], $q['realty']['phone']]);
        $this->assertTrue($q['can_offer']);
        $this->assertFalse($q['price_from_model']);

        // The price changed since: the quotation follows it.
        $this->unit->update(['price' => 3200000]);
        $this->assertEquals(3200000, $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id, 'plan_id' => $this->plan->id])->json('total'));
    }

    public function test_custom_terms_are_checked_like_an_offers(): void
    {
        Sanctum::actingAs($this->agent);
        $terms = [['label' => 'Reservation', 'percent' => 1, 'days' => 0], ['label' => 'Equity', 'percent' => 19, 'days' => 30, 'months' => 24], ['label' => 'Bank loan', 'percent' => 80, 'days' => null]];
        $q = $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id, 'milestones' => $terms])->assertOk()->json();
        $this->assertSame(['Custom terms', 3, 30000], [$q['terms'], count($q['schedule']), (int) $q['schedule'][0]['amount']]);
        $this->assertEquals(3000000, $q['total']);

        $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id, 'milestones' => [['label' => 'Down', 'percent' => 30, 'days' => 0]]])->assertUnprocessable()->assertJsonValidationErrors('milestones');
        $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id])->assertUnprocessable()->assertJsonValidationErrors('milestones');
        $this->postJson('/api/realty/quotation', ['price' => 2500000, 'milestones' => [['label' => 'Full payment', 'percent' => 100, 'days' => 0]]])->assertOk()->assertJsonPath('unit', null);
    }

    public function test_a_lot_without_a_price_is_quoted_at_its_models_price_and_other_realties_units_are_out_of_reach(): void
    {
        // Block 2 · Lot 5 (₱3,000,000, not on the site map) is the Two-Storey Townhouse's model price.
        $row = SiteRow::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'block' => '3', 'corners' => [[0, 0], [1, 0], [1, 1], [0, 1]], 'first_lot' => 1, 'lot_count' => 1]);
        $lot = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->project->id, 'site_row_id' => $row->id, 'name' => 'Block 3 · Lot 1', 'block' => '3', 'lot' => '1', 'category' => 'Residential', 'unit_type' => 'Two-Storey Townhouse', 'status' => 'available']);

        Sanctum::actingAs($this->agent);
        $q = $this->postJson('/api/realty/quotation', ['unit_id' => $lot->id, 'plan_id' => $this->plan->id])->assertOk()->json();
        $this->assertSame([3000000, true], [(int) $q['price'], $q['price_from_model']]);

        $other = Realty::create(['name' => 'Other Developer', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $theirs = Unit::create(['realty_id' => $other->id, 'project_id' => Project::create(['realty_id' => $other->id, 'name' => 'Elsewhere', 'status' => 'active'])->id, 'name' => 'Unit 1', 'category' => 'Residential', 'price' => 1000000, 'status' => 'available']);
        $this->postJson('/api/realty/quotation', ['unit_id' => $theirs->id, 'milestones' => [['label' => 'Full', 'percent' => 100, 'days' => 0]]])->assertNotFound();
        $this->postJson('/api/realty/quotation', ['unit_id' => $this->unit->id, 'plan_id' => 999999])->assertUnprocessable();
    }

    public function test_the_ai_puts_the_buyers_name_on_the_payment_card(): void
    {
        $tools = new RealtyTools($this->agent);
        $tools->call('compute_payments', ['unit' => 'Block 2 · Lot 5', 'plan' => '20% down', 'buyer_name' => 'Rosa Cruz']);

        $card = Cards::for($this->agent, $tools->shownCards())[0];
        $this->assertSame(['payment', 'Rosa Cruz', $this->plan->id], [$card['type'], $card['buyer'], $card['plan_id']]);
        $this->assertTrue($card['can_offer']);
    }
}
