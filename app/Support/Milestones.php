<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A payment schedule's rows — the same shape for a project's official plans
 * and an agent's custom terms: label, share of the price, when it's due
 * (days after the purchase date, or null for "on completion") and, for
 * equity paid monthly, how many months it's spread over.
 */
class Milestones
{
    /** @return array<string, array<int, string>> */
    public static function rules(string $key): array
    {
        return [
            $key => ['required', 'array', 'min:1', 'max:24'],
            "{$key}.*.label" => ['required', 'string', 'max:120'],
            "{$key}.*.percent" => ['required', 'numeric', 'gt:0', 'max:100'],
            "{$key}.*.days" => ['nullable', 'integer', 'min:0', 'max:36500'],
            "{$key}.*.months" => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }

    /**
     * Clean rows, checked to add up to 100%.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, percent: float, days: int|null, months: int|null}>
     */
    public static function normalize(array $rows, string $key): array
    {
        $rows = array_map(fn ($m) => [
            'label' => trim((string) $m['label']),
            'percent' => round((float) $m['percent'], 10), // agents type pesos; enough decimals that ₱20,000 stays ₱20,000.00
            'days' => isset($m['days']) && $m['days'] !== '' ? (int) $m['days'] : null,
            'months' => isset($m['months']) && (int) $m['months'] >= 2 ? (int) $m['months'] : null,
        ], array_values($rows));
        $total = array_sum(array_column($rows, 'percent'));
        if (abs($total - 100) > 0.01) {
            $shown = rtrim(rtrim(number_format($total, 2), '0'), '.');
            throw ValidationException::withMessages([$key => "The payments add up to {$shown}% of the price; they need to add up to 100%."]);
        }

        return $rows;
    }
}
