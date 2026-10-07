<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\PaymentPlan;
use App\Models\Project;
use App\Support\Milestones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Existing offers keep their own copy of the schedule, so a plan can always go. */
    public function destroy(Request $request, PaymentPlan $plan): JsonResponse
    {
        abort_unless($plan->realty_id === $request->user()->realty_id, 404);
        $plan->delete();

        return response()->json(['deleted' => $plan->id]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']] + Milestones::rules('milestones'));
        $data['milestones'] = Milestones::normalize($data['milestones'], 'milestones');

        return $data;
    }
}
