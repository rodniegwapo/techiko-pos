<?php

namespace App\Services\Ai;

use App\Ai\Tools\AssistantTool;
use App\Models\AssistantUnansweredQuestion;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionRegistry;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\Catalog\SoldTypes;
use App\Services\Ai\Actions\PendingActions;
use App\Support\RoutePermission;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * The tools the AI assistant may call for one user. Only what the user's role allows is offered:
 * guides are filtered by GuideLibrary, and each read tool and each propose_* tool is added only
 * when the user holds its permission. A propose_* tool never changes anything; it leaves a pending
 * change for the user to confirm (pendingActions()). The pages the AI pointed to are kept in
 * links() so the chat can show them as buttons.
 */
class AssistantTools
{
    /**
     * Read tools and the permissions that allow each (any one of them).
     */
    private const READ_TOOLS = [
        'list_categories' => ['categories.index', 'products.store', 'products.update'],
        'list_sold_types' => ['products.store', 'products.update'],
        'find_products' => ['products.index', 'products.update'],
        'list_stores' => ['inventory.locations.index', 'inventory.locations.update', 'inventory.locations.set-default', 'inventory.locations.toggle-status'],
        'get_sales_settings' => ['settings.index', 'settings.update'],
    ];

    /** @var array<string, array{title: string, url: string}> */
    private array $links = [];

    /** @var list<array{token: string, title: string, fields: list<array{label: string, value: string}>, confirm_label: string, expires_at: string}> */
    private array $pendingActions = [];

    private readonly ActionRegistry $actions;

    private readonly PendingActions $pending;

    /** The user's message and page, saved as-is when the AI reports it couldn't help. */
    private string $message = '';

    private ?string $page = null;

    private bool $reportedUnanswered = false;

    public function __construct(
        private readonly GuideLibrary $guides,
        private readonly User $user,
        private readonly Domain $domain,
        private readonly ?InventoryLocation $location = null,
    ) {
        $this->actions = app(ActionRegistry::class);
        $this->pending = app(PendingActions::class);
    }

    /** @return list<AssistantTool> */
    public function tools(): array
    {
        $tool = fn (string $name, string $description, Closure $schema) => new AssistantTool($this, $name, $description, $schema);

        $tools = [
            $tool('search_guides', 'Find the how-to guides for a task, best match first. Returns the guides this user may follow, and the titles of matching tasks their role does not allow. Use it before explaining how to do anything in Techiko POS.', fn (JsonSchema $schema) => [
                'query' => $schema->string()->description('The task in a few words, e.g. "add product" or "change VAT"')->required(),
            ]),
            $tool('get_guide', 'The step-by-step guide for one task (by key from search_guides or list_guides), with the page it happens on.', fn (JsonSchema $schema) => [
                'key' => $schema->string()->description('The guide key, e.g. "add-product"')->required(),
            ]),
            $tool('list_guides', 'Every task this user can get help with. Use for "what can you do" or "what can you help me with".', fn () => []),
            $tool('get_current_store', 'The store the user is working in now (new products are added there).', fn () => []),
            $tool('report_unanswered', 'Record that you could not help with this message (no guide, page or tool covers it), so the Techiko team can add help for it. Call it once, before suggesting the team chat.', fn (JsonSchema $schema) => [
                'topic' => $schema->string()->description('What it was about in 2 to 5 words, e.g. "transfer stock between stores"')->required(),
            ]),
        ];

        $read = [
            'list_categories' => ['The product categories of this business, with their ids.', fn () => []],
            'list_sold_types' => ['The ways a product can be sold (Piece, Box…).', fn () => []],
            'find_products' => ['Find products by name, SKU or barcode: id, name, price, cost, category, SKU, barcode (up to 10).', fn (JsonSchema $schema) => [
                'query' => $schema->string()->description('Part of the name, SKU or barcode')->required(),
            ]],
            'list_stores' => ['The stores and warehouses of this business: id, name, code, type, address, active, default.', fn () => []],
            'get_sales_settings' => ['The current sales settings: VAT on/off, VAT rate, prices include VAT or not, selling when out of stock, hiding out-of-stock products.', fn () => []],
        ];
        foreach ($read as $name => [$description, $schema]) {
            if ($this->mayRead($name)) {
                $tools[] = $tool($name, $description, $schema);
            }
        }

        foreach ($this->actions->allowedFor($this->user) as $action) {
            $tools[] = $tool(
                'propose_'.$action->name(),
                $action->description().' Nothing changes until the user presses Confirm on the card shown with your answer.',
                fn (JsonSchema $schema) => $action->schema($schema),
            );
        }

        return $tools;
    }

