<?php

namespace Tests\Feature;

use App\Ai\Agents\AppAssistantAgent;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Ai\Actions\PendingActions;
use App\Services\Ai\AppAssistant;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\GuideLibrary;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
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

        $this->assertSame(['answer' => '1. Open **Products → Items**.', 'links' => [], 'pending_actions' => []], $reply);
        AppAssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('How do I add a product?') && $prompt->contains('Taglish'));
    }

    public function test_ask_endpoint_answers_within_the_daily_limit(): void
    {
        config(['services.app_assistant.daily_limit' => 2]);
        AppAssistantAgent::fake(['Here are the steps.', 'Here are the steps.']);
        $ctx = $this->staff(['assistant.ask']);

        $ask = fn () => $this->actingAs($ctx['user'])->postJson($this->askUrl($ctx['domain']), ['message' => 'How do I add a product?']);

        $ask()->assertOk()->assertJsonPath('answer', 'Here are the steps.')->assertJsonPath('remaining', 1);
        $ask()->assertOk()->assertJsonPath('remaining', 0);
        $ask()->assertStatus(429);
    }

    public function test_ask_needs_the_assistant_permission_and_ai_feature(): void
    {
        AppAssistantAgent::fake()->preventStrayPrompts();

        $noPermission = $this->staff(['sales.index']);
        $this->actingAs($noPermission['user'])
            ->postJson($this->askUrl($noPermission['domain']), ['message' => 'How do I add a product?'])
            ->assertForbidden();

        $noFeature = $this->staff(['assistant.ask'], ai: false);
        $this->actingAs($noFeature['user'])
            ->postJson($this->askUrl($noFeature['domain']), ['message' => 'How do I add a product?'])
            ->assertStatus(403);
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

    public function test_a_product_is_added_only_after_the_user_confirms(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $ctx = $this->admin();
        $category = Category::factory()->create(['domain' => $ctx['domain']->name_slug, 'name' => 'Meals']);

        $token = $this->propose($ctx, 'create_product', ['name' => 'Chickenjoy 2pc', 'price' => 120, 'category_id' => $category->id]);
        $this->assertFalse(Product::query()->where('name', 'Chickenjoy 2pc')->exists(), 'nothing is saved before Confirm');

        $this->confirm($ctx, $token)->assertOk()->assertJsonPath('message', 'Added "Chickenjoy 2pc" to Main.');

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
