<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An update on a task, from the admin or the person doing it. */
#[Fillable(['task_id', 'user_id', 'body'])]
class TaskComment extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
