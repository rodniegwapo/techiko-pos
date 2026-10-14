<?php

namespace App\Services\Ai\Actions\Store;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\DescribesValues;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Changes the VAT and selling settings, like Settings → Sales. */
class UpdateSalesSettings implements AssistantAction
{
    use DescribesValues;

    private const KEYS = ['apply_vat_automatically', 'vat_rate_percent', 'vat_pricing_mode', 'allow_overselling', 'hide_out_of_stock'];

    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'update_sales_settings';
    }

    public function permission(): string
    {
        return 'settings.update';
    }

    public function description(): string
    {
        return 'Propose changing the business\'s sales settings: VAT on/off, VAT rate, whether prices include VAT, selling when out of stock, hiding out-of-stock products at the POS. Send only what changes; get_sales_settings shows the current values.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'apply_vat_automatically' => $schema->boolean()->description('Add VAT to every sale'),
            'vat_rate_percent' => $schema->number()->description('VAT rate in percent, 0 to 100 (usually 12)'),
            'vat_pricing_mode' => $schema->string()->enum(['inclusive', 'exclusive'])->description('inclusive = prices already include VAT; exclusive = VAT is added on top'),
            'allow_overselling' => $schema->boolean()->description('Let cashiers sell items with no stock left'),
            'hide_out_of_stock' => $schema->boolean()->description('Hide out-of-stock products at the POS'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $changes = $this->filled($input, self::KEYS);
        if ($changes === []) {
            throw new ActionInputException('Say which setting to change.');
        }
        if (isset($changes['vat_rate_percent']) && (! is_numeric($changes['vat_rate_percent']) || $changes['vat_rate_percent'] < 0 || $changes['vat_rate_percent'] > 100)) {
            throw new ActionInputException('The VAT rate must be between 0 and 100.');
        }
        if (isset($changes['vat_pricing_mode']) && ! in_array($changes['vat_pricing_mode'], ['inclusive', 'exclusive'], true)) {
            throw new ActionInputException('The VAT pricing mode must be inclusive or exclusive.');
        }

        $sales = $context->domain->settings['sales'] ?? [];
        $current = [
            ...$context->domain->salesVatSettings(),
            'allow_overselling' => (bool) ($sales['allow_overselling'] ?? false),
            'hide_out_of_stock' => (bool) ($sales['hide_out_of_stock'] ?? false),
        ];

        $labels = [
            'apply_vat_automatically' => 'Apply VAT automatically',
            'vat_rate_percent' => 'VAT rate',
            'vat_pricing_mode' => 'Prices',
            'allow_overselling' => 'Sell when out of stock',
            'hide_out_of_stock' => 'Hide out-of-stock products',
        ];
        $show = fn (string $key, mixed $value) => match ($key) {
            'vat_rate_percent' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.').'%',
            'vat_pricing_mode' => $value === 'inclusive' ? 'Include VAT' : 'VAT added on top',
            default => $this->onOff($value),
        };

        $data = [];
        $fields = [];
        foreach ($changes as $key => $value) {
            $value = match ($key) {
                'vat_rate_percent' => round((float) $value, 2),
                'vat_pricing_mode' => $value,
                default => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            };
            $data[$key] = $value;
            $fields[] = ['label' => $labels[$key], 'value' => $this->change($show($key, $current[$key]), $show($key, $value))];
        }

        return new PreparedAction('Change sales settings', $data, $fields, 'Save settings');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.settings.update', $input, ['domain' => $context->domain], $context->user, 'Sales settings saved.');
    }
}
