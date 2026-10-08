<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AssistantChat;
use App\Support\Assistant\Assistant;
use App\Support\Assistant\AssistantUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

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

        $conversation = $chat
            ? $chat->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get(['role', 'content'])->reverse()->values()->map->only(['role', 'content'])->all()
            : [];
        $conversation[] = ['role' => 'user', 'content' => $data['message']];

        set_time_limit(180);
        try {
            $answer = (new Assistant($user))->reply($conversation);
        } catch (AssistantUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $chat ??= AssistantChat::create(['user_id' => $user->id, 'realty_id' => $user->realty_id, 'title' => Str::limit(preg_replace('/\s+/', ' ', trim($data['message'])), 80, '…')]);
        $chat->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $reply = $chat->messages()->create(['role' => 'assistant', 'content' => $answer]);
        $chat->touch();

        return response()->json([
            'chat' => ['id' => $chat->id, 'title' => $chat->title, 'updated_at' => $chat->updated_at],
            'message' => $reply->only(['id', 'role', 'content', 'created_at']),
        ]);
    }

    public function destroy(Request $request, AssistantChat $chat): Response
    {
        $this->own($request, $chat);
        $chat->delete();

        return response()->noContent();
    }

    /** A chat belongs to one person in one realty; anyone else gets a 404. */
    private function own(Request $request, AssistantChat $chat): void
    {
        $user = $request->user();
        abort_unless($chat->user_id === $user->id && $chat->realty_id === $user->realty_id, 404);
    }
}
