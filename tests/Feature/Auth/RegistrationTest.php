<?php

namespace Tests\Feature\Auth;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        Event::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('registration.thankyou'));

        Event::assertDispatched(Registered::class);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('active', $user->status);

        $domain = Domain::where('name_slug', $user->domain)->first();
        $this->assertNotNull($domain);
        $this->assertTrue((bool) $domain->is_active);
    }

    public function test_registration_submission_is_rate_limited(): void
    {
        config(['registration.throttle.submit_per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $response = $this->post('/register', [
                'name' => 'Test User '.$i,
                'email' => 'throttle-test-'.$i.'@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'organization' => 'Throttle Org '.$i,
            ]);
            $response->assertRedirect(route('registration.thankyou'));
        }

        $response = $this->post('/register', [
            'name' => 'Test User Over',
            'email' => 'throttle-test-over@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'organization' => 'Throttle Org Over',
        ]);
        $response->assertStatus(429);
    }
}
