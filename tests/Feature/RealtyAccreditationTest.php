<?php

namespace Tests\Feature;

use App\Mail\AccreditationApprovedMail;
use App\Mail\AccreditationInviteMail;
use App\Models\AccreditationDocument;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RealtyAccreditation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Johndorf invites a realty by email, the realty sends back the accreditation form, Johndorf's
 * admins review and accept it, and the realty signs in with a temporary password it must replace.
 */
class RealtyAccreditationTest extends TestCase
{
    use RefreshDatabase;

    private Realty $johndorf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://jvconline.ph']);
        $this->johndorf = Realty::create([
            'name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(),
            'logo_path' => '/johndorf/logo.png', 'accent_color' => '#b4241c',
        ]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'name' => 'Johndorf Admin']);
    }

    // ----- inviting -----

    public function test_inviting_an_email_sends_the_accreditation_link_and_returns_it(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/realty/realties/invite', ['email' => 'Broker@Example.com'])
            ->assertCreated()
            ->assertJsonPath('email', 'broker@example.com')
            ->assertJsonPath('emailed', true);

        $url = $response->json('accreditation_url');
        $this->assertStringStartsWith('https://jvconline.ph/accreditation/', $url);
        $token = basename($url);
        $form = RealtyAccreditation::findByToken($token);
        $this->assertSame(RealtyAccreditation::STATUS_INVITED, $form->status);
        $this->assertSame($this->johndorf->id, $form->developer_id);
        $this->assertSame($this->admin->id, $form->invited_by);
        $this->assertTrue($form->expires_at->isAfter(now()->addDays(6)));
        Mail::assertSent(AccreditationInviteMail::class, fn (AccreditationInviteMail $m) => $m->hasTo('broker@example.com') && $m->url === $url);
    }

    public function test_a_failed_invite_mail_still_returns_the_link_for_the_staff_to_pass_on(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP is down'));
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', ['email' => 'broker@example.com'])
            ->assertCreated()
            ->assertJsonPath('emailed', false)
            ->assertJsonStructure(['accreditation_url']);
        $this->assertDatabaseHas('realty_accreditations', ['email' => 'broker@example.com', 'status' => 'invited']);
    }

    public function test_inviting_an_email_that_already_has_an_account_returns_422(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'There is already an account with this email.');
        $this->assertDatabaseCount('realty_accreditations', 0);
    }

    public function test_inviting_an_email_with_an_open_invite_returns_422_and_points_to_resend(): void
    {
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->create(['email' => 'broker@example.com']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', ['email' => 'broker@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'An invite to this email is still open. Resend it instead.');
    }

    public function test_inviting_an_email_whose_form_is_waiting_for_review_returns_422(): void
    {
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['email' => 'broker@example.com']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', ['email' => 'broker@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This email already sent in its form. Review it under Pending review.');
    }

    public function test_inviting_again_after_the_link_expired_replaces_the_old_invite(): void
    {
        Mail::fake();
        $old = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->expired()->create(['email' => 'broker@example.com']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', ['email' => 'broker@example.com'])->assertCreated();

        $this->assertModelMissing($old);
        $this->assertDatabaseCount('realty_accreditations', 1);
        Mail::assertSent(AccreditationInviteMail::class);
    }

    public function test_an_invalid_invite_email_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/realty/realties/invite', [])->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson('/api/realty/realties/invite', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_resending_an_invite_makes_a_new_link_and_the_old_one_stops_working(): void
    {
        Mail::fake();
        [$form, $oldToken] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        Sanctum::actingAs($this->admin);

        $newUrl = $this->postJson("/api/realty/realties/invitations/{$form->id}/resend")->assertOk()->assertJsonPath('emailed', true)->json('accreditation_url');

        $this->assertNotSame($oldToken, basename($newUrl));
        $this->getJson("/api/accreditation/{$oldToken}")->assertNotFound();
        $this->getJson('/api/accreditation/'.basename($newUrl))->assertOk();
        Mail::assertSent(AccreditationInviteMail::class, fn (AccreditationInviteMail $m) => $m->url === $newUrl);
    }

    // ----- who may manage realties -----

    public function test_only_the_developers_admins_can_manage_realties(): void
    {
        $broker = $this->broker('ABC Realty');
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create();
        $johndorfAgent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->johndorf->id]);
        $brokerAdmin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $broker->id]);

        foreach ([$johndorfAgent, $brokerAdmin] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson('/api/realty/realties')->assertForbidden();
            $this->postJson('/api/realty/realties/invite', ['email' => 'x@example.com'])->assertForbidden();
            $this->getJson("/api/realty/realties/accreditations/{$form->id}")->assertForbidden();
            $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")->assertForbidden();
        }
        $this->assertSame('submitted', $form->fresh()->status);
    }

    public function test_another_developers_forms_are_not_found(): void
    {
        $other = Realty::create(['name' => 'Other Developer', 'slug' => 'other-dev', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $form = RealtyAccreditation::factory()->for($other, 'developer')->submitted()->create();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/realty/realties/accreditations/{$form->id}")->assertNotFound();
        $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")->assertNotFound();
        $this->postJson("/api/realty/realties/accreditations/{$form->id}/reject", ['note' => 'No.'])->assertNotFound();
        $this->getJson("/api/realty/realties/accreditations/{$form->id}/documents/1")->assertNotFound();
        $this->assertSame('submitted', $form->fresh()->status);
    }

    // ----- the public form -----

    public function test_the_form_link_shows_johndorfs_brand_and_the_document_rows(): void
    {
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->getJson("/api/accreditation/{$token}")
            ->assertOk()
            ->assertJsonPath('developer.name', 'Johndorf Ventures Corporation')
            ->assertJsonPath('developer.logo_url', '/johndorf/logo.png')
            ->assertJsonPath('email', 'broker@example.com')
            ->assertJsonCount(5, 'documents')
            ->assertJsonPath('documents.0.kind', 'board_resolution')
            ->assertJsonPath('documents.0.required_for', 'corporation')
            ->assertJsonPath('documents.3.required_for', 'sole_proprietor')
            ->assertJsonPath('documents.4.required_for', null)
            ->assertJsonPath('civil_statuses.1', 'Married');
    }

    public function test_an_unknown_form_link_returns_404(): void
    {
        $this->getJson('/api/accreditation/'.str_repeat('a', 48))->assertNotFound();
        $this->postJson('/api/accreditation/'.str_repeat('a', 48), [])->assertNotFound();
    }

    public function test_an_expired_form_link_returns_410_and_says_who_to_ask(): void
    {
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->expired()->create();
        [$expired, $token] = [$form, 'known-token'];
        $expired->update(['token_hash' => hash('sha256', $token)]);

        $this->getJson("/api/accreditation/{$token}")->assertStatus(410)->assertJsonPath('message', 'This link has expired. Ask Johndorf Ventures Corporation for a new one.');
        $this->post("/api/accreditation/{$token}", $this->answers(), ['Accept' => 'application/json'])->assertStatus(410);
    }

    public function test_a_corporation_sends_in_the_form_with_its_documents_and_it_waits_for_review(): void
    {
        Storage::fake(AccreditationDocument::disk());
        [$form, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->post("/api/accreditation/{$token}", $this->answers() + ['documents' => $this->corporationFiles()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $form = $form->fresh();
        $this->assertSame(RealtyAccreditation::STATUS_SUBMITTED, $form->status);
        $this->assertSame('ABC Realty', $form->firm_name);
        $this->assertSame('corporation', $form->business_type);
        $this->assertSame('123-456-789-000', $form->tin_company);
        $this->assertSame('09171234567', $form->mobile);
        $this->assertSame('1985-04-12', $form->date_of_birth->toDateString());
        $this->assertNotNull($form->submitted_at);
        $this->assertSame('127.0.0.1', $form->submitted_ip);
        $this->assertNotSame('123-456-789-000', $form->getRawOriginal('tin_company'), 'TINs are stored encrypted.');
        $this->assertNotSame('987-654-321-000', $form->getRawOriginal('tin_personal'), 'TINs are stored encrypted.');
        $this->assertSame(['board_resolution', 'sec_registration'], $form->documents()->orderBy('id')->pluck('kind')->all());
        $form->documents->each(fn (AccreditationDocument $d) => Storage::disk(AccreditationDocument::disk())->assertExists($d->path));
    }

    public function test_a_sole_proprietor_needs_the_dti_registration_and_no_company_tin(): void
    {
        Storage::fake(AccreditationDocument::disk());
        [$form, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        $answers = ['business_type' => 'sole_proprietor', 'tin_company' => null] + $this->answers();

        $this->post("/api/accreditation/{$token}", $answers, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.dti_registration' => 'Attach your DTI registration.'])
            ->assertJsonMissingValidationErrors(['tin_company', 'documents.board_resolution', 'documents.sec_registration']);

        $this->post("/api/accreditation/{$token}", $answers + ['documents' => ['dti_registration' => UploadedFile::fake()->create('dti.pdf', 50, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertNull($form->fresh()->tin_company);
        $this->assertSame(['dti_registration'], $form->documents()->pluck('kind')->all());
    }

    public function test_a_corporation_must_give_the_company_tin_and_both_registration_documents(): void
    {
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->post("/api/accreditation/{$token}", ['tin_company' => null] + $this->answers(), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tin_company' => "Enter the company's tax identification number.",
                'documents.board_resolution' => 'Attach your Board/Partnership Resolution.',
                'documents.sec_registration' => 'Attach your SEC registration documents.',
            ]);
    }

    public function test_an_empty_form_lists_every_required_answer(): void
    {
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->post("/api/accreditation/{$token}", [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'business_type', 'firm_name', 'residential_address', 'tin_personal', 'prc_number', 'prc_valid_until', 'representative_name',
                'place_of_birth', 'date_of_birth', 'citizenship', 'gender', 'civil_status', 'mobile', 'login_email',
                'years_in_real_estate', 'years_firm_operating', 'salespersons',
            ])
            ->assertJsonPath('errors.business_type.0', 'Choose Corporation or Sole Proprietor.')
            ->assertJsonPath('errors.gender.0', 'Choose Male or Female.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAnswers')]
    public function test_an_invalid_answer_is_refused_with_its_message(array $overrides, string $field, string $message): void
    {
        User::factory()->create(['email' => 'registered@example.com']);
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->post("/api/accreditation/{$token}", $overrides + $this->answers() + ['documents' => $this->corporationFiles()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'mobile that is not 09 plus nine digits' => [['mobile' => '0917123'], 'mobile', 'Enter an 11-digit mobile number that starts with 09, e.g. 09171234567.'],
            'representative under 18' => [['date_of_birth' => now()->subYears(17)->toDateString()], 'date_of_birth', 'The representative has to be at least 18 years old.'],
            'PRC registration already expired' => [['prc_valid_until' => now()->subDay()->toDateString()], 'prc_valid_until', 'The PRC registration has to be valid today or later.'],
            'HLURB number without its issue date' => [['hlurb_number' => 'HLURB-77', 'hlurb_issued_at' => null], 'hlurb_issued_at', 'Enter the date the HLURB registration was issued.'],
            'email that already has an account' => [['login_email' => 'registered@example.com'], 'login_email', 'There is already an account with this email.'],
            'gender that is neither choice' => [['gender' => 'other'], 'gender', 'The selected gender is invalid.'],
        ];
    }

    public function test_a_document_that_is_not_a_photo_or_pdf_is_refused(): void
    {
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        $files = ['board_resolution' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'), 'sec_registration' => UploadedFile::fake()->create('virus.exe', 50, 'application/x-msdownload')];

        $this->post("/api/accreditation/{$token}", $this->answers() + ['documents' => $files], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.sec_registration' => 'Attach a photo (JPG, PNG, HEIC) or a PDF.']);
    }

    public function test_a_document_over_10_mb_is_refused(): void
    {
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        $files = ['board_resolution' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'), 'sec_registration' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')];

        $this->post("/api/accreditation/{$token}", $this->answers() + ['documents' => $files], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.sec_registration' => 'Each file can be up to 10 MB.']);
    }

    public function test_a_form_can_only_be_sent_in_once(): void
    {
        Storage::fake(AccreditationDocument::disk());
        [, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $this->post("/api/accreditation/{$token}", $this->answers() + ['documents' => $this->corporationFiles()], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/accreditation/{$token}", ['firm_name' => 'Changed Name'] + $this->answers() + ['documents' => $this->corporationFiles()], ['Accept' => 'application/json'])
            ->assertStatus(410);
        $this->getJson("/api/accreditation/{$token}")->assertStatus(410);

        $this->assertSame('ABC Realty', RealtyAccreditation::first()->firm_name);
        $this->assertDatabaseCount('accreditation_documents', 2);
    }

    public function test_fields_a_realty_must_not_control_are_ignored(): void
    {
        Storage::fake(AccreditationDocument::disk());
        $other = Realty::create(['name' => 'Other Developer', 'slug' => 'other-dev', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        [$form, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        $sneaky = ['status' => 'approved', 'realty_id' => $other->id, 'developer_id' => $other->id, 'reviewed_by' => $this->admin->id, 'review_note' => 'Fine by me', 'email' => 'someone@else.com'];

        $this->post("/api/accreditation/{$token}", $sneaky + $this->answers() + ['documents' => $this->corporationFiles()], ['Accept' => 'application/json'])->assertCreated();

        $form = $form->fresh();
        $this->assertSame('submitted', $form->status);
        $this->assertSame($this->johndorf->id, $form->developer_id);
        $this->assertNull($form->realty_id);
        $this->assertNull($form->reviewed_by);
        $this->assertNull($form->review_note);
        $this->assertSame('broker@example.com', $form->email);
    }

    // ----- the review -----

    public function test_johndorfs_admin_sees_the_whole_picture_in_team_realties(): void
    {
        $broker = $this->broker('ABC Realty');
        $acceptedForm = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['realty_id' => $broker->id, 'status' => 'approved']);
        $pending = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => 'Pending Realty']);
        AccreditationDocument::factory()->for($pending, 'accreditation')->count(2)->create();
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->create(['email' => 'invited@example.com']);
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->rejected('Blurry scans')->create(['firm_name' => 'Rejected Realty']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/realty/realties')
            ->assertOk()
            ->assertJsonCount(1, 'pending')
            ->assertJsonPath('pending.0.firm_name', 'Pending Realty')
            ->assertJsonPath('pending.0.documents_count', 2)
            ->assertJsonCount(1, 'brokers')
            ->assertJsonPath('brokers.0.name', 'ABC Realty')
            ->assertJsonPath('brokers.0.accreditation_id', $acceptedForm->id)
            ->assertJsonPath('brokers.0.agents_count', 0)
            ->assertJsonCount(1, 'invited')
            ->assertJsonPath('invited.0.email', 'invited@example.com')
            ->assertJsonPath('invited.0.expired', false)
            ->assertJsonCount(1, 'rejected')
            ->assertJsonPath('rejected.0.review_note', 'Blurry scans');
    }

    public function test_the_review_page_shows_every_answer_with_the_government_numbers_readable(): void
    {
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => 'Pending Realty']);
        AccreditationDocument::factory()->for($form, 'accreditation')->create(['kind' => 'board_resolution', 'original_name' => 'resolution.pdf']);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/realty/realties/accreditations/{$form->id}")
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('details.firm_name', 'Pending Realty')
            ->assertJsonPath('details.tin_company', '123-456-789-000')
            ->assertJsonPath('details.tin_personal', '987-654-321-000')
            ->assertJsonPath('details.date_of_birth', '1985-04-12')
            ->assertJsonPath('documents.0.original_name', 'resolution.pdf')
            ->assertJsonPath('documents.0.label', AccreditationDocument::KINDS['board_resolution']['label'])
            ->assertJsonMissingPath('documents.0.path')
            ->assertJsonMissingPath('submitted_ip')
            ->assertJsonPath('realty', null);
    }

    public function test_an_invite_that_was_not_filled_in_has_nothing_to_review(): void
    {
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->create();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/realty/realties/accreditations/{$form->id}")->assertNotFound();
    }

    public function test_an_attached_file_opens_for_johndorfs_admin_and_cannot_run_as_a_page(): void
    {
        Storage::fake(AccreditationDocument::disk());
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create();
        $doc = AccreditationDocument::factory()->for($form, 'accreditation')->create(['path' => "accreditations/{$form->id}/sec.pdf"]);
        Storage::disk(AccreditationDocument::disk())->put($doc->path, '%PDF-1.4 fake');
        Sanctum::actingAs($this->admin);

        $this->get("/api/realty/realties/accreditations/{$form->id}/documents/{$doc->id}")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ----- accepting -----

    public function test_accepting_makes_the_broker_realty_and_its_admin_and_mails_the_temporary_password(): void
    {
        Mail::fake();
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => 'ABC Realty', 'login_email' => 'abby@abc.example', 'representative_name' => 'Abby Cruz', 'mobile' => '09179998888']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")
            ->assertCreated()
            ->assertJsonPath('realty.slug', 'abc-realty')
            ->assertJsonPath('username', 'abby@abc.example')
            ->assertJsonPath('login_url', 'https://jvconline.ph/abc-realty/login')
            ->assertJsonPath('emailed', true)
            ->assertJsonPath('temporary_password', null);

        $realty = Realty::where('slug', 'abc-realty')->firstOrFail();
        $this->assertSame('broker', $realty->kind);
        $this->assertSame($this->johndorf->id, $realty->developer_id);
        $this->assertSame('active', $realty->status);
        $this->assertSame('/johndorf/logo.png', $realty->logo_path);
        $this->assertSame('#b4241c', $realty->accent_color);
        $this->assertSame('Abby Cruz', $realty->contact_name);
        $this->assertDatabaseCount('requirement_types', 0);

        $admin = User::where('email', 'abby@abc.example')->firstOrFail();
        $this->assertSame(User::ROLE_REALTY, $admin->role);
        $this->assertSame($realty->id, $admin->realty_id);
        $this->assertSame('Abby Cruz', $admin->name);
        $this->assertTrue($admin->must_change_password);
        $this->assertSame('active', $admin->status);

        $form = $form->fresh();
        $this->assertSame('approved', $form->status);
        $this->assertSame($realty->id, $form->realty_id);
        $this->assertSame($this->admin->id, $form->reviewed_by);
        Mail::assertSent(AccreditationApprovedMail::class, fn (AccreditationApprovedMail $m) => $m->hasTo('abby@abc.example')
            && $m->loginUrl === 'https://jvconline.ph/abc-realty/login'
            && Hash::check($m->temporaryPassword, $admin->password));
    }

    public function test_accepting_gives_each_realty_a_unique_address_that_is_never_a_reserved_one(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $names = ['ABC Realty', 'ABC Realty', 'Projects', '!!!'];
        $slugs = [];

        foreach ($names as $name) {
            $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => $name]);
            $slugs[] = $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")->assertCreated()->json('realty.slug');
        }

        $this->assertSame(['abc-realty', 'abc-realty-2', 'projects-2', 'realty'], $slugs);
    }

    public function test_accepting_when_the_email_cannot_be_sent_still_makes_the_realty_and_hands_over_the_password(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP is down'));
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => 'ABC Realty', 'login_email' => 'abby@abc.example']);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")
            ->assertCreated()
            ->assertJsonPath('emailed', false);

        $password = $response->json('temporary_password');
        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, User::where('email', 'abby@abc.example')->firstOrFail()->password));
        $this->assertSame('approved', $form->fresh()->status);
    }

    public function test_accepting_a_form_whose_email_got_an_account_meanwhile_returns_422_and_changes_nothing(): void
    {
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['login_email' => 'abby@abc.example']);
        User::factory()->create(['email' => 'abby@abc.example']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login_email']);
        $this->assertSame('submitted', $form->fresh()->status);
        $this->assertSame(1, Realty::count());
    }

    public function test_a_form_that_is_not_waiting_for_review_cannot_be_decided_again(): void
    {
        Mail::fake();
        $submitted = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create();
        $invited = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/realties/accreditations/{$submitted->id}/approve")->assertCreated();
        $this->postJson("/api/realty/realties/accreditations/{$submitted->id}/approve")->assertConflict();
        $this->postJson("/api/realty/realties/accreditations/{$submitted->id}/reject", ['note' => 'Too late'])->assertConflict();
        $this->postJson("/api/realty/realties/accreditations/{$invited->id}/approve")->assertConflict();
        $this->assertSame(2, Realty::count());
    }

    public function test_rejecting_needs_a_reason_and_keeps_it_and_sends_no_email(): void
    {
        Mail::fake();
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/realties/accreditations/{$form->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'Say why, e.g. "The PRC registration is unreadable".');

        $this->postJson("/api/realty/realties/accreditations/{$form->id}/reject", ['note' => 'PRC registration is unreadable'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
        $form = $form->fresh();
        $this->assertSame('PRC registration is unreadable', $form->review_note);
        $this->assertSame($this->admin->id, $form->reviewed_by);
        $this->assertNull($form->realty_id);
        $this->assertSame(1, Realty::count());
        Mail::assertNothingSent();
    }

    // ----- the temporary password -----

    public function test_the_temporary_password_signs_in_at_the_brokers_address_and_the_dashboard_stays_shut_until_it_is_replaced(): void
    {
        Mail::fake();
        $password = $this->acceptedBroker('ABC Realty', 'abby@abc.example');

        $token = $this->postJson('/api/auth/login', ['email' => 'abby@abc.example', 'password' => $password, 'realty' => 'abc-realty'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.realty.kind', 'broker')
            ->json('token');

        $this->as($token)->getJson('/api/realty/overview')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->getJson('/api/realty/offers')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->as($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('must_change_password', true);

        $this->as($token)->postJson('/api/account/password', ['current_password' => $password, 'password' => 'My-new-pass-1', 'password_confirmation' => 'My-new-pass-1'])
            ->assertOk();

        $this->as($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('must_change_password', false);
        $this->as($token)->getJson('/api/realty/overview')->assertOk()->assertJsonPath('realty.slug', 'abc-realty');
        $this->postJson('/api/auth/login', ['email' => 'abby@abc.example', 'password' => $password, 'realty' => 'abc-realty'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'abby@abc.example', 'password' => 'My-new-pass-1', 'realty' => 'abc-realty'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidPasswordChanges')]
    public function test_a_password_change_is_refused_with_its_message(array $input, string $field, string $message): void
    {
        $user = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'password' => 'Current-pass-1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/account/password', $input)
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $message);
        $this->assertTrue(Hash::check('Current-pass-1', $user->fresh()->password));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidPasswordChanges(): array
    {
        return [
            'wrong current password' => [['current_password' => 'nope', 'password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1'], 'current_password', "That's not your current password."],
            'new password equal to the current one' => [['current_password' => 'Current-pass-1', 'password' => 'Current-pass-1', 'password_confirmation' => 'Current-pass-1'], 'password', 'The new password has to be different from the current one.'],
            'confirmation that does not match' => [['current_password' => 'Current-pass-1', 'password' => 'Another-pass-1', 'password_confirmation' => 'Different-pass-1'], 'password', "The two new passwords don't match."],
            'new password shorter than 8' => [['current_password' => 'Current-pass-1', 'password' => 'short', 'password_confirmation' => 'short'], 'password', 'The password field must be at least 8 characters.'],
            'missing current password' => [['password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1'], 'current_password', 'The current password field is required.'],
        ];
    }

    public function test_changing_the_password_ends_every_other_sign_in(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->johndorf->id, 'password' => 'Current-pass-1']);
        $thisSignIn = $user->createToken('web')->plainTextToken;
        $user->createToken('phone');

        $this->as($thisSignIn)->postJson('/api/account/password', ['current_password' => 'Current-pass-1', 'password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1'])->assertOk();

        $this->assertSame(['web'], $user->tokens()->pluck('name')->all());
    }

    public function test_resending_the_login_details_gives_a_new_password_until_the_realty_signs_in(): void
    {
        Mail::fake();
        $first = $this->acceptedBroker('ABC Realty', 'abby@abc.example');
        $form = RealtyAccreditation::where('login_email', 'abby@abc.example')->firstOrFail();
        $oldSignIn = $this->postJson('/api/auth/login', ['email' => 'abby@abc.example', 'password' => $first, 'realty' => 'abc-realty'])->json('token');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/realty/realties/accreditations/{$form->id}/resend-login")->assertOk()->assertJsonPath('emailed', true);

        $second = null;
        Mail::assertSent(AccreditationApprovedMail::class, function (AccreditationApprovedMail $m) use ($first, &$second) {
            $second = $m->temporaryPassword !== $first ? $m->temporaryPassword : $second;

            return true;
        });
        $this->assertNotNull($second);
        $this->assertFalse(Hash::check($first, User::where('email', 'abby@abc.example')->firstOrFail()->password));
        $this->assertTrue(Hash::check($second, User::where('email', 'abby@abc.example')->firstOrFail()->password));
        $this->as($oldSignIn)->getJson('/api/auth/me')->assertUnauthorized();

        User::where('email', 'abby@abc.example')->firstOrFail()->forceFill(['must_change_password' => false])->save();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/realties/accreditations/{$form->id}/resend-login")->assertConflict();
    }

    public function test_the_sidebar_counts_forms_waiting_for_review_for_johndorfs_admins_only(): void
    {
        $broker = $this->broker('ABC Realty');
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->count(2)->create();
        RealtyAccreditation::factory()->for($this->johndorf, 'developer')->create();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/realty/overview')->assertJsonPath('stats.realties_pending', 2);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $broker->id]));
        $this->getJson('/api/realty/overview')->assertJsonPath('stats.realties_pending', 0);
    }

    // ----- the accepted realty at work -----

    public function test_an_accepted_realty_invites_its_agents_who_then_make_offers_on_johndorfs_units(): void
    {
        Mail::fake();
        $password = $this->acceptedBroker('ABC Realty', 'abby@abc.example');
        $brokerAdmin = $this->postJson('/api/auth/login', ['email' => 'abby@abc.example', 'password' => $password, 'realty' => 'abc-realty'])->json('token');
        $this->as($brokerAdmin)->postJson('/api/account/password', ['current_password' => $password, 'password' => 'My-new-pass-1', 'password_confirmation' => 'My-new-pass-1'])->assertOk();

        $joinUrl = $this->as($brokerAdmin)->postJson('/api/realty/agents', ['name' => 'Ana Cruz', 'phone' => '09171234567'])->assertCreated()->json('join_url');
        $this->postJson('/api/join/'.basename($joinUrl), ['name' => 'Ana Cruz', 'email' => 'ana@abc.example', 'phone' => '09171234567', 'password' => 'Agent-pass-1', 'password_confirmation' => 'Agent-pass-1'])->assertCreated();
        $agent = User::where('email', 'ana@abc.example')->firstOrFail();
        $this->assertSame('pending', $agent->status);
        $this->as($brokerAdmin)->postJson("/api/realty/agents/{$agent->id}/approve")->assertOk();

        $project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Montierra', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $project->id, 'name' => 'Lot 1', 'category' => 'Residential', 'price' => 2800000, 'status' => 'available']);
        $agentToken = $this->postJson('/api/auth/login', ['email' => 'ana@abc.example', 'password' => 'Agent-pass-1', 'realty' => 'abc-realty'])->assertOk()->json('token');

        $this->as($agentToken)->getJson('/api/realty/projects')->assertOk()->assertJsonPath('0.name', 'Montierra');
        $offerId = $this->as($agentToken)->postJson('/api/realty/offers', [
            'unit_id' => $unit->id, 'buyer_name' => 'Juliecor Repompo', 'purchase_date' => now()->toDateString(), 'access_username' => 'Juliecor', 'access_password' => 'Repompo',
        ])->assertCreated()->json('id');

        $offer = Offer::findOrFail($offerId);
        $this->assertSame($this->johndorf->id, $offer->realty_id);
        $this->assertSame(Realty::where('slug', 'abc-realty')->value('id'), $offer->broker_realty_id);
        $this->assertSame($agent->id, $offer->agent_id);
    }

    public function test_the_platform_admin_sees_a_broker_with_the_offers_it_sold(): void
    {
        $broker = $this->broker('ABC Realty');
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $broker->id]);
        $project = Project::create(['realty_id' => $this->johndorf->id, 'name' => 'Montierra', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->johndorf->id, 'project_id' => $project->id, 'name' => 'Lot 1', 'category' => 'Residential', 'price' => 1, 'status' => 'available']);
        Offer::create([
            'realty_id' => $this->johndorf->id, 'broker_realty_id' => $broker->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'code' => Offer::newCode(), 'buyer_name' => 'Buyer', 'purchase_date' => now()->toDateString(), 'price' => 1, 'schedule' => [], 'status' => 'active',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'realty_id' => null]));

        $this->getJson('/api/admin/realties')->assertOk()->assertJsonFragment(['slug' => 'johndorf', 'kind' => 'developer'])->assertJsonFragment(['slug' => 'abc-realty', 'kind' => 'broker']);
        $this->getJson("/api/admin/realties/{$broker->id}")
            ->assertOk()
            ->assertJsonPath('realty.kind', 'broker')
            ->assertJsonPath('realty.developer.name', 'Johndorf Ventures Corporation')
            ->assertJsonPath('realty.offers_count', 1)
            ->assertJsonCount(1, 'offers')
            ->assertJsonCount(0, 'projects');
    }

    // ----- the emails -----

    public function test_the_invite_email_wears_johndorfs_brand_and_links_the_form(): void
    {
        [$form, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);
        $url = RealtyAccreditation::url($token);

        $mail = new AccreditationInviteMail($form->load('developer', 'inviter'), $url);

        $mail->assertHasSubject('Johndorf Ventures Corporation invites you to get accredited');
        $mail->assertFrom(config('mail.from.address'), 'Johndorf Ventures Corporation');
        $mail->assertSeeInHtml('<!DOCTYPE html>', false);
        $mail->assertSeeInHtml('src="https://jvconline.ph/johndorf/logo.png"', false);
        $mail->assertSeeInHtml('background-color:#b4241c', false);
        $mail->assertSeeInHtml('Realty accreditation');
        $mail->assertSeeInHtml($url, false);
        $mail->assertSeeInHtml('Open the accreditation form');
        $mail->assertSeeInHtml($form->expires_at->timezone('Asia/Manila')->format('F j, Y'));
        $mail->assertSeeInText($url);
        $mail->assertDontSeeInText('<table');
    }

    public function test_the_login_email_shows_the_username_and_the_temporary_password(): void
    {
        $realty = $this->broker('ABC Realty');
        $admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $realty->id, 'name' => 'Abby Cruz', 'email' => 'abby@abc.example']);

        $mail = new AccreditationApprovedMail($realty->load('developer'), $admin, 'Tmp8Pass3Word', 'https://jvconline.ph/abc-realty/login');

        $mail->assertHasSubject("You're accredited with Johndorf Ventures Corporation: your login");
        $mail->assertSeeInHtml('Welcome aboard, Abby');
        $mail->assertSeeInHtml('abby@abc.example');
        $mail->assertSeeInHtml('Tmp8Pass3Word');
        $mail->assertSeeInHtml('jvconline.ph/abc-realty/login');
        $mail->assertSeeInHtml('choose your own password the first time you sign in');
        $mail->assertSeeInText('Temporary password: Tmp8Pass3Word');
        $mail->assertSeeInText('Username: abby@abc.example');
    }

    public function test_the_emails_escape_names_so_no_markup_runs(): void
    {
        $evil = "<script>alert('x')</script> & <b>Realty</b>";
        $realty = $this->broker($evil);
        $admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $realty->id, 'name' => $evil, 'email' => 'abby@abc.example']);

        $mail = new AccreditationApprovedMail($realty->load('developer'), $admin, 'Tmp8Pass3Word', 'https://jvconline.ph/abc-realty/login');

        $mail->assertDontSeeInHtml("<script>alert('x')</script>", false);
        $mail->assertDontSeeInHtml('<b>Realty</b>', false);
        $mail->assertSeeInHtml('&lt;script&gt;', false);
    }

    public function test_an_email_falls_back_to_johndorf_red_when_the_accent_colour_is_not_a_hex_colour(): void
    {
        $this->johndorf->update(['accent_color' => 'red;background:url(x)']);
        [$form, $token] = RealtyAccreditation::issue($this->johndorf, 'broker@example.com', $this->admin);

        $mail = new AccreditationInviteMail($form->load('developer'), RealtyAccreditation::url($token));

        $mail->assertDontSeeInHtml('background:url(x)', false);
        $mail->assertSeeInHtml('background-color:#b4241c', false);
    }

    // ----- helpers -----

    private function broker(string $name): Realty
    {
        return Realty::create([
            'name' => $name, 'slug' => Realty::slugFor($name), 'kind' => Realty::KIND_BROKER, 'developer_id' => $this->johndorf->id,
            'status' => Realty::STATUS_ACTIVE, 'registered_at' => now(),
        ]);
    }

    /** Accepts a submitted form through the API and returns the temporary password that was mailed. */
    private function acceptedBroker(string $firm, string $email): string
    {
        $form = RealtyAccreditation::factory()->for($this->johndorf, 'developer')->submitted()->create(['firm_name' => $firm, 'login_email' => $email]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/realty/realties/accreditations/{$form->id}/approve")->assertCreated();

        $password = null;
        Mail::assertSent(AccreditationApprovedMail::class, function (AccreditationApprovedMail $m) use ($email, &$password) {
            $password = $m->hasTo($email) ? $m->temporaryPassword : $password;

            return true;
        });
        $this->app['auth']->forgetGuards();

        return $password;
    }

    /** A request signed in with a real token (a second request in one test would reuse the first one's user otherwise). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        return [
            'business_type' => 'corporation',
            'firm_name' => 'ABC Realty',
            'residential_address' => '12 Colon Street, Cebu City',
            'tin_company' => '123-456-789-000',
            'tin_personal' => '987-654-321-000',
            'prc_number' => 'PRC-100200',
            'prc_valid_until' => now()->addYear()->toDateString(),
            'hlurb_number' => null,
            'hlurb_issued_at' => null,
            'representative_name' => 'Abby Cruz',
            'place_of_birth' => 'Cebu City',
            'date_of_birth' => '1985-04-12',
            'citizenship' => 'Filipino',
            'gender' => 'female',
            'civil_status' => 'Married',
            'landline' => null,
            'mobile' => '09171234567',
            'login_email' => 'abby@abc.example',
            'facebook' => null,
            'years_in_real_estate' => 8,
            'years_firm_operating' => 5,
            'salespersons' => 12,
        ];
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function corporationFiles(): array
    {
        return [
            'board_resolution' => UploadedFile::fake()->create('resolution.pdf', 120, 'application/pdf'),
            'sec_registration' => UploadedFile::fake()->create('sec.pdf', 200, 'application/pdf'),
        ];
    }
}
