<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question ("user") or the assistant's answer ("assistant"), in Markdown.
 * meta holds what the answer showed besides its text, e.g. ["units" => [12, 40]].
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

    /** @return array<int, int> The units shown as cards under this answer, in order. */
    public function unitIds(): array
    {
        return array_values(array_map('intval', $this->meta['units'] ?? []));
    }
}
