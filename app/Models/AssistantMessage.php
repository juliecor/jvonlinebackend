<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question ("user") or the assistant's answer ("assistant"), in Markdown. */
#[Fillable(['assistant_chat_id', 'role', 'content'])]
class AssistantMessage extends Model
{
    public function chat(): BelongsTo
    {
        return $this->belongsTo(AssistantChat::class, 'assistant_chat_id');
    }
}
