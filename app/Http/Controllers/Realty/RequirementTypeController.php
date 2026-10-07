<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\RequirementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The realty's buyer checklist: what buyers upload on their offer page. Staff only. */
class RequirementTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = RequirementType::where('realty_id', $request->user()->realty_id)
            ->withCount('documents')
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'help', 'applies', 'sort', 'active']);

        return response()->json(['types' => $types, 'applies' => RequirementType::APPLIES]);
    }

    public function store(Request $request): JsonResponse
    {
        $realtyId = $request->user()->realty_id;
        $data = $this->validated($request);
        $type = RequirementType::create($data + [
            'realty_id' => $realtyId,
            'sort' => (int) RequirementType::where('realty_id', $realtyId)->max('sort') + 10,
        ]);

        return response()->json($type, 201);
    }

    public function update(Request $request, RequirementType $type): JsonResponse
    {
        $this->own($request, $type);
        $type->update($this->validated($request) + ['active' => $request->boolean('active', $type->active)]);

        return response()->json($type);
    }

    /** Swap places with the neighbour above or below. */
    public function move(Request $request, RequirementType $type): JsonResponse
    {
        $this->own($request, $type);
        $up = $request->input('direction') === 'up';
        $all = RequirementType::where('realty_id', $type->realty_id)->orderBy('sort')->orderBy('id')->get()->values();
        $i = $all->search(fn ($t) => $t->id === $type->id);
        $j = $up ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $all->count()) {
            $order = $all->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $n => $t) {
                $t->update(['sort' => ($n + 1) * 10]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** Only while no buyer has sent a file for it; after that, hide it instead. */
    public function destroy(Request $request, RequirementType $type): JsonResponse
    {
        $this->own($request, $type);
        if ($type->documents()->exists()) {
            return response()->json(['message' => 'Buyers already sent files for this, so it can only be hidden, not deleted.'], 409);
        }
        $type->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'help' => ['nullable', 'string', 'max:1000'],
            'applies' => ['required', Rule::in(array_keys(RequirementType::APPLIES))],
        ]);
    }

    private function own(Request $request, RequirementType $type): void
    {
        abort_unless($type->realty_id === $request->user()->realty_id, 404);
    }
}
