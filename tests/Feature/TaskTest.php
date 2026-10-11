<?php

namespace Tests\Feature;

use App\Mail\TaskAssignedMail;
use App\Models\Offer;
use App\Models\Project;
use App\Models\Realty;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Admins give tasks to staff and agents; each person works their own; files stay private. */
class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Realty $realty;

    private User $admin;

    private User $agent;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(config('filesystems.documents'));
        $this->realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $this->admin = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'name' => 'Johndorf Admin']);
        $this->agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id, 'name' => 'Ana Agent', 'email' => 'ana@example.com']);
        $project = Project::create(['realty_id' => $this->realty->id, 'name' => 'Plumera Mactan', 'status' => 'active']);
        $unit = Unit::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'name' => 'Unit 1', 'price' => 1000000, 'status' => 'available']);
        $this->offer = Offer::create(['realty_id' => $this->realty->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $this->agent->id, 'code' => 'TASKOFFER1', 'buyer_name' => 'Juliecor Repompo', 'purchase_date' => now()->toDateString(), 'price' => 1000000, 'schedule' => [], 'status' => 'active']);
    }

    public function test_an_admin_gives_an_agent_a_task_and_the_agent_works_it(): void
    {
        $id = $this->as($this->admin, 'post', '/api/realty/tasks', [
            'title' => 'Collect Juliecor\'s payslip', 'description' => 'She said Friday.', 'assignee_id' => $this->agent->id,
            'due_on' => now()->addDays(2)->toDateString(), 'priority' => 'urgent', 'offer_id' => $this->offer->id,
        ])->assertCreated()->assertJsonPath('link.label', 'Juliecor Repompo (TASKOFFER1)')->assertJsonPath('assignee.role', 'agent')->json('id');
        Mail::assertSent(TaskAssignedMail::class, fn (TaskAssignedMail $m) => $m->hasTo('ana@example.com') && str_contains($m->url, "/johndorf/dashboard/tasks?task={$id}"));

        $this->as($this->agent, 'get', '/api/realty/tasks')->assertJsonPath('manager', false)->assertJsonPath('tasks.0.title', "Collect Juliecor's payslip");
        $this->as($this->agent, 'post', "/api/realty/tasks/{$id}/status", ['status' => 'doing'])->assertOk()->assertJsonPath('status', 'doing');
        $this->as($this->agent, 'post', "/api/realty/tasks/{$id}/comments", ['body' => 'Called her, sending Friday.'])->assertCreated();
        $file = $this->as($this->agent, 'post', "/api/realty/tasks/{$id}/files", ['file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf')])->assertCreated()->json('id');
        $this->as($this->agent, 'get', "/api/realty/tasks/{$id}/files/{$file}")->assertOk()->assertHeader('Content-Security-Policy', 'sandbox');
        $this->as($this->agent, 'post', "/api/realty/tasks/{$id}/status", ['status' => 'done'])->assertJsonPath('status', 'done');
        $this->assertNotNull(Task::find($id)->completed_at);

        // The agent works the task but doesn't edit or delete it.
        $this->as($this->agent, 'post', "/api/realty/tasks/{$id}", ['title' => 'Mine now', 'priority' => 'normal'])->assertForbidden();
        $this->as($this->agent, 'delete', "/api/realty/tasks/{$id}")->assertForbidden();

        $detail = $this->as($this->admin, 'get', "/api/realty/tasks/{$id}")->assertOk();
        $this->assertSame(['Called her, sending Friday.'], array_column($detail->json('comments'), 'body'));
        $this->assertSame(['payslip.pdf'], array_column($detail->json('files'), 'name'));

        $path = Task::find($id)->files()->value('path');
        $this->as($this->admin, 'delete', "/api/realty/tasks/{$id}")->assertNoContent();
        Storage::disk(config('filesystems.documents'))->assertMissing($path);
    }

    public function test_people_only_see_their_own_tasks_and_only_managers_give_them_out(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $this->realty->id]);
        $clerk = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'permissions' => ['offers']]);
        $planner = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'permissions' => ['tasks']]);
        $id = $this->as($this->admin, 'post', '/api/realty/tasks', ['title' => 'For Ana', 'assignee_id' => $this->agent->id, 'priority' => 'normal'])->json('id');

        $this->as($other, 'get', "/api/realty/tasks/{$id}")->assertNotFound();
        $this->as($other, 'get', '/api/realty/tasks')->assertJsonCount(0, 'tasks');
        $this->as($other, 'post', '/api/realty/tasks', ['title' => 'Nope', 'priority' => 'normal'])->assertForbidden();
        $this->as($clerk, 'post', '/api/realty/tasks', ['title' => 'Nope', 'priority' => 'normal'])->assertForbidden();
        $this->as($clerk, 'get', '/api/realty/tasks/options')->assertForbidden();

        $this->as($planner, 'get', '/api/realty/tasks')->assertJsonPath('manager', true)->assertJsonCount(1, 'tasks');
        $this->as($planner, 'post', '/api/realty/tasks', ['title' => 'For the clerk', 'assignee_id' => $clerk->id, 'priority' => 'normal'])->assertCreated();
        $this->assertSame(['For the clerk'], array_column($this->as($clerk, 'get', '/api/realty/tasks')->json('tasks'), 'title'));
    }

    public function test_tasks_only_go_to_this_realtys_active_people(): void
    {
        $elsewhere = Realty::create(['name' => 'Other', 'slug' => 'other', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $stranger = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $elsewhere->id]);
        $off = User::factory()->create(['role' => User::ROLE_REALTY, 'realty_id' => $this->realty->id, 'status' => User::STATUS_DISABLED]);

        foreach ([$stranger, $off] as $who) {
            $this->as($this->admin, 'post', '/api/realty/tasks', ['title' => 'X', 'assignee_id' => $who->id, 'priority' => 'normal'])->assertJsonValidationErrors('assignee_id');
        }
        $people = array_column($this->as($this->admin, 'get', '/api/realty/tasks/options')->json('people'), 'name');
        $this->assertContains('Ana Agent', $people);
        $this->assertNotContains($stranger->name, $people);
        Mail::assertNothingSent();
    }

    public function test_the_sidebar_counts_open_and_overdue_tasks_and_old_done_ones_leave_the_board(): void
    {
        Task::create(['realty_id' => $this->realty->id, 'assignee_id' => $this->agent->id, 'title' => 'Late', 'due_on' => now()->subDays(2)->toDateString()]);
        Task::create(['realty_id' => $this->realty->id, 'assignee_id' => $this->agent->id, 'title' => 'Fresh']);
        Task::create(['realty_id' => $this->realty->id, 'assignee_id' => $this->agent->id, 'title' => 'Old done', 'status' => 'done', 'completed_at' => now()->subDays(40)]);

        $this->as($this->agent, 'get', '/api/realty/overview')->assertJsonPath('stats.my_tasks', 2)->assertJsonPath('stats.my_tasks_overdue', 1);
        $board = $this->as($this->agent, 'get', '/api/realty/tasks')->json('tasks');
        $this->assertSame(['Late', 'Fresh'], array_column($board, 'title'));
        $this->assertTrue($board[0]['overdue']);
    }

    /** Each call is a fresh request with its own token, as from the Next.js server. */
    private function as(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('web')->plainTextToken)->json($method, $uri, $data);
    }
}
