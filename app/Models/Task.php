<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something a realty's admin gives a staff member or an agent to do (see TaskController). */
#[Fillable(['realty_id', 'created_by', 'assignee_id', 'title', 'description', 'status', 'priority', 'due_on', 'offer_id', 'project_id', 'completed_at'])]
class Task extends Model
{
    public const STATUSES = ['todo', 'doing', 'done'];

    public const PRIORITIES = ['normal', 'urgent'];

    protected $attributes = ['status' => 'todo', 'priority' => 'normal'];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }

    public function realty(): BelongsTo
    {
        return $this->belongsTo(Realty::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class)->orderBy('id');
    }

    /** Past its due date and not done yet. */
    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->due_on !== null && $this->due_on->lt(now('Asia/Manila')->startOfDay());
    }
}
