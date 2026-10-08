<?php

namespace Database\Factories;

use App\Models\AccreditationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A PDF attached to an accreditation. Pick the form with ->for($accreditation, 'accreditation').
 *
 * @extends Factory<AccreditationDocument>
 */
class AccreditationDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'sec_registration',
            'path' => 'accreditations/0/'.fake()->uuid().'.pdf',
            'original_name' => 'sec-registration.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
        ];
    }
}
