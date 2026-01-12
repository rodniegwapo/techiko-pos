<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use App\Helpers;
use App\Traits\LocationCategoryScoping;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    use LocationCategoryScoping;
    /**
     * Display a listing of categories for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'CategoryController index entry',
            'data' => [
                'domain' => $domain ? $domain->name_slug : 'NULL',
                'domain_id' => $domain ? $domain->id : 'NULL',
                'has_domain' => $domain !== null,
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        // For category management, show all categories for the domain regardless of location
        // Location filtering should only apply to product/inventory views, not category management
        $query = $domain 
            ? Category::where('domain', $domain->name_slug)->withCount('products')
            : Category::query()->withCount('products');
        
        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'post-fix',
            'hypothesisId' => 'B',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Query built - showing all categories for domain',
            'data' => [
                'query_type' => 'all_categories_for_domain',
                'domain_slug' => $domain ? $domain->name_slug : 'NULL',
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion
        
        $query = $query->when($request->input('search'), function ($q, $search) {
                return $q->search($search);
            });

        $items = $query->latest()->paginate($request?->data['per_page'] ?? 15);

        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'post-fix',
            'hypothesisId' => 'B',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Query executed - pagination result',
            'data' => [
                'total' => $items->total(),
                'count' => $items->count(),
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'has_data' => $items->count() > 0,
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        $resourceCollection = CategoryResource::collection($items);

        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Resource collection created',
            'data' => [
                'resource_type' => get_class($resourceCollection),
                'resource_count' => $resourceCollection->count(),
                'has_data_property' => property_exists($resourceCollection, 'data'),
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        $response = Inertia::render('Categories/Index', [
            'items' => $resourceCollection,
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);

        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Inertia response prepared',
            'data' => [
                'items_type' => gettype($resourceCollection),
                'is_global_view' => !$domain,
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        return $response;
    }

    /**
     * Store a newly created category for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        
        // Create category (no direct location binding)
        $category = Category::create($validated);

        return redirect()->back()->with('success', 'Category created successfully');
    }

    /**
     * Update the specified category for the domain.
     */
    public function update(Request $request, Domain $domain, Category $category)
    {
        // Ensure category belongs to this domain
        if ($category->domain !== $domain->name_slug) {
            abort(403, 'Category does not belong to this domain');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return redirect()->back()->with('success', 'Category updated successfully');
    }

    /**
     * Remove the specified category from the domain.
     */
    public function destroy(Request $request, Domain $domain, Category $category)
    {
        // Ensure category belongs to this domain
        if ($category->domain !== $domain->name_slug) {
            abort(403, 'Category does not belong to this domain');
        }

        // Check if category has products
        $productCount = \App\Models\Product\Product::where('category_id', $category->id)->count();

        if ($productCount > 0) {
            return redirect()->back()->with('error', "Cannot delete category with {$productCount} existing products. Please move or delete the products first.");
        }

        try {
            $category->delete();
            return redirect()->back()->with('success', 'Category deleted successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete category: ' . $e->getMessage());
        }
    }
}
