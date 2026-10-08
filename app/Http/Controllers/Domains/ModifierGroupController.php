<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ModifierGroup;
use App\Models\Product\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product modifiers (add-ons): an organization's groups of options (Size, Sugar level, Add-ons…),
 * their options and prices, and the products each group applies to.
 */
class ModifierGroupController extends Controller
{
    public function index(Request $request, Domain $domain): Response|JsonResponse
    {
        $groups = ModifierGroup::forDomain($domain->name_slug)
            ->with(['modifiers', 'products:id,name'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($request->wantsJson()) {
            return response()->json(['data' => $groups]);
        }

        return Inertia::render('Products/Modifiers', [
            'groups' => $groups,
            'products' => Product::query()
                ->where('domain', $domain->name_slug)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Domain $domain): RedirectResponse|JsonResponse
    {
        $validated = $this->validated($request, $domain);

        $group = DB::transaction(function () use ($validated, $domain) {
            $group = ModifierGroup::create([
                ...$this->groupAttributes($validated),
                'domain' => $domain->name_slug,
            ]);
            $this->syncModifiers($group, $validated['modifiers']);
            $group->products()->sync($validated['product_ids'] ?? []);

            return $group;
        });

        return $this->respond($request, $group, 'Modifier group created');
    }

    public function update(Request $request, Domain $domain, ModifierGroup $modifierGroup): RedirectResponse|JsonResponse
    {
        $this->ensureInDomain($domain, $modifierGroup);
        $validated = $this->validated($request, $domain, $modifierGroup);

        DB::transaction(function () use ($validated, $modifierGroup) {
            $modifierGroup->update($this->groupAttributes($validated));
            $this->syncModifiers($modifierGroup, $validated['modifiers']);
            $modifierGroup->products()->sync($validated['product_ids'] ?? []);
        });

        return $this->respond($request, $modifierGroup, 'Modifier group updated');
    }

    public function destroy(Request $request, Domain $domain, ModifierGroup $modifierGroup): RedirectResponse|JsonResponse
    {
        $this->ensureInDomain($domain, $modifierGroup);

        // Lines already sold keep the options' names and prices; only the group goes.
        $modifierGroup->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Modifier group deleted');
    }

    private function validated(Request $request, Domain $domain, ?ModifierGroup $group = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('modifier_groups', 'name')
                    ->where('domain', $domain->name_slug)
                    ->ignore($group?->id),
            ],
            'selection' => ['required', Rule::in(['single', 'multiple'])],
            'is_required' => ['boolean'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'modifiers' => ['required', 'array', 'min:1', 'max:50'],
            'modifiers.*.id' => [
                'nullable', 'integer',
                // Only this group's own options can be edited through it.
                Rule::exists('modifiers', 'id')->where('modifier_group_id', $group?->id ?? 0),
            ],
            'modifiers.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'modifiers.*.price_delta' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'modifiers.*.cost_delta' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'modifiers.*.is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('domain', $domain->name_slug)],
        ], [
            'name.unique' => 'There is already a modifier group with this name.',
            'modifiers.required' => 'Add at least one option.',
            'modifiers.*.name.distinct' => 'Each option needs its own name.',
        ]);

        if (($validated['selection'] ?? 'single') === 'multiple'
            && isset($validated['max_select'])
            && $validated['max_select'] > count($validated['modifiers'])) {
            throw ValidationException::withMessages([
                'max_select' => 'The most that can be picked is more than the options there are.',
            ]);
        }

        return $validated;
    }

    private function groupAttributes(array $validated): array
    {
        $single = $validated['selection'] === 'single';

        return [
            'name' => trim($validated['name']),
            'selection' => $validated['selection'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'max_select' => $single ? null : ($validated['max_select'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /** Update the options listed, add the new ones and remove the ones left out, in the order given. */
    private function syncModifiers(ModifierGroup $group, array $rows): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $attributes = [
                'name' => trim($row['name']),
                'price_delta' => round((float) ($row['price_delta'] ?? 0), 2),
                'cost_delta' => round((float) ($row['cost_delta'] ?? 0), 4),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $i,
            ];

            $modifier = ! empty($row['id'])
                ? tap($group->modifiers()->findOrFail($row['id']))->update($attributes)
                : $group->modifiers()->create($attributes);

            $keep[] = $modifier->id;
        }

        $group->modifiers()->whereNotIn('id', $keep)->delete();
    }

    private function respond(Request $request, ModifierGroup $group, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['data' => $group->fresh(['modifiers', 'products:id,name'])]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function ensureInDomain(Domain $domain, ModifierGroup $group): void
    {
        if ($group->domain !== $domain->name_slug) {
            abort(404);
        }
    }
}
