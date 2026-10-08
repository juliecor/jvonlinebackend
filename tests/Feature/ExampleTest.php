<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_api_root_redirects_to_the_public_site(): void
    {
        config(['app.frontend_url' => 'https://jvconline.ph/']);

        $this->get('/')
            ->assertStatus(301)
            ->assertRedirect('https://jvconline.ph');
    }
}
