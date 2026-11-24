<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_root_redirects_to_the_dashboard()
    {
        $this->get(route('home'))->assertRedirect('/dashboard');
    }

    public function test_a_guest_following_the_root_redirect_ends_up_at_login()
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_no_welcome_page_component_remains()
    {
        $this->assertFileDoesNotExist(resource_path('js/pages/welcome.tsx'));
        $this->assertFileDoesNotExist(resource_path('js/layouts/bare-layout.tsx'));
    }
}
