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
        $category = Category::query()->when($request->input('search'), function ($query, $search) {
            return $query->search($search);
        })->paginate($request?->data['per_page'] ?? 10);

        return inertia('Categories/Index', [
            'items' => CategoryResource::collection($category),
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
