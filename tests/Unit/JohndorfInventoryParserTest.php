<?php

namespace Tests\Unit;

use App\Support\JohndorfInventory;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are saved pages from https://johndorfventures.com/reservation/project_unit_list.php?RealtyID=<n>,
 * read 2026-10-08 (tests/Fixtures/johndorf). inventory-41-sample.html keeps
 * 7 of Plumera's 334 rows verbatim, chosen to cover every shape the real
 * page uses: a Bank-only row, a two-financing-model row, a 36-month equity
 * row with no discount, and every floor-ordinal case (GF, 2F, 3F, 10th F).
 */
class JohndorfInventoryParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        // A pure unit test (plain PHPUnit\Framework\TestCase, no booted app),
        // so the path is built from __DIR__ rather than the base_path() helper.
        return file_get_contents(dirname(__DIR__)."/Fixtures/johndorf/{$name}");
    }

    public function test_parses_all_sample_rows_in_building_unit_order(): void
    {
        $result = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera');

        $this->assertSame('listed', $result['inventory_state']);
        $this->assertSame(7, $result['available_count']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(
            ['C-2F (215)', 'J-GF (101)', 'J-GF (112)', 'J-3F (322)', 'N-4F (401)', 'T-2F (205)', 'T-10F (1037)'],
            array_column($result['units'], 'code'),
        );
    }

    public function test_normalises_unit_type_spelling_variants(): void
    {
        $units = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'];
        $byCode = array_column($units, null, 'code');

        $this->assertSame('1 BR', $byCode['J-GF (112)']['raw_type']);
        $this->assertSame('1BR Unit', $byCode['J-GF (112)']['unit_type']);
        $this->assertSame('Studio', $byCode['T-2F (205)']['raw_type']);
        $this->assertSame('Studio Unit', $byCode['T-2F (205)']['unit_type']);
    }

    public function test_bank_only_row_with_a_discount(): void
    {
        $unit = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'][0];

        $this->assertSame('C-2F (215)', $unit['code']);
        $this->assertSame(4186, $unit['project_unit_id']);
        $this->assertSame('Bldg C · Unit 215', $unit['name']);
        $this->assertSame('C', $unit['building']);
        $this->assertSame('2nd floor', $unit['floor']);
        $this->assertSame(215, $unit['unit_number']);
        $this->assertSame(5492000, $unit['price']);
        $this->assertSame(15000, $unit['reservation_fee']);
        $this->assertCount(1, $unit['financing']);
        $this->assertSame([
            'option' => 'Bank Financing',
            'discount' => 100000,
            'required_income' => 147000,
            'loanable_amount' => 5297000,
            'monthly_amortization' => 42672.27,
            'equity_months' => 4,
            'equity_total' => 80000,
            'equity_monthly' => 20000,
        ], $unit['financing'][0]);
    }

    public function test_ground_floor_row_with_both_financing_options(): void
    {
        $unit = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'][1];

        $this->assertSame('J-GF (101)', $unit['code']);
        $this->assertSame(5404, $unit['project_unit_id']);
        $this->assertSame('Ground floor', $unit['floor']);
        $this->assertCount(2, $unit['financing']);
        $this->assertSame('HDMF Financing', $unit['financing'][0]['option']);
        $this->assertSame(498000, $unit['financing'][0]['equity_total']);
        $this->assertSame(20750, $unit['financing'][0]['equity_monthly']);
        $this->assertSame('Bank Financing', $unit['financing'][1]['option']);
        $this->assertSame(300000, $unit['financing'][1]['equity_total']);
        $this->assertSame(12500, $unit['financing'][1]['equity_monthly']);
    }

    public function test_long_equity_term_with_no_discount(): void
    {
        $unit = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'][3];

        $this->assertSame('J-3F (322)', $unit['code']);
        $this->assertSame('3rd floor', $unit['floor']);
        $this->assertSame(36, $unit['financing'][0]['equity_months']);
        $this->assertNull($unit['financing'][0]['discount']);
        $this->assertNull($unit['financing'][1]['discount']);
    }

    public function test_figures_are_stored_verbatim_even_when_the_package_total_does_not_reconcile(): void
    {
        // N-4F (401): discount + reservation fee + equity + loanable != total
        // package on Johndorf's own page. parse() quotes what the page says
        // rather than silently "fixing" the arithmetic.
        $unit = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'][4];

        $this->assertSame('N-4F (401)', $unit['code']);
        $this->assertSame(3675000, $unit['total_package']);
        $this->assertSame(100000, $unit['financing'][0]['discount']);
        $this->assertSame(377000, $unit['financing'][0]['equity_total']);
        $this->assertSame(3203000, $unit['financing'][0]['loanable_amount']);
        $this->assertSame(15000, $unit['reservation_fee']);
    }

    public function test_tenth_floor_ordinal(): void
    {
        $unit = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera')['units'][6];

        $this->assertSame('T-10F (1037)', $unit['code']);
        $this->assertSame('10th floor', $unit['floor']);
        $this->assertSame(1037, $unit['unit_number']);
    }

    public function test_requirements_days_is_read_from_whichever_row_shows_it(): void
    {
        $result = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Plumera');

        $this->assertSame(7, $result['requirements_days']);
    }

    public function test_block_lot_rows_for_a_different_project(): void
    {
        $result = JohndorfInventory::parse($this->fixture('inventory-40.html'), 'tierranava-carcar', 'Tierra Nava');

        $this->assertSame('listed', $result['inventory_state']);
        $this->assertSame(2, $result['available_count']);
        $unit = $result['units'][0];
        $this->assertSame('24-27', $unit['code']);
        $this->assertSame('Block 24 · Lot 27', $unit['name']);
        $this->assertNull($unit['building']);
        $this->assertSame('24', $unit['block']);
        $this->assertSame('27', $unit['lot']);
        $this->assertSame('Two-Storey Townhouse', $unit['unit_type']);
        $this->assertSame(2300000, $unit['price']);
        $this->assertSame(20000, $unit['reservation_fee']);
        $this->assertSame([
            'option' => 'Bank Financing',
            'discount' => null,
            'required_income' => 65000,
            'loanable_amount' => 2220000,
            'monthly_amortization' => 17884.17,
            'equity_months' => 4,
            'equity_total' => 60000,
            'equity_monthly' => 15000,
        ], $unit['financing'][0]);
    }

    public function test_sold_out_sentinel_yields_no_available_units(): void
    {
        $result = JohndorfInventory::parse($this->fixture('inventory-43.html'), 'mimosa-minglanilla', 'Mimosa Minglanilla');

        $this->assertSame('no_available_units', $result['inventory_state']);
        $this->assertSame(0, $result['available_count']);
        $this->assertSame([], $result['units']);
    }

    public function test_title_mismatch_is_an_error_not_an_empty_result(): void
    {
        $result = JohndorfInventory::parse($this->fixture('inventory-41-sample.html'), 'plumera', 'Some Other Project');

        $this->assertSame('error', $result['inventory_state']);
        $this->assertNotSame([], $result['errors']);
    }

    public function test_a_row_missing_its_projectunitid_fails_the_row_count_check(): void
    {
        $html = str_replace('ProjectUnitID=5404', 'ProjectUnitID=', $this->fixture('inventory-41-sample.html'));

        $result = JohndorfInventory::parse($html, 'plumera', 'Plumera');

        $this->assertSame('error', $result['inventory_state']);
    }
}
