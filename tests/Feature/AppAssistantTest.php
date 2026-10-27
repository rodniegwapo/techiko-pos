<?php

namespace Tests\Feature;

use App\Ai\Agents\AppAssistantAgent;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\AssistantConversation;
use App\Models\AssistantUnansweredQuestion;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Ai\Actions\PendingActions;
use App\Services\Ai\AppAssistant;
use App\Services\Ai\AppMap;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\GuideLibrary;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The AI assistant in the chat drawer: how-to guides and confirmed changes, limited to what each
 * role may do.
 */
class AppAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        config(['services.app_assistant.provider' => 'gemini', 'ai.providers.gemini.key' => 'test-key']);
    }

    /** @return array{domain: Domain, location: InventoryLocation, user: User} A staff member (not a super user) holding only these permissions. */
    private function staff(array $routeNames, bool $ai = true): array
    {
        $domain = Domain::query()->create([
            'name' => 'Assistant Org',
            'name_slug' => 'assistant-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => $ai]],
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false, 'location_id' => $location->id]);

        return ['domain' => $domain, 'location' => $location, 'user' => $this->grant($user, $routeNames)];
    }

    private function grant(User $user, array $routeNames): User
    {
        foreach ($routeNames as $routeName) {
            $permission = Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => 'Test '.$routeName.' '.Str::uuid()],
            );
            $user->givePermissionTo($permission);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    /** An admin who may ask, confirm, and make every catalog and store change. */
    private function admin(): array
    {
        return $this->staff([
            'assistant.ask', 'assistant.actions.confirm', 'assistant.actions.cancel',
            'products.index', 'products.store', 'products.update', 'categories.index', 'categories.store',
            'settings.index', 'settings.update',
            'inventory.locations.index', 'inventory.locations.store', 'inventory.locations.update',
            'inventory.locations.set-default', 'inventory.locations.toggle-status',
        ]);
    }

    private function tools(array $ctx): AssistantTools
    {
        return new AssistantTools(app(GuideLibrary::class), $ctx['user'], $ctx['domain'], $ctx['location']);
    }

    /** Proposes a change through the AI's tool and returns its confirm-card token. */
    private function propose(array $ctx, string $action, array $input): string
    {
        $tools = $this->tools($ctx);
        $result = json_decode($tools->run('propose_'.$action, $input), true);
        $this->assertSame('awaiting_confirmation', $result['status'] ?? null, json_encode($result));

        return $tools->pendingActions()[0]['token'];
    }

    private function confirm(array $ctx, string $token, ?User $as = null)
    {
        return $this->actingAs($as ?? $ctx['user'])->postJson(route('domains.assistant.actions.confirm', [
            'domain' => $ctx['domain']->name_slug, 'token' => $token,
        ]));
    }

    private function askUrl(Domain $domain): string
    {
        return route('domains.assistant.ask', ['domain' => $domain->name_slug]);
    }

    public function test_every_guide_names_real_routes(): void
    {
        $guides = app(GuideLibrary::class)->all();

        $this->assertArrayHasKey('add-product', $guides);
        foreach ($guides as $guide) {
            $this->assertNotSame('', $guide['steps'], $guide['key'].' has no steps');
            foreach (array_filter([$guide['page'], ...$guide['requires']]) as $routeName) {
                $this->assertTrue(
                    Route::has('domains.'.$routeName) || Route::has($routeName),
                    "Guide {$guide['key']} names the unknown route {$routeName}",
                );
            }
        }
    }

    public function test_guides_follow_the_users_permissions(): void
    {
        $cashier = $this->staff(['sales.index'])['user'];
        $owner = $this->staff(['sales.index', 'products.create', 'products.store'])['user'];
        $library = app(GuideLibrary::class);

        $this->assertArrayHasKey('make-a-sale', $library->allowedFor($cashier));
        $this->assertArrayNotHasKey('add-product', $library->allowedFor($cashier));
        $this->assertArrayHasKey('add-product', $library->allowedFor($owner));

        $this->assertSame('add-product', $library->search($owner, 'how do I add a product?')[0]['key']);
        $this->assertSame([], $library->search($cashier, 'add product'));
    }

    /** How staff actually ask, in English and Taglish, and the guide that should come up (top 3). */
    public static function commonQuestions(): array
    {
        return [
            ['paano mag void ng benta', 'void-sale'],
            ['how do I void a sale', 'void-sale'],
            ['how to add stock from supplier delivery', 'receive-stock'],
            ['lipat stock sa ibang branch', 'transfer-stock'],
            ['transfer stock to another store', 'transfer-stock'],
            ['record utang payment', 'credit-payment'],
            ['bayad sa utang ng customer', 'credit-payment'],
            ['add new cashier', 'add-user'],
            ['senior discount', 'pwd-senior-discount'],
            ['pwd discount', 'pwd-senior-discount'],
            ['split payment cash and gcash', 'split-payment'],
            ['customer pays with gcash', 'card-ewallet-payment'],
            ['damaged items stock', 'stock-adjustment'],
            ['expired na products', 'stock-adjustment'],
            ['approve stock adjustment', 'approve-adjustment'],
            ['reprint receipt', 'find-receipt'],
            ['walang internet paano magbenta', 'offline-sales'],
            ['set credit limit for customer', 'credit-limit'],
            ['who is overdue', 'overdue-credit'],
            ['add loyalty points to customer', 'adjust-loyalty-points'],
            ['set pin for manager', 'user-pin'],
            ['low stock items', 'low-stock-reorder'],
            ['stock value', 'inventory-valuation'],
            ['ilan pa stock', 'check-stock'],
            ['export sales to excel', 'export-sales'],
            ['add new customer', 'add-customer'],
            ['record electricity expense', 'record-expense'],
            ['bayad sa kuryente meralco', 'record-expense'],
            ['rent every month automatic', 'recurring-expense'],
            ['record supplier bill', 'supplier-bill'],
            ['add a new supplier', 'add-supplier'],
            ['pay supplier', 'pay-supplier-bill'],
            ['bank balance gcash balance', 'bank-ewallet-balance'],
            ['loan from bank repayment', 'record-loan'],
            ['new freezer equipment', 'record-asset'],
            ['owner added capital puhunan', 'owner-investment'],
            ['how much profit kita this month', 'finance-reports'],
            ['vat report for bir', 'vat-report'],
            ['opening cash float', 'opening-cash'],
            ['count cash end shift', 'end-shift'],
            ['owner took cash out', 'cash-in-out'],
            ['add gcash payment option', 'payment-channels'],
            ['create payday promo', 'create-discount'],
            ['add sizes small medium large', 'modifier-groups'],
            ['add-ons sugar level', 'modifier-groups'],
            ['set senior discount rate', 'mandatory-discounts'],
            ['upgrade plan product limit', 'servicing-payment'],
        ];
    }

    #[DataProvider('commonQuestions')]
    public function test_common_questions_find_the_right_guide(string $question, string $guide): void
    {
        $owner = User::factory()->create(['is_super_user' => true]);

        $found = array_column(app(GuideLibrary::class)->search($owner, $question, 3), 'key');

        $this->assertContains($guide, $found, "\"{$question}\" found: ".implode(', ', $found));
    }

    public function test_finance_guides_need_the_finance_feature(): void
    {
        $ctx = $this->staff(['expenses.index', 'expenses.store', 'finance.dashboard']);
        $library = app(GuideLibrary::class);

        // The role allows it, but the business has no Finance: no Finance guides, and a clear reason.
        $this->assertArrayHasKey('record-expense', $library->allowedFor($ctx['user']));
        $this->assertArrayNotHasKey('record-expense', $library->allowedFor($ctx['user'], $ctx['domain']));
        $this->assertSame([], array_values(array_filter(
            array_column($library->search($ctx['user'], 'record electricity expense', 5, $ctx['domain']), 'key'),
            fn ($key) => $key === 'record-expense',
        )));
        $refused = json_decode($this->tools($ctx)->run('get_guide', ['key' => 'record-expense']), true);
        $this->assertSame('not_available', $refused['error']);

        $ctx['domain']->update(['settings' => ['features' => ['ai' => true, 'finance' => true]]]);
        $this->assertArrayHasKey('record-expense', $library->allowedFor($ctx['user'], $ctx['domain']->fresh()));
        $this->assertArrayHasKey('steps', json_decode($this->tools(['domain' => $ctx['domain']->fresh()] + $ctx)->run('get_guide', ['key' => 'record-expense']), true));
    }

    public function test_guides_for_sales_inventory_customers_and_users_follow_permissions(): void
    {
        $library = app(GuideLibrary::class);

        $cashier = array_keys($library->allowedFor($this->staff(['sales.index'])['user']));
        foreach (['make-a-sale', 'sale-discount', 'pwd-senior-discount', 'split-payment', 'redeem-loyalty', 'void-item-in-cart'] as $key) {
            $this->assertContains($key, $cashier);
        }
        foreach (['void-sale', 'receive-stock', 'add-user', 'credit-payment', 'approve-adjustment'] as $key) {
            $this->assertNotContains($key, $cashier);
        }

        $stockClerk = array_keys($library->allowedFor($this->staff(['inventory.products', 'inventory.receive', 'inventory.transfer', 'inventory.movements'])['user']));
        foreach (['check-stock', 'receive-stock', 'transfer-stock', 'stock-movements'] as $key) {
            $this->assertContains($key, $stockClerk);
        }
        $this->assertNotContains('approve-adjustment', $stockClerk);
    }

    public function test_tools_teach_allowed_tasks_and_refuse_the_rest(): void
    {
        $cashier = $this->staff(['sales.index']);
        $tools = new AssistantTools(app(GuideLibrary::class), $cashier['user'], $cashier['domain']);

        $search = json_decode($tools->run('search_guides', ['query' => 'add product']), true);
        $this->assertSame([], $search['guides']);
        $this->assertContains('Add a product', $search['not_allowed_for_this_role']);

        $refused = json_decode($tools->run('get_guide', ['key' => 'add-product']), true);
        $this->assertSame('not_allowed', $refused['error']);
        $this->assertSame([], $tools->links());

        $guide = json_decode($tools->run('get_guide', ['key' => 'make-a-sale']), true);
        $this->assertStringContainsString('Proceed Payment', $guide['steps']);
        $this->assertSame(
            [['title' => 'Make a sale', 'url' => route('domains.sales.index', ['domain' => $cashier['domain']->name_slug])]],
            $tools->links(),
        );
    }

    public function test_assistant_answers_through_the_agent(): void
    {
        AppAssistantAgent::fake(['1. Open **Products → Items**.'])->preventStrayPrompts();
        $ctx = $this->staff(['assistant.ask', 'products.create', 'products.store']);

        $reply = app(AppAssistant::class)->reply('How do I add a product?', [], $ctx['user'], $ctx['domain'], $ctx['location'], 'domains.products.index', 'taglish');

        $this->assertSame(['answer' => '1. Open **Products → Items**.', 'links' => [], 'pending_actions' => [], 'forms' => [], 'tools_called' => []], $reply);
        AppAssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('How do I add a product?') && $prompt->contains('Taglish'));
    }

    public function test_the_fallback_provider_answers_when_the_first_is_turned_away(): void
    {
        config([
            'services.app_assistant.provider' => 'openai', 'services.app_assistant.model' => 'gpt-6-luna',
            'services.app_assistant.fallback_provider' => 'gemini', 'services.app_assistant.fallback_model' => 'gemini-3.8-flash',
            'services.app_assistant.timeout' => 20,
            'ai.providers.openai.key' => 'test-key', 'ai.providers.gemini.key' => 'test-key',
        ]);
        $tried = [];
        AppAssistantAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$tried) {
            $tried[] = [$provider->name(), $model];
            if ($provider->name() === 'openai') {
                throw RateLimitedException::forProvider('openai');
            }

            return 'Answered by the backup.';
        });
        $ctx = $this->staff(['assistant.ask']);

        $reply = app(AppAssistant::class)->reply('Where are my sales?', [], $ctx['user'], $ctx['domain'], $ctx['location']);

        $this->assertSame('Answered by the backup.', $reply['answer']);
        $this->assertSame([['openai', 'gpt-6-luna'], ['gemini', 'gemini-3.8-flash']], $tried);
        AppAssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->timeout === 20);
    }

    public function test_a_fallback_without_its_key_is_left_out(): void
    {
        config([
            'services.app_assistant.provider' => 'openai', 'services.app_assistant.model' => 'gpt-6-luna',
            'services.app_assistant.fallback_provider' => 'gemini', 'services.app_assistant.fallback_model' => 'gemini-3.8-flash',
            'ai.providers.openai.key' => 'test-key', 'ai.providers.gemini.key' => '',
        ]);
        $tried = [];
        AppAssistantAgent::fake(function (string $prompt, $attachments, $provider) use (&$tried) {
            $tried[] = $provider->name();

            throw RateLimitedException::forProvider('openai');
        });
        $ctx = $this->staff(['assistant.ask']);

        try {
            app(AppAssistant::class)->reply('Where are my sales?', [], $ctx['user'], $ctx['domain'], $ctx['location']);
            $this->fail('expected the rate limit to reach the user');
        } catch (\RuntimeException $e) {
            $this->assertSame('The AI assistant is busy right now. Please try again in a minute.', $e->getMessage());
        }
        $this->assertSame(['openai'], $tried);

        // With only the fallback's key, the fallback alone is used, so the assistant still counts as set up.
        config(['ai.providers.openai.key' => '', 'ai.providers.gemini.key' => 'test-key']);
        $this->assertTrue(app(AppAssistant::class)->isConfigured());
        config(['ai.providers.gemini.key' => '']);
        $this->assertFalse(app(AppAssistant::class)->isConfigured());
    }

    public function test_ask_endpoint_answers_within_the_daily_limit(): void
    {
        config(['services.app_assistant.daily_limit' => 2]);
        AppAssistantAgent::fake(['Here are the steps.', 'Here are the steps.']);
        $ctx = $this->staff(['assistant.ask']);

        $ask = fn () => $this->actingAs($ctx['user'])->postJson($this->askUrl($ctx['domain']), ['message' => 'How do I add a product?']);

        $ask()->assertOk()->assertJsonPath('answer', 'Here are the steps.')->assertJsonPath('remaining', 1)->assertJsonMissingPath('tools_called');
        $ask()->assertOk()->assertJsonPath('remaining', 0);
        $ask()->assertStatus(429);
    }

    public function test_ask_needs_the_assistant_permission_and_ai_credits(): void
    {
        AppAssistantAgent::fake()->preventStrayPrompts();

        $noPermission = $this->staff(['sales.index']);
        $this->actingAs($noPermission['user'])
            ->postJson($this->askUrl($noPermission['domain']), ['message' => 'How do I add a product?'])
            ->assertForbidden();

        // Every plan has the assistant; a super admin turns it off with 0 credits.
        $noCredits = $this->staff(['assistant.ask'], ai: false);
        $noCredits['domain']->update(['ai_monthly_credits_override' => 0]);
        $this->actingAs($noCredits['user'])
            ->postJson($this->askUrl($noCredits['domain']), ['message' => 'How do I add a product?'])
            ->assertStatus(402);
    }

    public function test_ask_reports_when_the_ai_is_not_set_up(): void
    {
        config(['ai.providers.gemini.key' => '']);
        $ctx = $this->staff(['assistant.ask']);

        $this->actingAs($ctx['user'])
            ->postJson($this->askUrl($ctx['domain']), ['message' => 'How do I add a product?'])
            ->assertStatus(503);
    }

    public function test_change_tools_follow_the_users_permissions(): void
    {
        $names = fn (array $ctx) => array_map(fn ($tool) => $tool->name(), $this->tools($ctx)->tools());

        $cashier = $names($this->staff(['sales.index']));
        $this->assertSame([], array_values(array_filter($cashier, fn ($n) => str_starts_with($n, 'propose_'))));
        $this->assertNotContains('list_stores', $cashier);

        $admin = $names($this->admin());
        $this->assertContains('propose_create_product', $admin);
        $this->assertContains('propose_update_sales_settings', $admin);
        $this->assertContains('propose_set_default_store', $admin);
        $this->assertContains('find_products', $admin);

        // Asking for a change the role can't make is refused even if the AI tries.
        $cashierCtx = $this->staff(['sales.index']);
        $refused = json_decode($this->tools($cashierCtx)->run('propose_create_product', ['name' => 'Fries', 'price' => 50]), true);
        $this->assertSame('not_allowed', $refused['error']);
    }

    public function test_the_add_product_form_is_built_by_the_server_for_allowed_users(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        Category::factory()->create(['domain' => $ctx['domain']->name_slug, 'name' => 'Drinks']);
        Category::factory()->create(['domain' => $ctx['domain']->name_slug, 'name' => 'Burgers']);
        Category::factory()->create(['domain' => $this->staff([])['domain']->name_slug, 'name' => 'Not Ours']);

        $tools = $this->tools($ctx);
        $this->assertContains('show_fill_in_form', array_map(fn ($tool) => $tool->name(), $tools->tools()));
        $shown = json_decode($tools->run('show_fill_in_form', ['action' => 'create_product']), true);
        $this->assertSame('form_shown', $shown['status']);
        $this->assertFalse($shown['page_link_shown_to_user']);

        [$form] = $tools->forms();
        $this->assertSame('create_product', $form['action']);
        $this->assertSame('Add product', $form['title']);
        $lines = explode("\n", $form['text']);
        $this->assertSame('Name: ', $lines[0]);
        $this->assertSame('Price (₱): ', $lines[1]);
        $this->assertSame('Category (one of: Burgers, Drinks; optional): ', $lines[2]);
        $this->assertStringNotContainsString('Not Ours', $form['text']);
        // This admin may save products but not open the Create Product page, so no page button.
        $this->assertSame([], $tools->links());

        // With the page allowed too, its button comes with the form.
        $withPage = $this->tools(['user' => $this->grant($ctx['user'], ['products.create'])] + $ctx);
        $shown = json_decode($withPage->run('show_fill_in_form', ['action' => 'create_product']), true);
        $this->assertTrue($shown['page_link_shown_to_user']);
        $this->assertSame(
            [['title' => 'Add a product', 'url' => route('domains.products.create', ['domain' => $ctx['domain']->name_slug])]],
            $withPage->links(),
        );

        // A role that can't add products gets no form tool, and no form even if the AI asks for one.
        $cashier = $this->tools($this->staff(['sales.index']));
        $this->assertNotContains('show_fill_in_form', array_map(fn ($tool) => $tool->name(), $cashier->tools()));
        $this->assertSame('not_allowed', json_decode($cashier->run('show_fill_in_form', ['action' => 'create_product']), true)['error']);
        $this->assertSame([], $cashier->forms());
    }

    public function test_a_filled_in_product_form_may_name_the_category(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        $drinks = Category::factory()->create(['domain' => $ctx['domain']->name_slug, 'name' => 'Drinks']);
        Category::factory()->create(['domain' => $this->staff([])['domain']->name_slug, 'name' => 'Snacks']);

        $token = $this->propose($ctx, 'create_product', ['name' => 'Coke 1L', 'price' => 65, 'category' => ' drinks ']);
        $this->confirm($ctx, $token)->assertOk();
        $this->assertSame($drinks->id, Product::query()->where('name', 'Coke 1L')->value('category_id'));

        // Another business's category is not found, and the AI is told this business's names.
        $refused = json_decode($this->tools($ctx)->run('propose_create_product', ['name' => 'Chippy', 'price' => 15, 'category' => 'Snacks']), true);
        $this->assertSame('There is no category "Snacks" in this business. The categories are: Drinks.', $refused['error']);
    }

    public function test_a_product_is_added_only_after_the_user_confirms(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        $category = Category::factory()->create(['domain' => $ctx['domain']->name_slug, 'name' => 'Meals']);

        $token = $this->propose($ctx, 'create_product', ['name' => 'Chickenjoy 2pc', 'price' => 120, 'category_id' => $category->id]);
        $this->assertFalse(Product::query()->where('name', 'Chickenjoy 2pc')->exists(), 'nothing is saved before Confirm');
        $saved = AssistantConversation::query()
            ->create(['domain' => $ctx['domain']->name_slug, 'user_id' => $ctx['user']->id, 'title' => 'Add', 'last_message_at' => now()])
            ->messages()->create(['question' => 'Add it', 'answer' => 'Confirm below.', 'pending_actions' => [['token' => $token, 'title' => 'Add product']]]);
        app(PendingActions::class)->attachMessage($token, $saved->id);

        $this->confirm($ctx, $token)->assertOk()->assertJsonPath('message', 'Added "Chickenjoy 2pc" to Main.');
        $this->assertSame('done', $saved->fresh()->pending_actions[0]['state'], 'the saved chat shows it was done');

        $product = Product::query()->where('domain', $ctx['domain']->name_slug)->where('name', 'Chickenjoy 2pc')->sole();
        $this->assertEquals(120, $product->price);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Piece', $product->sold_type);
        $this->assertTrue($product->activeLocations()->whereKey($ctx['location']->id)->exists(), 'added to the store the user works in');

        // A card runs once.
        $this->confirm($ctx, $token)->assertNotFound();
    }

    public function test_only_the_asker_may_confirm_while_allowed_and_in_time(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();

        $other = $this->grant(User::factory()->create(['domain' => $ctx['domain']->name_slug, 'is_super_user' => false]), ['assistant.actions.confirm', 'products.store']);
        $token = $this->propose($ctx, 'create_product', ['name' => 'Spaghetti', 'price' => 60]);
        $this->confirm($ctx, $token, $other)->assertForbidden();

        $this->travel(PendingActions::MINUTES + 1)->minutes();
        $this->confirm($ctx, $token)->assertNotFound();
        $this->travelBack();

        $token = $this->propose($ctx, 'create_product', ['name' => 'Burger', 'price' => 45]);
        $ctx['user']->revokePermissionTo(Permission::query()->where('route_name', 'products.store')->first());
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->confirm($ctx, $token, $ctx['user']->fresh())->assertForbidden();

        $this->assertFalse(Product::query()->whereIn('name', ['Spaghetti', 'Burger'])->exists());
    }

    public function test_confirmed_changes_keep_the_pages_rules(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        $category = Category::factory()->create(['domain' => $ctx['domain']->name_slug]);
        Product::factory()->create(['domain' => $ctx['domain']->name_slug, 'category_id' => $category->id, 'SKU' => 'JB-001']);

        $token = $this->propose($ctx, 'create_product', ['name' => 'Peach Mango Pie', 'price' => 40, 'sku' => 'JB-001']);
        $this->confirm($ctx, $token)->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['SKU']]);

        config(['features.unlimited_products' => false]);
        Product::factory()->count(10)->create(['domain' => $ctx['domain']->name_slug, 'category_id' => $category->id]);
        $token = $this->propose($ctx, 'create_product', ['name' => 'Sundae', 'price' => 35]);
        $this->confirm($ctx, $token)->assertStatus(422)->assertJsonStructure(['errors' => ['plan']]);

        $this->assertFalse(Product::query()->whereIn('name', ['Peach Mango Pie', 'Sundae'])->exists());
    }

    public function test_a_product_price_change_keeps_its_other_details(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        $category = Category::factory()->create(['domain' => $ctx['domain']->name_slug]);
        $product = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug, 'category_id' => $category->id, 'name' => 'Yumburger', 'price' => 40, 'cost' => 20, 'SKU' => 'YB-1', 'sold_type' => 'Piece',
        ]);

        $tools = $this->tools($ctx);
        json_decode($tools->run('propose_update_product', ['product_id' => $product->id, 'price' => 45]), true);
        $card = $tools->pendingActions()[0];
        $this->assertContains(['label' => 'Price', 'value' => '₱40.00 → ₱45.00'], $card['fields']);

        $this->confirm($ctx, $card['token'])->assertOk();

        $product->refresh();
        $this->assertEquals(45, $product->price);
        $this->assertEquals(20, $product->cost);
        $this->assertSame('YB-1', $product->SKU);
        $this->assertSame('Yumburger', $product->name);
    }

    public function test_sales_settings_change_after_confirm(): void
    {
        $ctx = $this->admin();

        $token = $this->propose($ctx, 'update_sales_settings', ['apply_vat_automatically' => true, 'vat_rate_percent' => 12, 'vat_pricing_mode' => 'inclusive']);
        $this->assertFalse($ctx['domain']->fresh()->salesVatSettings()['apply_vat_automatically']);

        $this->confirm($ctx, $token)->assertOk();

        $this->assertSame(
            ['apply_vat_automatically' => true, 'vat_rate_percent' => 12.0, 'vat_pricing_mode' => 'inclusive'],
            $ctx['domain']->fresh()->salesVatSettings(),
        );
    }

    public function test_store_changes_keep_other_details_and_one_default(): void
    {
        $ctx = $this->admin();
        $ctx['location']->update(['address' => '1 Old Street', 'phone' => '0917']);

        $token = $this->propose($ctx, 'update_store', ['location_id' => $ctx['location']->id, 'address' => '2 New Street']);
        $this->confirm($ctx, $token)->assertOk();
        $main = $ctx['location']->fresh();
        $this->assertSame('2 New Street', $main->address);
        $this->assertSame('0917', $main->phone);
        $this->assertSame('Main', $main->name);

        $token = $this->propose($ctx, 'create_store', ['name' => 'Branch 2', 'code' => 'br2']);
        $this->confirm($ctx, $token)->assertOk();
        $branch = InventoryLocation::query()->forDomain($ctx['domain']->name_slug)->where('code', 'BR2')->sole();

        $token = $this->propose($ctx, 'set_default_store', ['location_id' => $branch->id]);
        $this->confirm($ctx, $token)->assertOk();
        $this->assertSame(
            [$branch->id],
            InventoryLocation::query()->forDomain($ctx['domain']->name_slug)->where('is_default', true)->pluck('id')->all(),
        );

        $token = $this->propose($ctx, 'set_store_active', ['location_id' => $main->id, 'active' => false]);
        $this->confirm($ctx, $token)->assertOk();
        $this->assertFalse((bool) $main->fresh()->is_active);
    }

    public function test_the_app_map_covers_every_page_of_the_sidebar(): void
    {
        // The sidebar's items are defined in useNavMenu.js (leftMenu.vue renders them).
        preg_match_all('/routeName:\s*"([^"]+)"/', file_get_contents(resource_path('js/Composables/useNavMenu.js')), $matches);
        $sidebarDomainPages = array_values(array_filter(array_unique($matches[1]), fn ($r) => Route::has('domains.'.$r)));
        $mapped = array_column(config('assistant_map'), 'route');

        $this->assertNotEmpty($sidebarDomainPages);
        $this->assertSame([], array_values(array_diff($sidebarDomainPages, $mapped)), 'sidebar pages missing from config/assistant_map.php');
        foreach ($mapped as $route) {
            $this->assertTrue(Route::has('domains.'.$route), "config/assistant_map.php names the unknown route {$route}");
        }
    }

    public function test_the_app_map_lists_only_pages_the_user_can_open(): void
    {
        $map = app(AppMap::class);

        $cashier = $this->staff(['sales.index']);
        $lines = $map->forUser($cashier['user'], $cashier['domain']);
        $this->assertStringContainsString('- Sales: the POS screen', $lines);
        $this->assertStringNotContainsString('Inventory', $lines);

        // The owner can open Finance pages, but only once the business has Finance.
        $owner = $this->staff(['inventory.movements', 'finance.balance-sheet']);
        $this->assertStringContainsString('Inventory → Movements', $map->forUser($owner['user'], $owner['domain']));
        $this->assertStringNotContainsString('What you own & owe', $map->forUser($owner['user'], $owner['domain']));

        $owner['domain']->update(['settings' => ['features' => ['finance' => true, 'ai' => true]]]);
        $this->assertStringContainsString('Finance → What you own & owe', $map->forUser($owner['user'], $owner['domain']->fresh()));

        $this->assertSame('Inventory → Movements', $map->pathOf('domains.inventory.movements'));
    }

    public function test_questions_it_cannot_answer_are_logged_once_in_the_users_words(): void
    {
        $ctx = $this->staff(['sales.index']);
        $tools = $this->tools($ctx)->forMessage('Paano mag-transfer ng stock sa ibang branch?', 'domains.sales.index');

        $this->assertSame(['logged' => true], json_decode($tools->run('report_unanswered', ['topic' => 'Stock transfer']), true));
        $tools->run('report_unanswered', ['topic' => 'again']);

        $logged = AssistantUnansweredQuestion::query()->sole();
        $this->assertSame('Paano mag-transfer ng stock sa ibang branch?', $logged->question);
        $this->assertSame($ctx['domain']->name_slug, $logged->domain);
        $this->assertSame($ctx['user']->id, $logged->user_id);
        $this->assertSame('domains.sales.index', $logged->page);
        $this->assertSame('Stock transfer', $logged->topic);
        $this->assertNull($logged->resolved_at);
    }

    public function test_super_admins_review_and_resolve_unanswered_questions(): void
    {
        $ctx = $this->staff(['sales.index']);
        $question = AssistantUnansweredQuestion::query()->create([
            'domain' => $ctx['domain']->name_slug, 'user_id' => $ctx['user']->id, 'question' => 'How do I print a Z-reading?', 'topic' => 'z reading',
        ]);
        $super = User::factory()->create(['is_super_user' => true]);

        $this->actingAs($super)->get(route('assistant-unanswered.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Messages/AssistantUnanswered')
                ->where('openCount', 1)
                ->where('questions.data.0.question', 'How do I print a Z-reading?')
                ->where('topTopics.0.topic', 'z reading'));

        $this->actingAs($super)->post(route('assistant-unanswered.resolve', $question))->assertRedirect();
        $this->assertNotNull($question->fresh()->resolved_at);

        $this->actingAs($ctx['user'])->getJson(route('assistant-unanswered.index'))->assertForbidden();
    }

    public function test_a_cancelled_change_is_not_saved(): void
    {
        $ctx = $this->admin();
        $token = $this->propose($ctx, 'create_category', ['name' => 'Desserts']);

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.assistant.actions.cancel', ['domain' => $ctx['domain']->name_slug, 'token' => $token]))
            ->assertOk();

        $this->confirm($ctx, $token)->assertNotFound();
        $this->assertFalse(Category::query()->where('name', 'Desserts')->exists());
    }
}
