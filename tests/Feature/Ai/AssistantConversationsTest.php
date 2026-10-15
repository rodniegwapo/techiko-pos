<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\AppAssistantAgent;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\AiUsage;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Domain;
use App\Models\Permission;
use App\Models\User;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\PendingActions;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Each user's saved AI assistant chats: kept per user, continued with their history, and pruned after 90 days. */
class AssistantConversationsTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'assistant.ask', 'assistant.actions.confirm', 'assistant.actions.cancel',
        'assistant.conversations.index', 'assistant.conversations.show', 'assistant.conversations.destroy',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        config(['services.app_assistant.provider' => 'gemini', 'ai.providers.gemini.key' => 'test-key']);
    }

    private function domain(): Domain
    {
        return Domain::query()->create([
            'name' => 'Chat Org',
            'name_slug' => 'chat-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true]],
        ]);
    }

    private function member(Domain $domain): User
    {
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        foreach (self::ROUTES as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => 'Test '.$routeName],
            ));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function url(string $name, Domain $domain, array $params = []): string
    {
        return route('domains.assistant.'.$name, ['domain' => $domain->name_slug, ...$params]);
    }

    private function chat(User $user, array $attributes = []): AssistantConversation
    {
        return AssistantConversation::query()->create([
            'domain' => $user->domain, 'user_id' => $user->id, 'title' => 'A chat', 'last_message_at' => now(), ...$attributes,
        ]);
    }

    public function test_asking_saves_the_chat_and_continues_it_with_its_history(): void
    {
        AppAssistantAgent::fake(['Open Products.', 'Then press Add.']);
        $domain = $this->domain();
        $user = $this->member($domain);

        $first = $this->actingAs($user)->postJson($this->url('ask', $domain), ['message' => 'How do I add a product?', 'page' => 'domains.dashboard'])
            ->assertOk()
            ->assertJsonPath('answer', 'Open Products.');

        $chat = AssistantConversation::query()->sole();
        $this->assertSame($first->json('conversation_id'), $chat->id);
        $this->assertSame([$user->id, $domain->name_slug, 'How do I add a product?'], [$chat->user_id, $chat->domain, $chat->title]);
        $message = AssistantMessage::query()->sole();
        $this->assertSame(['How do I add a product?', 'Open Products.', 'domains.dashboard'], [$message->question, $message->answer, $message->page]);
        $this->assertSame(AiUsage::query()->sole()->id, $message->ai_usage_id);

        $this->actingAs($user)->postJson($this->url('ask', $domain), ['message' => 'And then?', 'conversation_id' => $chat->id])
            ->assertOk()
            ->assertJsonPath('conversation_id', $chat->id);

        $this->assertSame(2, $chat->messages()->count());
        AppAssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('And then?')
            && collect($prompt->agent->messages())->pluck('content')->all() === ['How do I add a product?', 'Open Products.']);
    }

    public function test_a_chat_is_only_continued_opened_or_deleted_by_its_owner(): void
    {
        AppAssistantAgent::fake()->preventStrayPrompts();
        $domain = $this->domain();
        $owner = $this->member($domain);
        $colleague = $this->member($domain);
        $chat = $this->chat($owner);
        $this->chat($colleague, ['title' => 'Not yours']);

        $this->actingAs($colleague)->postJson($this->url('ask', $domain), ['message' => 'Hello there', 'conversation_id' => $chat->id])->assertNotFound();
        $this->actingAs($colleague)->getJson($this->url('conversations.show', $domain, ['conversation' => $chat->id]))->assertNotFound();
        $this->actingAs($colleague)->deleteJson($this->url('conversations.destroy', $domain, ['conversation' => $chat->id]))->assertNotFound();
        $this->assertNotNull($chat->fresh());

        $this->actingAs($owner)->getJson($this->url('conversations.index', $domain))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'A chat');
    }

    public function test_a_chat_opens_as_turns_and_can_be_deleted(): void
    {
        $domain = $this->domain();
        $user = $this->member($domain);
        $chat = $this->chat($user);
        $chat->messages()->create(['question' => 'Q1', 'answer' => 'A1', 'links' => [['title' => 'Products', 'url' => '/x']]]);
        $chat->messages()->create(['question' => 'Q2', 'answer' => 'A2']);

        $this->actingAs($user)->getJson($this->url('conversations.show', $domain, ['conversation' => $chat->id]))
            ->assertOk()
            ->assertJsonPath('turns.0.question', 'Q1')
            ->assertJsonPath('turns.0.links.0.title', 'Products')
            ->assertJsonPath('turns.1.answer', 'A2')
            ->assertJsonPath('turns.1.actions', []);

        $this->actingAs($user)->deleteJson($this->url('conversations.destroy', $domain, ['conversation' => $chat->id]))->assertOk();
        $this->assertSame(0, AssistantMessage::query()->count());
    }

    public function test_confirming_or_cancelling_a_card_is_recorded_on_its_message(): void
    {
        $domain = $this->domain();
        $user = $this->member($domain);
        $pending = app(PendingActions::class);
        $action = \Mockery::mock(AssistantAction::class);
        $action->shouldReceive('name')->andReturn('test_action');
        $context = new ActionContext($user, $domain, null);
        $cardA = $pending->put($action, new PreparedAction('Change A', ['x' => 1], []), $context);
        $cardB = $pending->put($action, new PreparedAction('Change B', ['x' => 2], []), $context);
        $message = $this->chat($user)->messages()->create(['question' => 'Q', 'answer' => 'Two changes.', 'pending_actions' => [$cardA, $cardB]]);
        $pending->attachMessage($cardA['token'], $message->id);
        $pending->attachMessage($cardB['token'], $message->id);

        $this->actingAs($user)->postJson($this->url('actions.cancel', $domain, ['token' => $cardB['token']]))->assertOk();

        $actions = $message->fresh()->pending_actions;
        $this->assertArrayNotHasKey('state', $actions[0]);
        $this->assertSame('cancelled', $actions[1]['state']);
        $this->assertSame("Two changes.\n[Change B: cancelled - Cancelled. Nothing was changed.]", $message->fresh()->answerWithOutcomes());
    }

    public function test_chats_idle_for_90_days_are_pruned(): void
    {
        $domain = $this->domain();
        $user = $this->member($domain);
        $old = $this->chat($user, ['last_message_at' => now()->subDays(91)]);
        $old->messages()->create(['question' => 'Old', 'answer' => 'Old']);
        $recent = $this->chat($user, ['last_message_at' => now()->subDays(89)]);

        $this->artisan('model:prune', ['--model' => [AssistantConversation::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertSame(0, AssistantMessage::query()->count());
    }
}
