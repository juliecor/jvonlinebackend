<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question ("user") or the assistant's answer ("assistant"), in Markdown.
 * meta holds what the answer showed besides its text: the cards under it, as
 * references (["cards" => [["type" => "unit", "id" => 12], …]]; see Cards).
 */
#[Fillable(['assistant_chat_id', 'role', 'content', 'meta'])]
class AssistantMessage extends Model
{
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AssistantChat::class, 'assistant_chat_id');
    }

    /** @return array<int, array<string, mixed>> The cards under this answer, as references, in order. */
    public function cardRefs(): array
    {
        if (isset($this->meta['cards'])) {
            return array_values($this->meta['cards']);
        }

        // Answers from before offer and project cards kept only unit ids.
        return array_map(fn ($id) => ['type' => 'unit', 'id' => (int) $id], array_values($this->meta['units'] ?? []));
    }
}
