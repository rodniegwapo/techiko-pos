<?php

namespace App\Services\Ai\Actions\Finance;

use App\Models\ExpenseCategory;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use Illuminate\Database\Eloquent\Collection;

/** The expense categories a change may use (this business's active ones), found by id or by name. */
class ExpenseCategories
{
    /** @return Collection<int, ExpenseCategory> A–Z. */
    public static function active(ActionContext $context): Collection
    {
        return ExpenseCategory::query()->forDomain($context->domain->name_slug)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The category given by id, else by name (any letter case). Null when neither is given or the id
     * isn't one of them; an unknown name is answered with the valid names.
     */
    public static function resolve(array $input, ActionContext $context, string $idKey = 'expense_category_id', string $nameKey = 'expense_category'): ?ExpenseCategory
    {
        $categories = self::active($context);
        if (! empty($input[$idKey])) {
            return $categories->firstWhere('id', (int) $input[$idKey]);
        }

        $wanted = trim((string) ($input[$nameKey] ?? ''));
        if ($wanted === '') {
            return null;
        }

        return $categories->first(fn (ExpenseCategory $c) => strcasecmp($c->name, $wanted) === 0)
            ?? throw new ActionInputException('There is no expense category "'.$wanted.'". The categories are: '
                .($categories->pluck('name')->implode(', ') ?: 'none yet').'.');
    }
}
