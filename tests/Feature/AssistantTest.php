<?php

namespace Tests\Feature;

use App\Models\AssistantChat;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\PaymentPlan;
use App\Models\Project;
use App\Models\Realty;
use App\Models\RequirementType;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\RealtyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The dashboard's AI assistant: answers from the realty's own data, keeps each person's chats, never leaks buyers' contacts. */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $admin;

    private User $agent;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-test']);
        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => 'Johndorf Admin']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Plumera Mactan', 'status' => 'active']);
        $this->unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Bldg S · Unit 123', 'unit_type' => 'Studio Unit', 'category' => 'Residential', 'price' => 3543000, 'status' => 'available']);
    }

    public function test_a_question_is_answered_from_the_tools_and_saved_as_a_chat(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_projects', 'arguments' => '{"query":"Plumera"}']]]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '**Plumera Mactan has 1 available unit.**']]]]);
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/realty/assistant/messages', ['message' => 'How many units are left in Plumera?'])
            ->assertOk()
            ->assertJsonPath('message.content', '**Plumera Mactan has 1 available unit.**')
            ->assertJsonPath('chat.title', 'How many units are left in Plumera?');

        // The second call carried the tool's answer, from this realty's data.
        Http::assertSent(function (HttpRequest $request) {
            $tool = collect($request['messages'])->firstWhere('role', 'tool');

            return $tool && str_contains($tool['content'], '"available":1') && str_contains($tool['content'], 'Plumera Mactan');
        });
        $chat = AssistantChat::findOrFail($response->json('chat.id'));
        $this->assertSame(['user', 'assistant'], $chat->messages()->pluck('role')->all());

        $this->getJson('/api/realty/assistant/chats')->assertJsonPath('name', 'Johndorf AI')->assertJsonCount(1, 'chats');
    }

    public function test_the_answer_streams_word_by_word_and_is_saved_when_complete(): void
    {
        $sse = fn (array ...$deltas) => implode('', array_map(fn (array $d) => 'data: '.json_encode(['choices' => [['delta' => $d]]])."\n\n", $deltas))."data: [DONE]\n\n";
        Http::fakeSequence('api.openai.com/*')
            ->push($sse(
                ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_projects', 'arguments' => '{"que']]]],
                ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ry":"Plumera"}']]]],
            ))
            ->push($sse(['content' => '**Plumera Mactan'], ['content' => ' has 1 available unit.**']));
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/realty/assistant/stream', ['message' => 'Units in Plumera?'])->assertOk();
        $events = $response->streamedContent();

        $this->assertStringContainsString("event: status\ndata: {\"tool\":\"list_projects\"}", $events);
        $this->assertStringContainsString("event: delta\ndata: {\"text\":\"**Plumera Mactan\"}", $events);
        $this->assertStringContainsString("event: delta\ndata: {\"text\":\" has 1 available unit.**\"}", $events);
        $this->assertStringContainsString('event: done', $events);
        // The tool ran with the arguments that came in two pieces.
        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r['messages']), 'Plumera Mactan') && collect($r['messages'])->contains('role', 'tool'));
        $this->assertSame('**Plumera Mactan has 1 available unit.**', AssistantChat::firstOrFail()->messages()->where('role', 'assistant')->value('content'));
    }

    public function test_a_streamed_answer_that_fails_ends_with_an_error_and_saves_nothing(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        Sanctum::actingAs($this->admin);

        $events = $this->postJson('/api/realty/assistant/stream', ['message' => 'Hello'])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: error', $events);
        $this->assertStringContainsString('run out of credit', $events);
        $this->assertSame(0, AssistantChat::count());
    }

    public function test_follow_up_questions_send_the_earlier_conversation(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Sure.']]]])]);
        Sanctum::actingAs($this->admin);

        $chatId = $this->postJson('/api/realty/assistant/messages', ['message' => 'First question'])->json('chat.id');
        $this->postJson('/api/realty/assistant/messages', ['chat_id' => $chatId, 'message' => 'And a follow-up'])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => collect($r['messages'])->pluck('content')->filter()->slice(1)->values()->all() === ['First question', 'Sure.', 'And a follow-up']);
        $this->assertSame(4, AssistantChat::findOrFail($chatId)->messages()->count());
    }

    public function test_agents_only_see_their_own_offers_and_buyers_contacts_never_go_out(): void
    {
        $mine = $this->offer($this->agent, 'Juliecor Repompo');
        $this->offer($this->admin, 'Someone Else');
        OfferResponse::create(['offer_id' => $mine->id, 'realty_id' => $this->realty->id, 'kind' => 'question', 'name' => 'Juliecor Repompo', 'phone' => '09171234567', 'email' => 'buyer@example.com', 'message' => 'Call me at 0917 123 4567 or buyer@example.com']);

        $tools = new RealtyTools($this->agent);
        $offers = $tools->call('list_offers', ['status' => 'all']);
        $this->assertSame(['Juliecor Repompo'], array_column($offers['offers'], 'buyer'));

        $detail = json_encode($tools->call('offer_details', ['offer' => $mine->code]));
        $this->assertStringNotContainsString('0917', $detail);
        $this->assertStringNotContainsString('buyer@example.com', $detail);
        $this->assertStringContainsString('[number hidden]', $detail);
        $this->assertSame(['error' => "Only the realty's admins can see the team."], $tools->call('list_agents', []));
        $this->assertSame(['error' => 'No offer found for "Someone Else" among your offers.'], $tools->call('offer_details', ['offer' => 'Someone Else']));
    }

    public function test_offers_name_what_the_buyer_still_has_to_send_and_agents_are_counted(): void
    {
        $waiting = $this->offer($this->agent, 'Juliecor Repompo');
        $done = $this->offer($this->agent, 'Maria Buyer');
        $done->forceFill(['details_submitted_at' => now(), 'buyer_details' => ['income_source' => 'employed']])->save();
        RequirementType::seedDefaults($this->realty);
        User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'status' => User::STATUS_PENDING]);

        $tools = new RealtyTools($this->admin);
        $rows = collect($tools->call('list_offers', ['status' => 'all'])['offers'])->keyBy('buyer');
        $this->assertSame('Buyer information form', $rows['Juliecor Repompo']['still_missing'][0]);
        $this->assertNotContains('Buyer information form', $rows['Maria Buyer']['still_missing']);

        $team = $tools->call('list_agents', []);
        $this->assertSame(1, $team['active_agents']);
        $this->assertSame(1, $team['applications_waiting_for_approval']);
    }

    public function test_without_a_key_or_when_openai_fails_nothing_is_saved(): void
    {
        Sanctum::actingAs($this->admin);

        config(['services.openai.key' => null]);
        $this->postJson('/api/realty/assistant/messages', ['message' => 'Hello'])->assertStatus(503);

        config(['services.openai.key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $this->postJson('/api/realty/assistant/messages', ['message' => 'Hello'])->assertStatus(503)->assertJsonPath('message', 'The AI is busy or the OpenAI account has run out of credit. Try again in a minute.');

        $this->assertSame(0, AssistantChat::count());
    }

    public function test_a_chat_is_only_for_the_person_who_started_it(): void
    {
        $chat = AssistantChat::create(['user_id' => $this->admin->id, 'realty_id' => $this->realty->id, 'title' => 'Mine']);
        Sanctum::actingAs($this->agent);

        $this->getJson("/api/realty/assistant/chats/{$chat->id}")->assertNotFound();
        $this->deleteJson("/api/realty/assistant/chats/{$chat->id}")->assertNotFound();
        $this->postJson('/api/realty/assistant/messages', ['chat_id' => $chat->id, 'message' => 'Hi'])->assertNotFound();
    }

    public function test_units_shown_as_cards_stream_with_the_answer_and_stay_with_the_chat(): void
    {
        $sold = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $this->unit->project_id, 'name' => 'Bldg S · Unit 124', 'unit_type' => 'Studio Unit', 'price' => 3600000, 'status' => 'sold']);
        $elsewhere = Realty::create(['name' => 'Other Realty', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $theirs = Unit::create(['realty_id' => $elsewhere->id, 'project_id' => Project::create(['realty_id' => $elsewhere->id, 'name' => 'Theirs', 'status' => 'active'])->id, 'name' => 'Not yours', 'price' => 1, 'status' => 'available']);
        $sse = fn (array ...$deltas) => implode('', array_map(fn (array $d) => 'data: '.json_encode(['choices' => [['delta' => $d]]])."\n\n", $deltas))."data: [DONE]\n\n";
        $call = fn (string $name, array $args) => ['tool_calls' => [['index' => 0, 'id' => "call_{$name}", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]]];
        Http::fakeSequence('api.openai.com/*')
            ->push($sse($call('search_units', ['project' => 'Plumera'])))
            ->push($sse($call('show_units', ['unit_ids' => [$this->unit->id, $theirs->id, $sold->id]])))
            ->push($sse(['content' => 'Here are **2 studios** in Plumera Mactan.']))
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'The first one is available.']]]]);
        Sanctum::actingAs($this->agent);

        $events = $this->postJson('/api/realty/assistant/stream', ['message' => 'Show me studios in Plumera'])->assertOk()->streamedContent();

        // search_units hands the model ids to show; the other realty's unit is never shown.
        Http::assertSent(function (HttpRequest $r) {
            $found = collect($r['messages'])->where('role', 'tool')->map(fn (array $m) => json_decode($m['content'], true))->firstWhere('units');

            return $found && $found['units'][0]['id'] === $this->unit->id && $found['units'][0]['name'] === 'Bldg S · Unit 123';
        });
        $this->assertStringContainsString('event: cards', $events);
        $this->assertStringNotContainsString('Not yours', $events);
        $chat = AssistantChat::firstOrFail();
        $this->assertSame([$this->unit->id, $sold->id], $chat->messages()->where('role', 'assistant')->firstOrFail()->unitIds());

        $cards = $this->getJson("/api/realty/assistant/chats/{$chat->id}")->assertOk()->json('messages.1.cards');
        $this->assertSame(['Bldg S · Unit 123', 'Bldg S · Unit 124'], array_column($cards, 'name'));
        $this->assertSame([true, false], array_column($cards, 'can_offer'));
        $this->assertSame('Plumera Mactan', $cards[0]['project']['name']);

        // The cards come from the live units: once it's sold, no more Make offer.
        $this->unit->update(['status' => 'sold']);
        $this->assertFalse($this->getJson("/api/realty/assistant/chats/{$chat->id}")->json('messages.1.cards.0.can_offer'));

        // The next question knows which units were on the cards.
        $this->postJson('/api/realty/assistant/messages', ['chat_id' => $chat->id, 'message' => 'Is the first one still available?'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r['messages'], JSON_UNESCAPED_UNICODE), 'these units were shown as cards, in order: Bldg S · Unit 123 (Plumera Mactan, id '.$this->unit->id.')'));
    }

    public function test_offer_details_give_the_buyer_link_for_messages_but_never_the_login(): void
    {
        $offer = $this->offer($this->agent, 'Juliecor Repompo');
        $offer->forceFill(['access_username' => 'jrepompo-login', 'access_password' => 'S3cret-Pass'])->save();

        $detail = (new RealtyTools($this->agent))->call('offer_details', ['offer' => $offer->code]);

        $this->assertSame(Offer::url($offer->code), $detail['buyer_link']);
        $this->assertTrue($detail['buyer_has_to_sign_in']);
        $this->assertStringNotContainsString('jrepompo-login', json_encode($detail));
        $this->assertStringNotContainsString('S3cret-Pass', json_encode($detail));

        $offer->forceFill(['approval_status' => 'pending'])->save();
        $this->assertStringStartsWith('not usable yet', (new RealtyTools($this->agent))->call('offer_details', ['offer' => $offer->code])['buyer_link']);
    }

    public function test_attention_today_lists_what_needs_doing_for_each_person(): void
    {
        RequirementType::seedDefaults($this->realty);
        $answered = $this->offer($this->agent, 'Ana Answered');
        OfferResponse::create(['offer_id' => $answered->id, 'realty_id' => $this->realty->id, 'kind' => 'interested', 'name' => 'Ana Answered', 'message' => 'Text me at 0917 123 4567']);
        $files = $this->offer($this->agent, 'Fe Files');
        OfferDocument::create(['offer_id' => $files->id, 'realty_id' => $this->realty->id, 'path' => 'x.pdf', 'original_name' => 'id.pdf', 'mime' => 'application/pdf', 'size' => 10, 'status' => 'pending']);
        $this->offer($this->agent, 'Terry Terms')->forceFill(['approval_status' => 'pending'])->save();
        $this->offer($this->agent, 'Vic Views')->forceFill(['views' => 8, 'last_viewed_at' => now()->subDay()])->save();
        $quiet = $this->offer($this->admin, 'Uma Unopened');
        $quiet->forceFill(['created_at' => now()->subDays(4)])->save();
        $this->unit->update(['status' => 'reserved', 'status_offer_id' => $answered->id, 'status_at' => now()]);
        User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'status' => User::STATUS_PENDING, 'name' => 'Paolo Pending']);

        $admin = (new RealtyTools($this->admin))->call('attention_today', []);
        $this->assertSame(['Ana Answered'], array_column($admin['new_buyer_answers_not_opened_yet']['items'], 'buyer'));
        $this->assertStringContainsString('[number hidden]', $admin['new_buyer_answers_not_opened_yet']['items'][0]['message']);
        $this->assertSame(['Fe Files'], array_column($admin['buyer_files_to_review']['items'], 'buyer'));
        $this->assertSame(['Terry Terms'], array_column($admin['custom_terms_waiting_for_your_approval']['items'], 'buyer'));
        $this->assertSame(['Ana Answered'], array_column($admin['interested_buyers_still_missing_requirements']['items'], 'buyer'));
        $this->assertSame(['Vic Views'], array_column($admin['opened_often_but_no_answer_yet']['items'], 'buyer'));
        $this->assertSame(['Uma Unopened'], array_column($admin['links_not_opened_3_days_after_sending']['items'], 'buyer'));
        $this->assertSame(['Paolo Pending'], array_column($admin['agent_applications_waiting']['items'], 'name'));
        $this->assertSame('Ana Answered', $admin['good_news_reserved_or_sold_last_7_days']['items'][0]['buyer']);
        $this->assertSame(7, $admin['total_things_to_do']);

        // An agent sees only their own offers, and nothing that's the admins' to do.
        $agent = (new RealtyTools($this->agent))->call('attention_today', []);
        $this->assertArrayNotHasKey('custom_terms_waiting_for_your_approval', $agent);
        $this->assertArrayNotHasKey('agent_applications_waiting', $agent);
        $this->assertSame(0, $agent['links_not_opened_3_days_after_sending']['count']);
        $this->assertSame(['Ana Answered', 'Fe Files', 'Ana Answered', 'Vic Views'], [
            ...array_column($agent['new_buyer_answers_not_opened_yet']['items'], 'buyer'),
            ...array_column($agent['buyer_files_to_review']['items'], 'buyer'),
            ...array_column($agent['interested_buyers_still_missing_requirements']['items'], 'buyer'),
            ...array_column($agent['opened_often_but_no_answer_yet']['items'], 'buyer'),
        ]);
        $this->assertSame(4, $agent['total_things_to_do']);

        Sanctum::actingAs($this->agent);
        $this->getJson('/api/realty/assistant/chats')->assertJsonPath('attention', 4);
    }

    public function test_payments_are_worked_out_like_the_offers_for_a_plan_or_custom_terms(): void
    {
        $project = $this->unit->project;
        $project->update(['completion_date' => '2028-12-31']);
        $milestones = [
            ['label' => 'Reservation', 'percent' => 10, 'days' => 0, 'months' => null],
            ['label' => 'Down payment', 'percent' => 20, 'days' => 30, 'months' => 12],
            ['label' => 'Balance', 'percent' => 70, 'days' => null, 'months' => null],
        ];
        PaymentPlan::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Standard 10-20-70', 'milestones' => $milestones]);
        $tools = new RealtyTools($this->agent);

        $plan = $tools->call('compute_payments', ['unit' => 'Bldg S · Unit 123', 'plan' => 'standard', 'purchase_date' => '2026-10-08']);
        $expected = Offer::buildSchedule(3543000.0, $milestones, now()->setDate(2026, 10, 8), $project->completion_date);
        $this->assertSame(array_column($expected, 'amount'), array_column($plan['payments'], 'amount'));
        $this->assertSame(['payments' => 12, 'each' => 59050.0, 'from' => 'Nov 7, 2026', 'to' => 'Oct 7, 2027', 'last_payment' => 59050.0], $plan['payments'][1]['monthly']);
        $this->assertSame('Dec 31, 2028', $plan['payments'][2]['due']);
        $this->assertSame(3543000.0, $plan['total']);

        // A fixed reservation fee, 20% over 24 months, and whatever is left on turnover.
        $custom = $tools->call('compute_payments', ['unit' => 'Unit 123', 'terms' => [
            ['label' => 'Reservation fee', 'amount' => 20000, 'due' => 'on_purchase'],
            ['label' => 'Down payment', 'percent' => 20, 'due' => 'days_after_purchase', 'days' => 30, 'months' => 24],
            ['label' => 'Balance', 'rest' => true, 'due' => 'on_turnover'],
        ]]);
        $this->assertSame([20000.0, 708600.0, 2814400.0], array_column($custom['payments'], 'amount'));
        $this->assertSame('custom terms', $custom['terms']);

        $this->assertStringContainsString('need to make 100%', $tools->call('compute_payments', ['price' => 1000000, 'terms' => [['label' => 'Down', 'percent' => 30, 'due' => 'on_purchase']]])['error']);
        $this->assertStringContainsString('Standard 10-20-70', $tools->call('compute_payments', ['unit' => 'Unit 123'])['error']);
    }

    public function test_a_question_from_a_dashboard_page_knows_the_page_but_only_what_the_person_may_see(): void
    {
        $theirs = $this->offer($this->admin, 'Someone Else');
        $mine = $this->offer($this->agent, 'Juliecor Repompo');
        $agentTools = new RealtyTools($this->agent);

        $this->assertSame('the page of the project Plumera Mactan', $agentTools->describePage("/johndorf/dashboard/projects/{$this->unit->project_id}"));
        $this->assertStringContainsString('Juliecor Repompo', $agentTools->describePage("/johndorf/dashboard/offers/{$mine->id}"));
        $this->assertNull($agentTools->describePage("/johndorf/dashboard/offers/{$theirs->id}"));
        $this->assertNull($agentTools->describePage('/somewhere/else'));

        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Sure.']]]])]);
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/realty/assistant/messages', ['message' => "What's left here?", 'page' => "/johndorf/dashboard/projects/{$this->unit->project_id}"])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => collect($r['messages'])->contains(fn (array $m) => $m['role'] === 'system' && str_contains($m['content'], 'asking from the page of the project Plumera Mactan')));
    }

    public function test_a_spoken_question_comes_back_as_text(): void
    {
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' How many units are left in Plumera? '])]);
        Sanctum::actingAs($this->agent);

        $this->post('/api/realty/assistant/transcribe', ['audio' => UploadedFile::fake()->create('question.webm', 40, 'audio/webm')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertExactJson(['text' => 'How many units are left in Plumera?']);

        // The realty's project names go along so they come out spelled right.
        Http::assertSent(fn (HttpRequest $r) => str_contains(collect($r->data())->firstWhere('name', 'prompt')['contents'] ?? '', 'Plumera Mactan')
            && (collect($r->data())->firstWhere('name', 'model')['contents'] ?? null) === config('services.openai.transcribe_model'));
        $this->post('/api/realty/assistant/transcribe', ['audio' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    private function offer(User $by, string $buyer): Offer
    {
        return Offer::create([
            'realty_id' => $this->realty->id,
            'project_id' => $this->unit->project_id,
            'unit_id' => $this->unit->id,
            'agent_id' => $by->id,
            'code' => Offer::newCode(),
            'buyer_name' => $buyer,
            'purchase_date' => now()->toDateString(),
            'price' => 3543000,
            'schedule' => [],
            'status' => 'active',
        ]);
    }
}
