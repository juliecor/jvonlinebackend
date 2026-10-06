<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How a unit is paid: milestones as [{label, percent, days|null}], null days = on completion. */
#[Fillable(['realty_id', 'project_id', 'name', 'milestones'])]
class PaymentPlan extends Model
{
    protected function casts(): array
    {
        return ['milestones' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
