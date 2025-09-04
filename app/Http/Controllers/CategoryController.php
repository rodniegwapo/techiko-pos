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

    public function store(Request $request) {}

    public function update(Request $request, $id)
    {
        // Validate and update category logic here
    }

    public function destroy($id)
    {
        // Delete category logic here
    }
}
