<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Mail\TaskAssignedMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Illuminate\Support\defer;

/**
 * A realty's tasks. Managers (full admins, and staff with the Tasks section)
 * see everyone's, create, edit, assign and delete them; everyone else sees
 * the tasks given to them (or that they wrote) and can move them along,
 * comment and attach files. The person given a task gets an email.
 */
class TaskController extends Controller
{
    /** Done tasks stay on the board this long. */
    private const DONE_DAYS = 30;

    /** Files on one task, at most. */
    private const MAX_FILES = 20;

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $manager = $this->manages($me);
        $mine = ! $manager || $request->query('scope') === 'mine';
        $tasks = $this->visible($me)
            ->when($mine, fn ($q) => $q->where('assignee_id', $me->id))
            ->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('completed_at', '>=', now()->subDays(self::DONE_DAYS)))
            ->with(['assignee:id,name,role', 'creator:id,name', 'offer:id,code,buyer_name', 'project:id,name'])
            ->withCount(['comments', 'files'])
            ->orderByRaw("case priority when 'urgent' then 0 else 1 end")->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')
            ->get();

        return response()->json([
            'manager' => $manager,
            'tasks' => $tasks->map(fn (Task $t) => $this->row($t, $me))->all(),
        ]);
    }

    /** What a new task can be given to and linked with: the realty's people, projects and recent offers. */
    public function options(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->manages($me), 403, 'Only admins with the Tasks section can give out tasks.');

        return response()->json([
            'people' => User::where('realty_id', $me->realty_id)->whereIn('role', [User::ROLE_REALTY, User::ROLE_AGENT])->where('status', User::STATUS_ACTIVE)
                ->orderBy('role')->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role === User::ROLE_REALTY ? 'staff' : 'agent', 'you' => $u->id === $me->id])->all(),
            'projects' => Project::where('realty_id', $me->realty_id)->orderBy('name')->get(['id', 'name'])->all(),
            'offers' => Offer::where('realty_id', $me->realty_id)->where('status', 'active')->with('project:id,name')->latest()->limit(200)->get(['id', 'code', 'buyer_name', 'project_id'])
                ->map(fn (Offer $o) => ['id' => $o->id, 'label' => "{$o->buyer_name} · {$o->project?->name} ({$o->code})"])->all(),
        ]);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        $me = $request->user();
        $t = $this->visible($me)->with(['assignee:id,name,role', 'creator:id,name', 'offer:id,code,buyer_name', 'project:id,name', 'comments.user:id,name', 'files.user:id,name'])->withCount(['comments', 'files'])->findOrFail($task);

        return response()->json($this->row($t, $me) + [
            'description' => $t->description,
            'assignee_id' => $t->assignee_id,
            'offer_id' => $t->offer_id,
            'project_id' => $t->project_id,
            'comments' => $t->comments->map(fn (TaskComment $c) => ['id' => $c->id, 'body' => $c->body, 'by' => $c->user?->name ?? 'Someone', 'mine' => $c->user_id === $me->id, 'at' => $c->created_at->toIso8601String()])->all(),
            'files' => $t->files->map(fn (TaskFile $f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size, 'mime' => $f->mime, 'by' => $f->user?->name ?? 'Someone', 'at' => $f->created_at->toIso8601String(), 'can_delete' => $f->user_id === $me->id || $this->manages($me)])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->manages($me), 403, 'Only admins with the Tasks section can give out tasks.');
        $task = Task::create($this->validated($request, $me) + ['realty_id' => $me->realty_id, 'created_by' => $me->id]);
        $this->notify($task, $me);

        return response()->json($this->row($task->load(['assignee:id,name,role', 'creator:id,name', 'offer:id,code,buyer_name', 'project:id,name'])->loadCount(['comments', 'files']), $me), 201);
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->manages($me), 403, 'Only admins with the Tasks section can change a task.');
        $t = $this->visible($me)->findOrFail($task);
        $before = $t->assignee_id;
        $t->update($this->validated($request, $me));
        if ($t->assignee_id !== $before) {
            $this->notify($t, $me);
        }

        return response()->json($this->row($t->load(['assignee:id,name,role', 'creator:id,name', 'offer:id,code,buyer_name', 'project:id,name'])->loadCount(['comments', 'files']), $me));
    }

    /** To do → In progress → Done (and back), by the person doing it or a manager. */
    public function status(Request $request, int $task): JsonResponse
    {
        $me = $request->user();
        $t = $this->visible($me)->findOrFail($task);
        abort_unless($t->assignee_id === $me->id || $this->manages($me), 403, 'Only the person doing this task, or an admin, can move it.');
        $data = $request->validate(['status' => ['required', Rule::in(Task::STATUSES)]]);
        $t->update(['status' => $data['status'], 'completed_at' => $data['status'] === 'done' ? ($t->completed_at ?? now()) : null]);

        return response()->json($this->row($t->load(['assignee:id,name,role', 'creator:id,name', 'offer:id,code,buyer_name', 'project:id,name'])->loadCount(['comments', 'files']), $me));
    }

    public function destroy(Request $request, int $task): Response
    {
        $me = $request->user();
        abort_unless($this->manages($me), 403, 'Only admins with the Tasks section can delete a task.');
        $t = $this->visible($me)->with('files')->findOrFail($task);
        $paths = $t->files->pluck('path')->all();
        $t->delete();
        Storage::disk(OfferDocument::disk())->delete($paths);

        return response()->noContent();
    }

    public function comment(Request $request, int $task): JsonResponse
    {
        $me = $request->user();
        $t = $this->visible($me)->findOrFail($task);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $c = $t->comments()->create(['user_id' => $me->id, 'body' => trim($data['body'])]);
        $t->touch();

        return response()->json(['id' => $c->id, 'body' => $c->body, 'by' => $me->name, 'mine' => true, 'at' => $c->created_at->toIso8601String()], 201);
    }

    public function upload(Request $request, int $task): JsonResponse
    {
        $me = $request->user();
        $t = $this->visible($me)->findOrFail($task);
        abort_if($t->files()->count() >= self::MAX_FILES, 422, 'This task has '.self::MAX_FILES.' files already. Delete one first.');
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,csv,txt']], [
            'file.mimes' => 'Attach a photo, a PDF, or a Word or Excel file.',
            'file.max' => 'The file is bigger than 10 MB.',
        ]);
        $file = $request->file('file');
        $path = $file->storeAs("tasks/{$t->id}", Str::random(32).'.'.($file->guessExtension() ?: $file->getClientOriginalExtension()), OfferDocument::disk());
        abort_unless($path, 500, 'The file could not be saved. Please try again.');
        $f = $t->files()->create(['user_id' => $me->id, 'path' => $path, 'original_name' => Str::limit($file->getClientOriginalName(), 240, ''), 'mime' => $file->getMimeType() ?? 'application/octet-stream', 'size' => $file->getSize()]);
        $t->touch();

        return response()->json(['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size, 'mime' => $f->mime, 'by' => $me->name, 'at' => $f->created_at->toIso8601String(), 'can_delete' => true], 201);
    }

    public function download(Request $request, int $task, int $file): StreamedResponse
    {
        $t = $this->visible($request->user())->findOrFail($task);
        $f = $t->files()->whereKey($file)->firstOrFail();
        $disk = Storage::disk(OfferDocument::disk());
        abort_unless($disk->exists($f->path), 404, 'The file is missing from storage.');

        return $disk->response($f->path, $f->original_name, [
            'Content-Type' => $f->mime,
            // Never let an uploaded file run as a page.
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function deleteFile(Request $request, int $task, int $file): Response
    {
        $me = $request->user();
        $t = $this->visible($me)->findOrFail($task);
        $f = $t->files()->whereKey($file)->firstOrFail();
        abort_unless($f->user_id === $me->id || $this->manages($me), 403, 'Only the person who added this file, or an admin, can delete it.');
        DB::transaction(fn () => $f->delete());
        Storage::disk(OfferDocument::disk())->delete($f->path);

        return response()->noContent();
    }

    /** Staff who give out tasks: full admins, and staff whose account includes Tasks. Agents never do. */
    private function manages(User $user): bool
    {
        return $user->role === User::ROLE_REALTY && $user->canUse('tasks');
    }

    /** @return Builder<Task> The realty's tasks for a manager; for anyone else, theirs (given to them or written by them). */
    private function visible(User $me): Builder
    {
        return Task::where('realty_id', $me->realty_id)
            ->when(! $this->manages($me), fn ($q) => $q->where(fn ($w) => $w->where('assignee_id', $me->id)->orWhere('created_by', $me->id)));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $me): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('realty_id', $me->realty_id)->where('status', User::STATUS_ACTIVE)->whereIn('role', [User::ROLE_REALTY, User::ROLE_AGENT])],
            'due_on' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'offer_id' => ['nullable', 'integer', Rule::exists('offers', 'id')->where('realty_id', $me->realty_id)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('realty_id', $me->realty_id)],
        ], ['assignee_id.exists' => 'Choose someone from your realty.']);
        $data['title'] = trim($data['title']);

        return $data;
    }

    /** The person given the task hears about it by email (not when they gave it to themselves). */
    private function notify(Task $task, User $me): void
    {
        $to = $task->assignee;
        if (! $to || $to->id === $me->id) {
            return;
        }
        $url = rtrim(config('app.frontend_url'), '/')."/{$me->realty->slug}/dashboard/tasks?task={$task->id}";
        $mail = new TaskAssignedMail($task->load(['creator', 'offer', 'project', 'realty']), $to, $url);
        // Sent once the answer has gone back, so a slow mail server never holds up the board.
        defer(function () use ($to, $mail, $task) {
            try {
                Mail::to($to->email)->send($mail);
            } catch (\Throwable $e) {
                // The task is saved either way; it shows on their board.
                Log::warning('Task mail failed', ['task' => $task->id, 'to' => $to->email, 'error' => $e->getMessage()]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function row(Task $t, User $me): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'priority' => $t->priority,
            'due_on' => $t->due_on?->toDateString(),
            'overdue' => $t->isOverdue(),
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'role' => $t->assignee->role === User::ROLE_REALTY ? 'staff' : 'agent'] : null,
            'creator' => $t->creator?->name,
            'link' => $t->offer ? ['type' => 'offer', 'id' => $t->offer->id, 'label' => "{$t->offer->buyer_name} ({$t->offer->code})"] : ($t->project ? ['type' => 'project', 'id' => $t->project->id, 'label' => $t->project->name] : null),
            'comment_count' => (int) ($t->comments_count ?? 0),
            'file_count' => (int) ($t->files_count ?? 0),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
            'can_edit' => $this->manages($me),
            'can_move' => $t->assignee_id === $me->id || $this->manages($me),
        ];
    }
}
