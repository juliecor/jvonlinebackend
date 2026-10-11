<?php

namespace Tests\Feature;

use App\Mail\AccreditationPeriodMail;
use App\Models\AccreditationDocument;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RealtyAccreditation;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccreditationAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Broker's Accreditation Agreement: the developer sets its details, a realty agrees on its form,
 * the developer accepts it for a period (a year, to December 31 or a date), renews or ends it, and
 * checks the signed and notarized copy; once the period runs out the realty makes no new offers.
 */
class AccreditationAgreementTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(AccreditationDocument::disk());
        config(['app.frontend_url' => 'https://jvconline.ph']);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 11)->setTime(10, 0));
        $this->johndorf = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id]);
    }

    public function test_once_the_details_are_set_a_realty_agrees_on_its_form_with_its_own_name(): void
    {
        $token = basename($this->api($this->admin, 'post', '/api/realty/realties/invite', ['email' => 'abby@abc.example'])->json('accreditation_url'));
        $this->assertNull($this->getJson("/api/accreditation/{$token}")->json('agreement'));

        $this->api($this->admin, 'post', '/api/realty/realties/agreement', $this->settings())
            ->assertOk()->assertJsonPath('ready', true)->assertJsonCount(17, 'preview.terms')->assertJsonPath('preview.developer.representative', 'Maria Santos');
        $agreement = $this->getJson("/api/accreditation/{$token}")->assertOk()->json('agreement');
        $this->assertStringContainsString('under the group of __________', $agreement['terms'][0]['text']);
        $this->assertSame(30, $agreement['signed_copy_days']);

        $this->post("/api/accreditation/{$token}", $this->answers() + ['documents' => $this->files()], ['Accept' => 'application/json'])->assertJsonValidationErrors(['agree', 'agreed_name']);
        $this->post("/api/accreditation/{$token}", $this->answers() + ['agree' => '1', 'agreed_name' => 'Someone Else', 'documents' => $this->files()], ['Accept' => 'application/json'])->assertJsonValidationErrors('agreed_name');
        $this->post("/api/accreditation/{$token}", $this->answers() + ['agree' => '1', 'agreed_name' => ' abby  cruz ', 'documents' => $this->files()], ['Accept' => 'application/json'])->assertCreated();

        $form = RealtyAccreditation::firstWhere('login_email', 'abby@abc.example');
        $this->assertSame('abby cruz', $form->agreed_name);
        $this->assertSame(['name' => 'Abby Cruz', 'firm' => 'ABC Realty', 'address' => '12 Colon Street, Cebu City', 'citizenship' => 'Filipino'], $form->agreement['broker']);
        $this->assertSame([['classification' => 'PRC Licensed Broker', 'rate' => '5%']], $form->agreement['commission']);
    }

    public function test_accepting_sets_the_period_a_year_to_december_31_or_a_chosen_date(): void
    {
        foreach ([['1y', null, '2027-10-10'], ['dec31', null, '2026-12-31'], ['custom', '2027-03-15', '2027-03-15']] as $i => [$term, $until, $expected]) {
            $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['login_email' => "b{$i}@example.com", 'firm_name' => "Firm {$i}"]);
            $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/approve", array_filter(['term' => $term, 'until' => $until]))->assertCreated();
            $form = $form->fresh();
            $this->assertSame(['2026-10-11', $expected, 'missing', '2026-11-10'], [$form->accredited_from->toDateString(), $form->accredited_until->toDateString(), $form->signed_copy_status, $form->signed_copy_due->toDateString()]);
        }

        $period = $this->api($this->admin, 'get', '/api/realty/realties')->json('brokers.0.period');
        $this->assertSame('active', $period['state']);
    }

    public function test_an_ended_period_stops_new_offers_and_renewing_starts_it_again(): void
    {
        [$form, $brokerAdmin] = $this->accepted('dec31');
        $this->travelTo(now()->setDate(2027, 1, 1));

        $this->assertFalse($form->realty->fresh()->isAccredited());
        $this->api($brokerAdmin, 'post', '/api/realty/offers', $this->offerFor())->assertForbidden()->assertJsonPath('message', 'Your accreditation with Johndorf Ventures Corporation ended on Dec 31, 2026. Ask them to renew it to make new offers.');
        $this->api($brokerAdmin, 'get', '/api/auth/me')->assertJsonPath('realty.accreditation.active', false);

        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/term", ['term' => '1y'])->assertOk()->assertJsonPath('until', '2027-12-31')->assertJsonPath('state', 'active');
        $this->assertTrue($form->realty->fresh()->isAccredited());

        // Renewing early adds the time after the current last day.
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/term", ['term' => 'dec31'])->assertJsonPath('until', '2028-12-31');

        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/end", [])->assertJsonValidationErrors('note');
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/end", ['note' => 'Unapproved ads on Facebook'])->assertJsonPath('state', 'ended');
        $this->assertFalse($form->realty->fresh()->isAccredited());
    }

    public function test_the_realty_prints_its_agreement_with_the_dates_and_uploads_the_signed_copy_for_checking(): void
    {
        [$form, $brokerAdmin] = $this->accepted('custom', '2027-06-30');

        $mine = $this->api($brokerAdmin, 'get', '/api/realty/accreditation')->assertOk()->assertJsonPath('until', '2027-06-30')->assertJsonPath('signed_copy.status', 'missing');
        $this->assertStringContainsString('in effect from October 11, 2026 to June 30, 2027', $mine->json('agreement.terms.8.text'));
        $this->assertSame('This Accreditation Agreement is valid until June 30, 2027.', $mine->json('agreement.terms.16.text'));

        $this->api($brokerAdmin, 'post', '/api/realty/accreditation/signed-copy', ['file' => UploadedFile::fake()->create('signed.pdf', 300, 'application/pdf')])->assertOk()->assertJsonPath('signed_copy.status', 'submitted');
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/signed-copy", ['verdict' => 'returned'])->assertJsonValidationErrors('note');
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/signed-copy", ['verdict' => 'returned', 'note' => 'Page 2 is not signed'])->assertJsonPath('signed_copy.status', 'returned');
        $this->api($brokerAdmin, 'get', '/api/realty/accreditation')->assertJsonPath('signed_copy.note', 'Page 2 is not signed');

        $this->api($brokerAdmin, 'post', '/api/realty/accreditation/signed-copy', ['file' => UploadedFile::fake()->create('signed-again.pdf', 300, 'application/pdf')])->assertOk();
        $this->assertSame(1, $form->documents()->where('kind', AccreditationDocument::SIGNED_AGREEMENT)->count());
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/signed-copy", ['verdict' => 'verified'])->assertJsonPath('signed_copy.status', 'verified');
        $doc = $this->api($this->admin, 'get', "/api/realty/realties/accreditations/{$form->id}")->json('documents');
        $this->assertSame(['Signed and notarized accreditation agreement'], array_column(array_filter($doc, fn ($d) => $d['kind'] === 'signed_agreement'), 'label'));
        $this->api($brokerAdmin, 'post', '/api/realty/accreditation/signed-copy', ['file' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')])->assertStatus(409);
    }

    public function test_reminders_go_out_once_thirty_days_before_and_once_after_it_ends(): void
    {
        [$form] = $this->accepted('custom', '2026-11-05');
        Mail::fake();

        $this->artisan('accreditations:remind')->assertSuccessful();
        Mail::assertSent(AccreditationPeriodMail::class, fn (AccreditationPeriodMail $m) => $m->state === 'ending' && ! $m->forDeveloper && $m->hasTo('abby@abc.example'));
        Mail::assertSent(AccreditationPeriodMail::class, fn (AccreditationPeriodMail $m) => $m->state === 'ending' && $m->forDeveloper && $m->hasTo($this->admin->email));
        $this->artisan('accreditations:remind');
        Mail::assertSentCount(2);

        $this->travelTo(now()->setDate(2026, 11, 6));
        $this->artisan('accreditations:remind');
        Mail::assertSent(AccreditationPeriodMail::class, fn (AccreditationPeriodMail $m) => $m->state === 'ended');
        $this->assertSame(4, Mail::sent(AccreditationPeriodMail::class)->count());
    }

    /** @return array{0: RealtyAccreditation, 1: User} An accepted realty (agreed online) and its admin. */
    private function accepted(string $term, ?string $until = null): array
    {
        $this->api($this->admin, 'post', '/api/realty/realties/agreement', $this->settings());
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create($this->answers());
        $form->update(['agreement' => AccreditationAgreement::snapshot($this->johndorf, $form), 'agreed_name' => 'Abby Cruz', 'agreed_at' => now()]);
        $this->api($this->admin, 'post', "/api/realty/realties/accreditations/{$form->id}/approve", array_filter(['term' => $term, 'until' => $until]))->assertCreated();
        $form = $form->fresh();
        $brokerAdmin = User::where('realty_id', $form->realty_id)->firstOrFail();
        $brokerAdmin->forceFill(['must_change_password' => false])->save();

        return [$form, $brokerAdmin];
    }

    /** @return array<string, mixed> */
    private function offerFor(): array
    {
        $project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Plumera Mactan', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $project->id, 'name' => 'Unit 1', 'price' => 1000000, 'status' => 'available']);

        return ['unit_id' => $unit->id, 'buyer_name' => 'A Buyer', 'purchase_date' => now()->toDateString()];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['representative_name' => 'Maria Santos', 'representative_title' => 'Sales Director', 'office_address' => 'Cebu City', 'commission' => [['classification' => 'PRC Licensed Broker', 'rate' => '5%']], 'signed_copy_days' => 30];
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return [
            'business_type' => 'corporation', 'firm_name' => 'ABC Realty', 'residential_address' => '12 Colon Street, Cebu City',
            'tin_company' => '123-456-789-000', 'tin_personal' => '987-654-321-000', 'prc_number' => 'PRC-100200', 'prc_valid_until' => now()->addYear()->toDateString(),
            'representative_name' => 'Abby Cruz', 'place_of_birth' => 'Cebu City', 'date_of_birth' => '1985-04-12', 'citizenship' => 'Filipino', 'gender' => 'female',
            'civil_status' => 'Married', 'mobile' => '09171234567', 'login_email' => 'abby@abc.example', 'years_in_real_estate' => 8, 'years_firm_operating' => 5, 'salespersons' => 12,
        ];
    }

    /** @return array<string, UploadedFile> */
    private function files(): array
    {
        return ['board_resolution' => UploadedFile::fake()->create('resolution.pdf', 120, 'application/pdf'), 'sec_registration' => UploadedFile::fake()->create('sec.pdf', 200, 'application/pdf')];
    }

    /** Each call is a fresh request with its own token, as from the Next.js server. */
    private function api(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('web')->plainTextToken)->json($method, $uri, $data);
    }
}
