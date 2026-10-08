<?php

namespace Database\Factories;

use App\Models\RealtyAccreditation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An invite that is out and not yet filled in. Pick the developer with
 * ->for($developer, 'developer'); the states move it along the way.
 *
 * @extends Factory<RealtyAccreditation>
 */
class RealtyAccreditationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(RealtyAccreditation::DAYS_VALID),
            'status' => RealtyAccreditation::STATUS_INVITED,
        ];
    }

    /** The link ran out before the realty filled the form in. */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    /** The realty filled the form in: waiting for the developer's staff. */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => RealtyAccreditation::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_ip' => '203.0.113.7',
            'business_type' => RealtyAccreditation::TYPE_CORPORATION,
            'firm_name' => fake()->unique()->company(),
            'residential_address' => '12 Colon Street, Cebu City',
            'tin_company' => '123-456-789-000',
            'tin_personal' => '987-654-321-000',
            'prc_number' => 'PRC-100200',
            'prc_valid_until' => now()->addYear()->toDateString(),
            'representative_name' => fake()->name(),
            'place_of_birth' => 'Cebu City',
            'date_of_birth' => '1985-04-12',
            'citizenship' => 'Filipino',
            'gender' => 'female',
            'civil_status' => 'Married',
            'mobile' => '09171234567',
            'login_email' => fake()->unique()->safeEmail(),
            'years_in_real_estate' => 8,
            'years_firm_operating' => 5,
            'salespersons' => 12,
        ]);
    }

    /** A sole proprietor, which needs no company TIN and attaches a DTI registration. */
    public function soleProprietor(): static
    {
        return $this->state(fn () => ['business_type' => RealtyAccreditation::TYPE_SOLE_PROPRIETOR, 'tin_company' => null]);
    }

    public function rejected(string $note = 'The PRC registration is unreadable.'): static
    {
        return $this->state(fn () => ['status' => RealtyAccreditation::STATUS_REJECTED, 'review_note' => $note, 'reviewed_at' => now()]);
    }
}
