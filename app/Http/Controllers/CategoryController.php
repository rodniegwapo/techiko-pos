<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        return inertia('Category/Index', [
            'items' => CategoryResource::collection(Category::paginate($request?->data['per_page'] ?? 10)),
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
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
    }
}
