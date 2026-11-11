<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendRoutesTest extends TestCase
{
    public function test_home_page_renders_bdms_landing_view(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Current Blood Group Availability');
        $response->assertSee('Register as Donor');
    }

    public function test_authentication_pages_render_successfully(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to BDMS');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your BDMS account');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?');
    }

    public function test_dashboard_renders_role_specific_portals_and_falls_back_safely(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Blood Bank Operations Dashboard');

        $this->get(route('dashboard', ['role' => 'donor']))
            ->assertOk()
            ->assertSee('Donor Portal Overview');

        $this->get(route('dashboard', ['role' => 'hospital']))
            ->assertOk()
            ->assertSee('Hospital Requester Portal');

        $this->get(route('dashboard', ['role' => 'unknown-role']))
            ->assertOk()
            ->assertSee('Blood Bank Operations Dashboard');
    }
}
