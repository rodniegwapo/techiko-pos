<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct()
    {
        // Middleware is handled at route level
    }

    public function index(Request $request)
    {
        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Global CategoryController index entry',
            'data' => [
                'is_global' => true,
                'search' => $request->input('search'),
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        $category = Category::query()->when($request->input('search'), function ($query, $search) {
            return $query->search($search);
        })->paginate($request?->data['per_page'] ?? 10);

        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'B',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Global query executed',
            'data' => [
                'total' => $category->total(),
                'count' => $category->count(),
                'current_page' => $category->currentPage(),
                'per_page' => $category->perPage(),
                'has_data' => $category->count() > 0,
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        $resourceCollection = CategoryResource::collection($category);

        // #region agent log
        $logData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => __FILE__ . ':' . __LINE__,
            'message' => 'Global resource collection created',
            'data' => [
                'resource_type' => get_class($resourceCollection),
                'resource_count' => $resourceCollection->count(),
            ],
            'timestamp' => now()->timestamp * 1000,
        ];
        $logPath = 'c:\\laragon\\www\\techiko-pos\\.cursor\\debug.log';
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
        // #endregion

        return inertia('Categories/Index', [
            'items' => $resourceCollection,
            'isGlobalView' => true,
            'domains' => \App\Models\Domain::select('id', 'name', 'name_slug')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        Category::create($data);

        return redirect()->back();
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validatedData($request);

        $category->update($data);

        return back();
    }

    public function destroy(Request $request, Category $category)
    {
        $category->delete();

        return redirect()->back();
    }

    private function validatedData(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ];

        // Add domain validation for global view
        if ($request->has('domain') && $request->domain) {
            $rules['domain'] = 'required|string|exists:domains,name_slug';
        }

        return $request->validate($rules);
    }
}
