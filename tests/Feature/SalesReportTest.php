<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\Cards;
use App\Support\Assistant\RealtyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sales report for a period (the report page, its PDF and the AI's report card): offers sent, answers,
 * homes reserved and sold, fees confirmed, by project and agent, against the period before. Agents only
 * ever get their own offers.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $admin;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        // Friday, October 16, 2026: this week is Oct 12–16, this month Oct 1–16.
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));

        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => 'Johndorf Admin']);
        $this->ana = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent']);
        $this->ben = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ben Agent']);

        $astana = Project::create(['realty_id' => $this->realty->id, 'name' => 'Astana Davao', 'status' => 'active']);
        $montierra = Project::create(['realty_id' => $this->realty->id, 'name' => 'Montierra', 'status' => 'active']);
        $unit = fn (Project $p, string $name, int $price) => Unit::create(['realty_id' => $this->realty->id, 'project_id' => $p->id, 'name' => $name, 'category' => 'Residential', 'price' => $price, 'status' => 'available']);
        $u1 = $unit($astana, 'Block 1 · Lot 1', 3000000);
        $u2 = $unit($astana, 'Block 1 · Lot 2', 3000000);
        $u3 = $unit($astana, 'Block 1 · Lot 3', 3000000);
        $u4 = $unit($astana, 'Block 1 · Lot 4', 3100000);
        $u6 = $unit($montierra, 'Bldg A · Unit 1', 2800000);

        $o1 = $this->offer($this->ana, $u1, '2026-10-02 09:00', views: 3, extra: ['reservation_fee' => 10000, 'reservation_status' => 'confirmed', 'reservation_confirmed_at' => $this->at('2026-10-05 15:00')]);
        $this->answer($o1, 'interested', '2026-10-03 11:00');
        $u1->update(['status' => 'reserved', 'status_offer_id' => $o1->id, 'status_at' => $this->at('2026-10-05 15:00')]);
        $this->offer($this->ana, $u2, '2026-10-10 09:00');
        $o3 = $this->offer($this->ben, $u6, '2026-10-12 09:00', views: 1);
        $this->answer($o3, 'question', '2026-10-13 08:00');
        // The month before: one offer, and its home sold.
        $o4 = $this->offer($this->ben, $u3, '2026-09-10 09:00', views: 1);
        $u3->update(['status' => 'sold', 'status_offer_id' => $o4->id, 'status_at' => $this->at('2026-09-12 10:00')]);
        // Marked sold by the admin without an offer.
        $u4->update(['status' => 'sold', 'status_at' => $this->at('2026-10-14 10:00')]);
    }

    public function test_an_admins_report_counts_the_period_and_compares_it_with_the_one_before(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/api/realty/reports?period=this_month')->assertOk()->json();

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-16', 'label' => 'This month', 'dates' => 'Oct 1 – 16, 2026', 'days' => 16, 'compared_with' => 'Sep 1 – 16, 2026'], $r['period']);
        $this->assertEquals(['offers_sent' => 3, 'opened' => 2, 'answers' => 2, 'interested' => 1, 'questions' => 1, 'not_interested' => 0, 'reserved' => 1, 'sold' => 1, 'value' => 6100000, 'reservations_confirmed' => 1, 'fees_confirmed' => 10000], $r['totals']);
        $this->assertSame([1, 0, 1], [$r['previous']['offers_sent'], $r['previous']['reserved'], $r['previous']['sold']]);
        $this->assertSame(['Astana Davao', 2, 1, 1], [$r['projects'][0]['name'], $r['projects'][0]['offers'], $r['projects'][0]['reserved'], $r['projects'][0]['sold']]);
        $this->assertSame(['Montierra', 1], [$r['projects'][1]['name'], $r['projects'][1]['offers']]);
        $this->assertSame([['Ana Agent', 2, 1, 1], ['Ben Agent', 1, 0, 0]], array_map(fn (array $a) => [$a['name'], $a['offers'], $a['interested'], $a['deals']], $r['agents']));
        $this->assertSame(['Block 1 · Lot 4', 'Block 1 · Lot 1'], array_column($r['deals'], 'unit'));
        $this->assertCount(3, $r['offers']);
        $this->assertSame('Has a question', $r['offers'][0]['answer']);
        $this->assertStringStartsWith('3 offers sent, up from 1 the period before', $r['highlights'][0]);
        $this->assertStringContainsString('2 homes reserved or sold (1 reserved, 1 sold), worth ₱6,100,000', $r['highlights'][1]);
        $this->assertSame("All of Johndorf Ventures Corporation's offers and units", $r['prepared']['scope']);
    }

    public function test_an_agent_only_gets_their_own_offers(): void
    {
        Sanctum::actingAs($this->ben);
        $r = $this->getJson('/api/realty/reports?period=this_month')->assertOk()->json();

        $this->assertSame([1, 1, 0, 0], [$r['totals']['offers_sent'], $r['totals']['answers'], $r['totals']['reserved'], $r['totals']['sold']]);
        $this->assertSame([1, 1], [$r['previous']['offers_sent'], $r['previous']['sold']]);
        $this->assertSame([], $r['agents']);
        $this->assertSame(['Bldg A · Unit 1'], array_column($r['offers'], 'unit'));
        $this->assertNull($r['offers'][0]['agent']);
        $this->assertSame('Your own offers', $r['prepared']['scope']);
        $this->assertStringNotContainsString('Ana Agent', json_encode($r));
    }

    public function test_any_two_dates_or_a_named_period(): void
    {
        Sanctum::actingAs($this->admin);
        $custom = $this->getJson('/api/realty/reports?from=2026-10-10&to=2026-10-12')->assertOk()->json();
        $this->assertSame(['Oct 10 – 12, 2026', 'Oct 7 – 9, 2026', 2], [$custom['period']['label'], $custom['period']['compared_with'], $custom['totals']['offers_sent']]);

        $week = $this->getJson('/api/realty/reports?period=last_week')->assertOk()->json('period');
        $this->assertSame(['2026-10-05', '2026-10-11', 'Last week', 'Sep 28 – Oct 4, 2026'], [$week['from'], $week['to'], $week['label'], $week['compared_with']]);
        $this->assertSame('This week', $this->getJson('/api/realty/reports?from=2026-10-12&to=2026-10-16')->json('period.label'));
        $this->assertSame('Aug 1 – 31, 2026', $this->getJson('/api/realty/reports?period=last_month')->json('period.compared_with'));

        $this->getJson('/api/realty/reports?from=2026-10-12&to=2026-10-01')->assertUnprocessable()->assertJsonPath('message', 'The end date is before the start date.');
        $this->getJson('/api/realty/reports?from=2024-01-01&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/realty/reports?period=forever')->assertUnprocessable();
    }

    public function test_staff_without_the_offers_section_get_no_report(): void
    {
        $tasksOnly = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'permissions' => ['tasks']]);
        Sanctum::actingAs($tasksOnly);
        $this->getJson('/api/realty/reports?period=this_month')->assertForbidden();
        $this->assertArrayHasKey('error', (new RealtyTools($tasksOnly))->call('make_report', ['period' => 'this_month']));
    }

    public function test_the_ai_puts_the_report_under_its_answer(): void
    {
        $tools = new RealtyTools($this->admin);
        $result = $tools->call('make_report', ['period' => 'this_week']);

        $this->assertSame('This week (Oct 12 – 16, 2026), compared with Oct 5 – 9, 2026', $result['period']);
        $this->assertSame([1, 1], [$result['totals']['offers_sent'], $result['totals']['sold']]);
        $this->assertSame([['type' => 'report', 'id' => 1, 'from' => '2026-10-12', 'to' => '2026-10-16']], $tools->shownCards());

        $card = Cards::for($this->admin, $tools->shownCards())[0];
        $this->assertSame(['report', 'This week', 1, 1], [$card['type'], $card['period']['label'], $card['totals']['offers_sent'], $card['totals']['sold']]);
        $this->assertSame(['error' => 'The end date is before the start date.'], $tools->call('make_report', ['from' => '2026-10-12', 'to' => '2026-10-01']));
        // An agent's card stays their own: Ana sent nothing this week.
        $this->assertSame([0, 0], [Cards::for($this->ana, $tools->shownCards())[0]['totals']['offers_sent'], Cards::for($this->ana, $tools->shownCards())[0]['totals']['sold']]);
    }

    private function offer(User $agent, Unit $unit, string $at, int $views = 0, array $extra = []): Offer
    {
        $offer = Offer::create($extra + ['realty_id' => $this->realty->id, 'project_id' => $unit->project_id, 'unit_id' => $unit->id, 'agent_id' => $agent->id, 'code' => Offer::newCode(), 'buyer_name' => 'Buyer of '.$unit->name, 'purchase_date' => '2026-10-01', 'price' => $unit->price, 'schedule' => [], 'status' => 'active']);
        $offer->forceFill(['views' => $views, 'created_at' => $this->at($at)])->save();

        return $offer;
    }

    private function answer(Offer $offer, string $kind, string $at): void
    {
        OfferResponse::create(['offer_id' => $offer->id, 'realty_id' => $this->realty->id, 'kind' => $kind, 'name' => 'Buyer', 'phone' => '09170000000'])->forceFill(['created_at' => $this->at($at)])->save();
    }

    private function at(string $manila): Carbon
    {
        return Carbon::parse($manila, 'Asia/Manila')->utc();
    }
}