    /** Runs a tool and returns its result as JSON text. */
    public function run(string $name, array $input): string
    {
        try {
            if (str_starts_with($name, 'propose_')) {
                $result = $this->propose(substr($name, strlen('propose_')), $input);
            } elseif (array_key_exists($name, self::READ_TOOLS) && ! $this->mayRead($name)) {
                $result = ['error' => 'not_allowed', 'message' => 'This user\'s role cannot see that.'];
            } else {
                $result = match ($name) {
                    'search_guides' => $this->searchGuides((string) ($input['query'] ?? '')),
                    'get_guide' => $this->getGuide((string) ($input['key'] ?? '')),
                    'list_guides' => array_values(array_map(fn ($g) => ['key' => $g['key'], 'title' => $g['title']], $this->guides->allowedFor($this->user))),
                    'report_unanswered' => $this->reportUnanswered((string) ($input['topic'] ?? '')),
                    'get_current_store' => $this->location
                        ? ['id' => $this->location->id, 'name' => $this->location->name, 'type' => $this->location->type]
                        : ['error' => 'No store is selected.'],
                    'list_categories' => Category::query()->forDomain($this->domain->name_slug)->orderBy('name')->get(['id', 'name'])->toArray(),
                    'list_sold_types' => SoldTypes::all(),
                    'find_products' => $this->findProducts((string) ($input['query'] ?? '')),
                    'list_stores' => InventoryLocation::query()->forDomain($this->domain->name_slug)->orderBy('name')
                        ->get(['id', 'name', 'code', 'type', 'address', 'is_active', 'is_default'])->toArray(),
                    'get_sales_settings' => [
                        ...$this->domain->salesVatSettings(),
                        'allow_overselling' => (bool) ($this->domain->settings['sales']['allow_overselling'] ?? false),
                        'hide_out_of_stock' => (bool) ($this->domain->settings['sales']['hide_out_of_stock'] ?? false),
                    ],
                    default => ['error' => 'Unknown tool '.$name],
                };
            }
        } catch (Throwable $e) {
            report($e);
            $result = ['error' => 'That information could not be loaded.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /** The message being answered and the page it was asked on, for report_unanswered. */
    public function forMessage(string $message, ?string $page): static
    {
        $this->message = $message;
        $this->page = $page;

        return $this;
    }

    /** @return list<array{title: string, url: string}> The pages the AI pointed to, for buttons under the answer. */
    public function links(): array
    {
        return array_values($this->links);
    }

    /** @return list<array{token: string, title: string, fields: list<array{label: string, value: string}>, confirm_label: string, expires_at: string}> Changes waiting for the user's Confirm. */
    public function pendingActions(): array
    {
        return $this->pendingActions;
    }

    private function mayRead(string $tool): bool
    {
        foreach (self::READ_TOOLS[$tool] as $permission) {
            if (RoutePermission::allows($this->user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function propose(string $name, array $input): array
    {
        $action = $this->actions->find($name);
        if (! $action instanceof AssistantAction || ! RoutePermission::allows($this->user, $action->permission())) {
            return ['error' => 'not_allowed', 'message' => 'This user\'s role cannot do that. They should ask the business owner or an admin.'];
        }

        $context = new ActionContext($this->user, $this->domain, $this->location);
        try {
            $prepared = $action->prepare($input, $context);
        } catch (ActionInputException $e) {
            return ['error' => $e->getMessage()];
        }

        $this->pendingActions[] = $this->pending->put($action, $prepared, $context);

        return [
            'status' => 'awaiting_confirmation',
            'message' => 'A confirm card is shown with your answer. Nothing has changed yet; it is saved only when the user presses Confirm.',
            'summary' => $prepared->title.': '.collect($prepared->fields)->map(fn ($f) => $f['label'].' '.$f['value'])->implode('; '),
        ];
    }

    /** Saves the user's own words (not the AI's), once per message. */
    private function reportUnanswered(string $topic): array
    {
        if (! $this->reportedUnanswered && trim($this->message) !== '') {
            AssistantUnansweredQuestion::query()->create([
                'domain' => $this->domain->name_slug,
                'user_id' => $this->user->id,
                'question' => mb_substr($this->message, 0, 1000),
                'page' => $this->page ? mb_substr($this->page, 0, 255) : null,
                'role' => mb_substr($this->user->isSuperUser() ? 'super admin' : ($this->user->getRoleNames()->implode(', ') ?: 'staff'), 0, 255),
                'topic' => trim($topic) !== '' ? mb_substr(trim($topic), 0, 100) : null,
            ]);
            $this->reportedUnanswered = true;
        }

        return ['logged' => true];
    }

    private function findProducts(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['error' => 'Give part of the product name, SKU or barcode.'];
        }

        return Product::query()->forDomain($this->domain->name_slug)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('SKU', $query)->orWhere('barcode', $query))
            ->with('category:id,name')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'cost' => $p->cost !== null ? (float) $p->cost : null,
                'category' => $p->category?->name,
                'sold_type' => $p->sold_type,
                'sku' => $p->SKU,
                'barcode' => $p->barcode,
            ])
            ->all();
    }

    private function searchGuides(string $query): array
    {
        $allowed = $this->guides->search($this->user, $query);

        $notAllowed = [];
        foreach ($this->guides->all() as $guide) {
            if (! $this->guides->allows($this->user, $guide) && $this->guides->score($guide, $query) > 0) {
                $notAllowed[] = $guide['title'];
            }
        }

        return [
            'guides' => $allowed,
            'not_allowed_for_this_role' => $notAllowed,
        ];
    }

    private function getGuide(string $key): array
    {
        $guide = $this->guides->find($key);
        if ($guide === null) {
            return ['error' => 'No guide with that key. Use search_guides to find one.'];
        }
        if (! $this->guides->allows($this->user, $guide)) {
            return ['error' => 'not_allowed', 'message' => 'This user\'s role is not allowed to do "'.$guide['title'].'". They should ask the business owner or an admin.'];
        }

        $url = $this->guides->pageUrl($guide, $this->domain->name_slug);
        if ($url !== null) {
            $this->links[$guide['key']] = ['title' => $guide['title'], 'url' => $url];
        }

        return [
            'title' => $guide['title'],
            'steps' => $guide['steps'],
            'page_link_shown_to_user' => $url !== null,
        ];
    }
}
