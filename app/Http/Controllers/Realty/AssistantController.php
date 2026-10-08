<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\Unit;
use App\Models\User;
use App\Support\Assistant\Assistant;
use App\Support\Assistant\AssistantUnavailable;
use App\Support\Assistant\RealtyTools;
use App\Support\Assistant\UnitCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** The dashboard's AI assistant: each person's own chats, inside their realty. */
class AssistantController extends Controller
{
    /** How much of a chat goes back to the model with each new question. */
    private const HISTORY = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $chats = AssistantChat::where('user_id', $user->id)->where('realty_id', $user->realty_id)
            ->latest('updated_at')->limit(50)->get(['id', 'title', 'updated_at']);

        // How many things wait for this person, for the "What needs my attention today?" button.
        try {
            $attention = (new RealtyTools($user))->attention()['total_things_to_do'];
        } catch (Throwable $e) {
            Log::error('AI assistant: attention count failed', ['error' => $e->getMessage()]);
            $attention = null;
        }

        return response()->json(['name' => Assistant::nameFor($user->realty).' AI', 'chats' => $chats, 'attention' => $attention]);
    }

    public function show(Request $request, AssistantChat $chat): JsonResponse
    {
        $this->own($request, $chat);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'messages' => $chat->messages()->get(['id', 'role', 'content', 'meta', 'created_at'])->map(fn (AssistantMessage $m) => $this->present($request->user(), $m)),
        ]);
    }

    /** A question: answered from the realty's data, and saved with the answer (nothing is saved if it fails). */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'chat_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $chat = null;
        if (! empty($data['chat_id'])) {
            $chat = AssistantChat::findOrFail($data['chat_id']);
            $this->own($request, $chat);
        }

        $conversation = $this->conversation($chat, $data['message']);

        set_time_limit(180);
        $assistant = new Assistant($user);
        try {
            $answer = $assistant->reply($conversation);
        } catch (AssistantUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer, $assistant->shownUnitIds());

        return response()->json([
            'chat' => ['id' => $chat->id, 'title' => $chat->title, 'updated_at' => $chat->updated_at],
            'message' => $this->present($user, $reply),
        ]);
    }

    /**
     * The same question, answered word by word as server-sent events:
     * status (a lookup is running), delta (more of the answer), cards (units
     * to show under it), reset (start the answer again), then done (saved:
     * the chat and the message) or error.
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'chat_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $chat = null;
        if (! empty($data['chat_id'])) {
            $chat = AssistantChat::findOrFail($data['chat_id']);
            $this->own($request, $chat);
        }
        $conversation = $this->conversation($chat, $data['message']);

        return response()->stream(function () use ($user, $chat, $data, $conversation) {
            set_time_limit(180);
            $emit = function (string $event, array $payload): void {
                echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $assistant = new Assistant($user);
            try {
                $answer = $assistant->reply($conversation, $emit);
            } catch (AssistantUnavailable $e) {
                $emit('error', ['message' => $e->getMessage()]);

                return;
            } catch (Throwable $e) {
                Log::error('AI assistant: stream failed', ['error' => $e->getMessage()]);
                $emit('error', ['message' => "The AI couldn't answer right now. Try again in a moment."]);

                return;
            }

            [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer, $assistant->shownUnitIds());
            $emit('done', [
                'chat' => ['id' => $chat->id, 'title' => $chat->title, 'updated_at' => $chat->updated_at],
                'message' => $this->present($user, $reply),
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            // Nginx would otherwise hold the words back until the answer is complete.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, AssistantChat $chat): Response
    {
        $this->own($request, $chat);
        $chat->delete();

        return response()->noContent();
    }

    /**
     * The chat so far (its last messages) plus the new question, for the model.
     * Units an answer showed as cards follow it as a note, so "the second one"
     * still means something in the next question.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function conversation(?AssistantChat $chat, string $question): array
    {
        $conversation = [];
        if ($chat) {
            $messages = $chat->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get(['role', 'content', 'meta'])->reverse()->values();
            $units = Unit::where('realty_id', $chat->realty_id)->whereIn('id', $messages->flatMap(fn (AssistantMessage $m) => $m->unitIds()))->with('project:id,name')->get()->keyBy('id');
            foreach ($messages as $m) {
                $conversation[] = ['role' => $m->role, 'content' => $m->content];
                $shown = collect($m->unitIds())->map(fn (int $id) => $units->get($id))->filter();
                if ($shown->isNotEmpty()) {
                    $conversation[] = ['role' => 'system', 'content' => 'Under that answer, these units were shown as cards, in order: '.$shown->map(fn (Unit $u) => "{$u->name} ({$u->project?->name}, id {$u->id})")->implode('; ').'.'];
                }
            }
        }
        $conversation[] = ['role' => 'user', 'content' => $question];

        return $conversation;
    }

    /**
     * A message as the page shows it: an answer comes with its unit cards,
     * drawn from the live units.
     *
     * @return array<string, mixed>
     */
    private function present(User $user, AssistantMessage $message): array
    {
        $cards = $message->unitIds() ? UnitCards::for($user, $message->unitIds()) : [];

        return $message->only(['id', 'role', 'content', 'created_at']) + ($cards ? ['cards' => $cards] : []);
    }

    /**
     * Keep the question and its answer; a first question starts the chat and names it.
     *
     * @param  array<int, int>  $unitIds  units the answer showed as cards
     * @return array{0: AssistantChat, 1: AssistantMessage}
     */
    private function save(User $user, ?AssistantChat $chat, string $question, string $answer, array $unitIds = []): array
    {
        $chat ??= AssistantChat::create(['user_id' => $user->id, 'realty_id' => $user->realty_id, 'title' => Str::limit(preg_replace('/\s+/', ' ', trim($question)), 80, '…')]);
        $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $reply = $chat->messages()->create(['role' => 'assistant', 'content' => $answer, 'meta' => $unitIds ? ['units' => $unitIds] : null]);
        $chat->touch();

        return [$chat, $reply];
    }

    /** A chat belongs to one person in one realty; anyone else gets a 404. */
    private function own(Request $request, AssistantChat $chat): void
    {
        $user = $request->user();
        abort_unless($chat->user_id === $user->id && $chat->realty_id === $user->realty_id, 404);
    }
}
