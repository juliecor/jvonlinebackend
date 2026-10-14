<?php

namespace Tests\Feature;

use App\Mail\ReservationRequestMail;
use App\Mail\ReservationResultMail;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reserving the home from the sales offer: the agent sets a fee; the buyer pays it by bank/GCash and
 * uploads the proof, or online through PayMongo; the developer's admins confirm (the unit turns
 * reserved, the buyer is told) or send it back with a reason.
 */
class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private User $admin;

    private User $agent;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(), 'payment_instructions' => "BPI 1234-5678-90 (Johndorf Ventures Corp.)\nGCash 0917 000 0000"]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'email' => 'admin@example.com']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->johndorf->id, 'email' => 'agent@example.com']);
        $project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Astana Davao', 'slug' => 'astana-davao', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $project->id, 'name' => 'Block 2 · Lot 43', 'block' => '2', 'lot' => '43', 'category' => 'Residential', 'price' => 3300000, 'status' => 'available']);
    }

    public function test_the_buyer_uploads_proof_and_the_developer_confirms_which_reserves_the_unit(): void
    {
        Storage::fake(config('filesystems.documents'));
        Mail::fake();
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/realty/offers', ['unit_id' => $this->unit->id, 'buyer_name' => 'Rosa Cruz', 'buyer_email' => 'rosa@example.com', 'purchase_date' => now()->toDateString(), 'access_username' => 'rosa', 'access_password' => 'Rosa1234', 'reservation_fee' => 10000])->assertCreated();
        $offer = Offer::firstOrFail();
        $this->assertSame('10000.00', $offer->reservation_fee);
        $token = $offer->accessToken();
        $as = ['X-Offer-Access' => $token];

        // The buyer sees the fee, no online payment (PayMongo isn't set up), and where to pay.
        $this->getJson("/api/offers/{$offer->code}", $as)->assertOk()
            ->assertJsonPath('reservation.fee', 10000)->assertJsonPath('reservation.status', null)->assertJsonPath('reservation.online', false)
            ->assertJsonPath('reservation.instructions', $this->johndorf->payment_instructions);
        $this->postJson("/api/offers/{$offer->code}/reservation/checkout", [], $as)->assertStatus(422);

        // Proof of a GCash payment.
        $this->postJson("/api/offers/{$offer->code}/reservation/proof", ['proof' => UploadedFile::fake()->image('gcash.jpg'), 'reference' => 'GC-123456', 'note' => 'Paid this morning'], $as)
            ->assertCreated()->assertJsonPath('reservation.status', 'pending')->assertJsonPath('reservation.method', 'proof')->assertJsonPath('reservation.has_proof', true);
        $this->assertNotNull($offer->fresh()->reservation_proof_path);
        Storage::disk(config('filesystems.documents'))->assertExists($offer->fresh()->reservation_proof_path);
        // Admins and the agent are told.
        Mail::assertSent(ReservationRequestMail::class, fn (ReservationRequestMail $m) => $m->hasTo('admin@example.com'));
        Mail::assertSent(ReservationRequestMail::class, fn (ReservationRequestMail $m) => $m->hasTo('agent@example.com'));

        // The dashboard shows it; the agent can see the proof but not confirm.
        $this->getJson("/api/realty/offers/{$offer->id}")->assertOk()->assertJsonPath('reservation.status', 'pending')->assertJsonPath('reservation.reference', 'GC-123456');
        $this->getJson("/api/realty/offers/{$offer->id}/reservation/proof")->assertOk();
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/confirm")->assertForbidden();
        $this->getJson('/api/realty/offers')->assertJsonPath('0.reservation_status', 'pending');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/confirm")->assertOk()
            ->assertJsonPath('reservation.status', 'confirmed')->assertJsonPath('reservation.confirmed_by', $this->admin->name)->assertJsonPath('emailed', 'rosa@example.com');
        $unit = $this->unit->fresh();
        $this->assertSame(['reserved', $offer->id], [$unit->status, $unit->status_offer_id]);
        Mail::assertSent(ReservationResultMail::class, fn (ReservationResultMail $m) => $m->hasTo('rosa@example.com'));
        // Reserved: the buyer can't send another, and the unit shows reserved to everyone.
        $this->postJson("/api/offers/{$offer->code}/reservation/proof", ['proof' => UploadedFile::fake()->image('again.jpg')], $as)->assertStatus(422);
        $this->getJson("/api/offers/{$offer->code}", $as)->assertJsonPath('reservation.status', 'confirmed');
    }

    public function test_a_reservation_can_be_sent_back_with_a_reason_and_sent_again(): void
    {
        Storage::fake(config('filesystems.documents'));
        Mail::fake();
        $offer = $this->offer(['reservation_fee' => 15000]);
        $as = ['X-Offer-Access' => $offer->accessToken()];
        // Without the buyer's sign-in, nothing.
        $this->postJson("/api/offers/{$offer->code}/reservation/proof", ['proof' => UploadedFile::fake()->image('x.jpg')])->assertUnauthorized();
        $this->postJson("/api/offers/{$offer->code}/reservation/proof", ['proof' => UploadedFile::fake()->image('blurry.jpg')], $as)->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/decline", [])->assertJsonValidationErrors('note');
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/decline", ['note' => 'The photo is blurry, please send a clearer one.'])->assertOk()->assertJsonPath('reservation.status', 'declined');
        $this->assertSame('available', $this->unit->fresh()->status);
        Mail::assertSent(ReservationResultMail::class, fn (ReservationResultMail $m) => $m->hasTo('rosa@example.com'));
        // Nothing waits any more, so there is nothing to decline twice.
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/decline", ['note' => 'Again'])->assertStatus(422);

        $this->getJson("/api/offers/{$offer->code}", $as)->assertJsonPath('reservation.status', 'declined')->assertJsonPath('reservation.note', 'The photo is blurry, please send a clearer one.');
        $this->postJson("/api/offers/{$offer->code}/reservation/proof", ['proof' => UploadedFile::fake()->image('clear.jpg')], $as)->assertCreated()->assertJsonPath('reservation.status', 'pending')->assertJsonPath('reservation.note', null);
    }

    public function test_paying_online_goes_through_paymongo_and_its_webhook(): void
    {
        config(['services.paymongo.secret' => 'sk_test_x', 'services.paymongo.webhook_secret' => 'whsk_test']);
        Mail::fake();
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_123', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_123']]]),
            'api.paymongo.com/v1/checkout_sessions/cs_123' => Http::response(['data' => ['id' => 'cs_123', 'attributes' => ['payments' => [['id' => 'pay_1', 'attributes' => ['status' => 'paid']]]]]]),
        ]);
        $offer = $this->offer(['reservation_fee' => 10000]);
        $as = ['X-Offer-Access' => $offer->accessToken()];

        $this->getJson("/api/offers/{$offer->code}", $as)->assertJsonPath('reservation.online', true);
        $this->postJson("/api/offers/{$offer->code}/reservation/checkout", [], $as)->assertOk()->assertJsonPath('url', 'https://checkout.paymongo.com/cs_123');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/checkout_sessions') && $r['data']['attributes']['line_items'][0]['amount'] === 1000000 && $r['data']['attributes']['reference_number'] === $offer->code);
        $this->assertSame(['pending', 'online', 'cs_123'], [$offer->fresh()->reservation_status, $offer->fresh()->reservation_method, $offer->fresh()->reservation_checkout_id]);

        // The webhook, signed by PayMongo, marks it paid; a bad signature is refused.
        $body = json_encode(['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => 'cs_123', 'attributes' => ['payments' => [['id' => 'pay_1']]]]]]]);
        $this->call('POST', '/api/webhooks/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => 't=1,te=bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        $sig = hash_hmac('sha256', "1.{$body}", 'whsk_test');
        $this->call('POST', '/api/webhooks/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => "t=1,te={$sig},li=", 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(['paid', 'pay_1'], [$offer->fresh()->reservation_status, $offer->fresh()->reservation_reference]);
        $this->assertNotNull($offer->fresh()->reservation_paid_at);
        Mail::assertSent(ReservationRequestMail::class, fn (ReservationRequestMail $m) => $m->hasTo('admin@example.com'));

        // Coming back before the webhook also works: the status check asks PayMongo.
        $other = $this->offer(['reservation_fee' => 10000, 'reservation_status' => 'pending', 'reservation_method' => 'online', 'reservation_checkout_id' => 'cs_123']);
        $this->getJson("/api/offers/{$other->code}/reservation", ['X-Offer-Access' => $other->accessToken()])->assertOk()->assertJsonPath('reservation.status', 'paid');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/offers/{$offer->id}/reservation/confirm")->assertOk()->assertJsonPath('reservation.status', 'confirmed');
        $this->assertSame('reserved', $this->unit->fresh()->status);
    }

    public function test_the_developer_types_where_buyers_pay(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/realty/reservation-settings')->assertOk()->assertJsonPath('online', false);
        $this->postJson('/api/realty/reservation-settings', ['payment_instructions' => 'Maya 0918 111 2222'])->assertOk()->assertJsonPath('payment_instructions', 'Maya 0918 111 2222');
        $this->assertSame('Maya 0918 111 2222', $this->johndorf->fresh()->payment_instructions);
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/realty/reservation-settings', ['payment_instructions' => 'x'])->assertForbidden();
    }

    /** @param  array<string, mixed>  $overrides */
    private function offer(array $overrides = []): Offer
    {
        return Offer::create($overrides + [
            'realty_id' => $this->johndorf->id, 'project_id' => $this->unit->project_id, 'unit_id' => $this->unit->id, 'agent_id' => $this->agent->id,
            'code' => Offer::newCode(), 'buyer_name' => 'Rosa Cruz', 'buyer_email' => 'rosa@example.com', 'purchase_date' => now()->toDateString(), 'price' => 3300000, 'schedule' => [], 'status' => 'active',
            'access_username' => 'rosa', 'access_password' => 'Rosa1234',
        ]);
    }
}
