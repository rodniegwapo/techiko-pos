<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ExpenseCategory;
use App\Support\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function store(Request $request, Domain $domain)
    {
        abort_unless(FinanceAccess::canManage($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('domain', $domain->name_slug)],
            'type' => ['sometimes', Rule::in(ExpenseCategory::TYPES)],
        ]);

        ExpenseCategory::query()->create([
            'domain' => $domain->name_slug,
            'name' => trim($data['name']),
            'type' => $data['type'] ?? 'operating',
        ]);

        return redirect()->back()->with('success', 'Category added.');
    }

    public function update(Request $request, Domain $domain, ExpenseCategory $category)
    {
        abort_unless(FinanceAccess::canManage($request->user()), 403);
        abort_if($category->domain !== $domain->name_slug, 404);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')->where('domain', $domain->name_slug)->ignore($category->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(ExpenseCategory::TYPES)],
        ]);

        $category->update($data);

        return redirect()->back()->with('success', 'Category updated.');
    }

    public function destroy(Request $request, Domain $domain, ExpenseCategory $category)
    {
        abort_unless(FinanceAccess::canManage($request->user()), 403);
        abort_if($category->domain !== $domain->name_slug, 404);

        // Past expenses keep their category for the P&L; hide it from new expenses instead.
        if ($category->expenses()->withTrashed()->exists()) {
            return redirect()->back()->with('error', 'This category has expenses. Deactivate it instead.');
        }

        $category->delete();

        return redirect()->back()->with('success', 'Category deleted.');
    }
}
