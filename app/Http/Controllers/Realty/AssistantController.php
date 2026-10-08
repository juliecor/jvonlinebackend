<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\Assistant\Assistant;
use App\Support\Assistant\AssistantUnavailable;
use App\Support\Assistant\Cards;
use App\Support\Assistant\RealtyTools;
use App\Support\Assistant\Transcriber;
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
            // The dashboard page it's asked from ("Ask" on any page), e.g. /johndorf/dashboard/projects/14.
            'page' => ['nullable', 'string', 'max:300'],
        ]);
        $chat = null;
        if (! empty($data['chat_id'])) {
            $chat = AssistantChat::findOrFail($data['chat_id']);
            $this->own($request, $chat);
        }

        $conversation = $this->conversation($user, $chat, $data['message'], (new RealtyTools($user))->describePage($data['page'] ?? null));

        set_time_limit(180);
        $assistant = new Assistant($user);
        try {
            $answer = $assistant->reply($conversation);
        } catch (AssistantUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer, $assistant->shownCards());

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
            // The dashboard page it's asked from ("Ask" on any page), e.g. /johndorf/dashboard/projects/14.
            'page' => ['nullable', 'string', 'max:300'],
        ]);
        $chat = null;
        if (! empty($data['chat_id'])) {
            $chat = AssistantChat::findOrFail($data['chat_id']);
            $this->own($request, $chat);
        }
        $conversation = $this->conversation($user, $chat, $data['message'], (new RealtyTools($user))->describePage($data['page'] ?? null));

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

            [$chat, $reply] = $this->save($user, $chat, $data['message'], $answer, $assistant->shownCards());
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

    /** A spoken question, as text for the question box (nothing is kept). */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            // Chrome records webm, Safari mp4; 10 MB is several minutes.
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4,audio/x-m4a,audio/aac,audio/mpeg,audio/wav,audio/x-wav'],
        ]);

        set_time_limit(90);
        try {
            $text = (new Transcriber($request->user()))->text($request->file('audio'));
        } catch (AssistantUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['text' => $text]);
    }

    public function destroy(Request $request, AssistantChat $chat): Response
    {
        $this->own($request, $chat);
        $chat->delete();

        return response()->noContent();
    }

    /**
     * The chat so far (its last messages) plus the new question, for the model.
     * The cards an answer showed follow it as a note, so "the second one" or
     * "that buyer" still means something in the next question; so does the
     * page the question is asked from, so "this project" does too.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function conversation(User $user, ?AssistantChat $chat, string $question, ?string $page = null): array
    {
        $conversation = [];
        if ($chat) {
            $messages = $chat->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get(['role', 'content', 'meta'])->reverse()->values();
            foreach ($messages as $m) {
                $conversation[] = ['role' => $m->role, 'content' => $m->content];
                $shown = $m->cardRefs() ? Cards::for($user, $m->cardRefs()) : [];
                if ($shown !== []) {
                    $conversation[] = ['role' => 'system', 'content' => 'Under that answer, these cards were shown, in order: '.collect($shown)->map(fn (array $c) => match ($c['type']) {
                        'unit' => "unit {$c['name']} ({$c['project']['name']}, id {$c['id']})",
                        'offer' => "the offer {$c['code']} for {$c['buyer']}",
                        'project' => "the project {$c['name']} (id {$c['id']})",
                        'payment' => 'payments for '.($c['unit']['name'] ?? 'a price of '.number_format($c['price'], 2)).' with '.$c['terms'],
                    })->implode('; ').'.'];
                }
            }
        }
        if ($page) {
            $conversation[] = ['role' => 'system', 'content' => "The user is asking from {$page} in the dashboard. \"This project\", \"this offer\", \"this buyer\" or \"here\" mean it."];
        }
        $conversation[] = ['role' => 'user', 'content' => $question];

        return $conversation;
    }

    /**
     * A message as the page shows it: an answer comes with its cards, drawn
     * from the live data.
     *
     * @return array<string, mixed>
     */
    private function present(User $user, AssistantMessage $message): array
    {
        $cards = $message->cardRefs() ? Cards::for($user, $message->cardRefs()) : [];

        return $message->only(['id', 'role', 'content', 'created_at']) + ($cards ? ['cards' => $cards] : []);
    }

    /**
     * Keep the question and its answer; a first question starts the chat and names it.
     *
     * @param  array<int, array<string, mixed>>  $cards  what the answer showed as cards (references, see Cards)
     * @return array{0: AssistantChat, 1: AssistantMessage}
     */
    private function save(User $user, ?AssistantChat $chat, string $question, string $answer, array $cards = []): array
    {
        $chat ??= AssistantChat::create(['user_id' => $user->id, 'realty_id' => $user->realty_id, 'title' => Str::limit(preg_replace('/\s+/', ' ', trim($question)), 80, '…')]);
        $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $reply = $chat->messages()->create(['role' => 'assistant', 'content' => $answer, 'meta' => $cards ? ['cards' => $cards] : null]);
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
