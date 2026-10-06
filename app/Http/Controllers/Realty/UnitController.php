<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Units inside a project — what is actually for sale. Staff only. */
class UnitController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
        $data = $this->validated($request);
        $data['realty_id'] = $project->realty_id;
        $data['project_id'] = $project->id;
        if ($request->hasFile('floor_plan')) {
            $data['floor_plan_path'] = $request->file('floor_plan')->store('floor-plans', config('filesystems.uploads'));
        }

        return response()->json(Unit::create($data), 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        abort_unless($unit->realty_id === $request->user()->realty_id, 404);
        $data = $this->validated($request);
        if ($request->hasFile('floor_plan')) {
            $data['floor_plan_path'] = $request->file('floor_plan')->store('floor-plans', config('filesystems.uploads'));
        }
        $unit->update($data);

        return response()->json($unit->fresh());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit_type' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'floor' => ['nullable', 'string', 'max:120'],
            'area_sqm' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:available,reserved,sold'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'floor_plan' => ['nullable', 'image', 'max:6144'],
        ]);
    }
}
