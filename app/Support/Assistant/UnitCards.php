<?php

namespace App\Support\Assistant;

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;

/**
 * The unit cards the AI shows under an answer: photo, project, type, area,
 * price, status, and whether an offer can be made for it right now. Always
 * read from the live units, and only the person's own realty's.
 */
class UnitCards
{
    /** Cards under one answer, at most. */
    public const MAX = 6;

    /**
     * @param  array<int, int>  $ids  in the order to show them
     * @return array<int, array<string, mixed>>
     */
    public static function for(User $user, array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX);
        if ($ids === []) {
            return [];
        }
        $units = Unit::where('realty_id', $user->realty_id)->whereIn('id', $ids)->with('project')->get()->keyBy('id');
        $models = UnitType::whereIn('project_id', $units->pluck('project_id')->unique())->get();

        return collect($ids)->map(fn (int $id) => $units->get($id))->filter()->map(fn (Unit $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'project' => ['id' => $u->project_id, 'name' => $u->project?->name, 'location' => $u->project?->location],
            'unit_type' => $u->unit_type,
            'floor' => $u->floor,
            'area_sqm' => $u->area_sqm !== null ? (float) $u->area_sqm : null,
            'price' => $u->price !== null ? (float) $u->price : null,
            'status' => $u->status,
            'photo' => $u->photo($models, $u->project),
            // The New offer form lists available units with a price, in projects open for offers.
            'can_offer' => $u->status === 'available' && $u->price !== null && $u->project?->status === 'active',
        ])->values()->all();
    }
}
