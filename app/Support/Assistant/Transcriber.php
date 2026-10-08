<?php

namespace App\Support\Assistant;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A spoken question (the AI page's mic button) turned into text by OpenAI.
 * The realty's project names go along as a hint, so they come out spelled
 * the way the dashboard spells them. The recording isn't kept.
 */
class Transcriber
{
    public function __construct(private readonly User $user) {}

    public function text(UploadedFile $audio): string
    {
        $key = config('services.openai.key');
        if (! $key) {
            throw new AssistantUnavailable("The AI assistant isn't set up yet: OPENAI_API_KEY is missing from the backend's .env.");
        }

        $response = Http::withToken($key)->acceptJson()->timeout(60)
            ->attach('file', $audio->get(), 'question.'.($audio->guessExtension() ?: $audio->getClientOriginalExtension() ?: 'webm'))
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => config('services.openai.transcribe_model'),
                'prompt' => $this->hint(),
            ]);

        if ($response->failed()) {
            Log::warning('AI assistant: transcription failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);
            throw new AssistantUnavailable($response->status() === 429
                ? 'The AI is busy or the OpenAI account has run out of credit. Try again in a minute.'
                : "Couldn't make out the recording. Try again, or type the question.");
        }

        return trim((string) $response->json('text'));
    }

    /**
     * The names the recording may contain, as a plain list: a sentence here
     * tends to come back as the "transcript" of a silent recording.
     */
    private function hint(): string
    {
        $realty = $this->user->realty;

        return $realty->projects()->orderBy('name')->pluck('name')->prepend(Assistant::nameFor($realty))->push('Pag-IBIG', 'reservation fee', 'down payment', 'turnover')->implode(', ');
    }
}
