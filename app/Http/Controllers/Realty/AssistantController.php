<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\Assistant\Assistant;
use App\Support\Assistant\AssistantUnavailable;
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

        return response()->json(['name' => Assistant::nameFor($user->realty).' AI', 'chats' => $chats]);
    }

    public function show(Request $request, AssistantChat $chat): JsonResponse
    {
        $this->own($request, $chat);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'messages' => $chat->messages()->get(['id', 'role', 'content', 'created_at']),
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
        try {
            $answer = (new Assistant($user))->reply($conversation);
        } catch (AssistantUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer);

        return response()->json([
            'chat' => ['id' => $chat->id, 'title' => $chat->title, 'updated_at' => $chat->updated_at],
            'message' => $reply->only(['id', 'role', 'content', 'created_at']),
        ]);
    }

    /**
     * The same question, answered word by word as server-sent events:
     * status (a lookup is running), delta (more of the answer), reset (start
     * the answer again), then done (saved: the chat and the message) or error.
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

            try {
                $answer = (new Assistant($user))->reply($conversation, $emit);
            } catch (AssistantUnavailable $e) {
                $emit('error', ['message' => $e->getMessage()]);

                return;
            } catch (Throwable $e) {
                Log::error('AI assistant: stream failed', ['error' => $e->getMessage()]);
                $emit('error', ['message' => "The AI couldn't answer right now. Try again in a moment."]);

                return;
            }

            [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer);
            $emit('done', [
                'chat' => ['id' => $chat->id, 'title' => $chat->title, 'updated_at' => $chat->updated_at],
                'message' => $reply->only(['id', 'role', 'content', 'created_at']),
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
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function conversation(?AssistantChat $chat, string $question): array
    {
        $conversation = $chat
            ? $chat->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get(['role', 'content'])->reverse()->values()->map->only(['role', 'content'])->all()
            : [];
        $conversation[] = ['role' => 'user', 'content' => $question];

        return $conversation;
    }

    /**
     * Keep the question and its answer; a first question starts the chat and names it.
     *
     * @return array{0: AssistantChat, 1: AssistantMessage}
     */
    private function save(User $user, ?AssistantChat $chat, string $question, string $answer): array
    {
        $chat ??= AssistantChat::create(['user_id' => $user->id, 'realty_id' => $user->realty_id, 'title' => Str::limit(preg_replace('/\s+/', ' ', trim($question)), 80, '…')]);
        $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $reply = $chat->messages()->create(['role' => 'assistant', 'content' => $answer]);
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
