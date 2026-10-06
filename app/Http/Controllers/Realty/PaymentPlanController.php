<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\PaymentPlan;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Payment plans per project. Staff only. Milestones must add up to 100%. */
class PaymentPlanController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->realty_id === $request->user()->realty_id, 404);
        $data = $this->validated($request);

        return response()->json(PaymentPlan::create($data + ['realty_id' => $project->realty_id, 'project_id' => $project->id]), 201);
    }

    public function update(Request $request, PaymentPlan $plan): JsonResponse
    {
        abort_unless($plan->realty_id === $request->user()->realty_id, 404);
        $plan->update($this->validated($request));

        return response()->json($plan->fresh());
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'milestones' => ['required', 'array', 'min:1', 'max:24'],
            'milestones.*.label' => ['required', 'string', 'max:120'],
            'milestones.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'milestones.*.days' => ['nullable', 'integer', 'min:0', 'max:36500'],
        ]);
        $total = array_sum(array_map(fn ($m) => (float) $m['percent'], $data['milestones']));
        if (abs($total - 100) > 0.01) {
            throw ValidationException::withMessages(['milestones' => "The percentages add up to {$total}%, they need to be 100%."]);
        }
        $data['milestones'] = array_map(fn ($m) => ['label' => $m['label'], 'percent' => (float) $m['percent'], 'days' => isset($m['days']) && $m['days'] !== '' ? (int) $m['days'] : null], array_values($data['milestones']));

        return $data;
    }
}
