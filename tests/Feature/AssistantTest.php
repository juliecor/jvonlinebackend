<?php

namespace Tests\Feature;

use App\Models\AssistantChat;
use App\Models\Offer;
use App\Models\OfferResponse;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\RealtyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
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
