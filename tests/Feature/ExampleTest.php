<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test that root URL redirects to the custom page settings.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/custom-page');
    }

    /**
     * Test that oauth_result view renders cleanly.
     */
    public function test_oauth_result_page_renders_successfully(): void
    {
        $response = $this->view('oauth_result', [
            'success'    => true,
            'isAgency'   => false,
            'locationId' => 'loc_test_123',
        ]);

        $response->assertSee('Sub-Account Connected Successfully!');
        $response->assertSee('loc_test_123');
    }
}
