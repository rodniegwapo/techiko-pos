<?php

namespace Tests\Feature;

use App\Events\StaffInboxBadgeUpdated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Inertia;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_message(): void
    {
        $this->post(route('inquiries.store'), ['body' => 'Hello'])->assertRedirect();
        $this->assertGuest();
    }

    public function test_authenticated_user_can_create_message_in_conversation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->from('/dashboard')
            ->post(route('inquiries.store'), ['body' => 'Need help with invoice'])
            ->assertRedirect();

        $c = Conversation::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($c);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $c->id,
            'author_user_id' => $user->id,
            'body' => 'Need help with invoice',
        ]);
    }

    public function test_non_superuser_cannot_view_messages_index(): void
    {
        $user = User::factory()->create(['is_super_user' => false]);
        $this->actingAs($user);

        $this->from('/dashboard')
            ->get(route('messages.index'))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error', 'You do not have permission to access this page.');
    }

    public function test_superuser_can_view_messages_index(): void
    {
        $admin = User::factory()->create(['is_super_user' => true]);
        $this->actingAs($admin);

        $this->get(route('messages.index'))
            ->assertOk()
            ->assertInertia(
                fn (Inertia $page) => $page
                    ->component('Messages/Index')
                    ->has('conversations')
            );
    }

    public function test_superuser_can_view_thread_and_post_reply(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_super_user' => true]);
        $conversation = Conversation::query()->create(['user_id' => $user->id]);
        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'author_user_id' => $user->id,
            'body' => 'From customer',
        ]);

        $this->actingAs($admin);

        $this->get(route('messages.index', ['c' => $conversation->id]))
            ->assertOk()
            ->assertInertia(
                fn (Inertia $page) => $page
                    ->has('thread')
            );

        $this->from(route('messages.index', ['c' => $conversation->id]))
            ->post(
                route('messages.staff', $conversation),
                ['body' => 'We are on it.']
            )
            ->assertRedirect();

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'author_user_id' => $admin->id,
            'body' => 'We are on it.',
        ]);
    }

    public function test_customer_cannot_read_other_conversation_json(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = Conversation::query()->create(['user_id' => $b->id]);
        $this->actingAs($a);
        $this->getJson(route('conversations.messages', $c))->assertForbidden();
    }

    public function test_staff_inbox_badge_event_dispatched_when_customer_sends_message(): void
    {
        Event::fake([StaffInboxBadgeUpdated::class]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->from('/dashboard')
            ->post(route('inquiries.store'), ['body' => 'Need support']);
        Event::assertDispatched(StaffInboxBadgeUpdated::class, function (StaffInboxBadgeUpdated $e) {
            return $e->unread_conversation_count >= 1;
        });
    }

    public function test_staff_inbox_badge_event_not_dispatched_when_staff_sends_reply(): void
    {
        Event::fake([StaffInboxBadgeUpdated::class]);
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_super_user' => true]);
        $conversation = Conversation::query()->create(['user_id' => $user->id]);
        $this->actingAs($admin);
        $this->from('/dashboard')
            ->post(
                route('messages.staff', $conversation),
                ['body' => 'Staff reply only']
            );
        Event::assertNotDispatched(StaffInboxBadgeUpdated::class);
    }
}
