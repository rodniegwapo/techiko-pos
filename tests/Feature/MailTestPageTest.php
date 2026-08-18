<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Inertia;
use Tests\TestCase;

class MailTestPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_mail_test_page(): void
    {
        $this->get(route('mail.test'))->assertRedirect(route('login'));
    }

    public function test_non_superuser_cannot_view_mail_test_page(): void
    {
        $user = User::factory()->create(['is_super_user' => false]);
        $this->actingAs($user);

        $this->from('/dashboard')
            ->get(route('mail.test'))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error', 'You do not have permission to access this page.');
    }

    public function test_superuser_can_view_mail_test_page(): void
    {
        $admin = User::factory()->create(['is_super_user' => true]);
        $this->actingAs($admin);

        $this->get(route('mail.test'))
            ->assertOk()
            ->assertInertia(
                fn (Inertia $page) => $page->component('Mail/Test')
            );
    }

    public function test_superuser_can_send_test_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_super_user' => true]);
        $this->actingAs($admin);

        $this->from(route('mail.test'))
            ->post(route('mail.test.send'), [
                'email' => 'recipient@example.com',
            ])
            ->assertRedirect(route('mail.test'))
            ->assertSessionHas('success', 'Test email sent to recipient@example.com.');

        Mail::assertSent(TestEmail::class, function (TestEmail $mail) {
            return $mail->hasTo('recipient@example.com');
        });
    }

    public function test_mail_test_requires_valid_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_super_user' => true]);
        $this->actingAs($admin);

        $this->from(route('mail.test'))
            ->post(route('mail.test.send'), [
                'email' => 'not-an-email',
            ])
            ->assertRedirect(route('mail.test'))
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_non_superuser_cannot_send_test_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_super_user' => false]);
        $this->actingAs($user);

        $this->from('/dashboard')
            ->post(route('mail.test.send'), [
                'email' => 'recipient@example.com',
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error', 'You do not have permission to access this page.');

        Mail::assertNothingSent();
    }
}
