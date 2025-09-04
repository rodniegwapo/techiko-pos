<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {   
        return inertia('Category/Index', [
            'items' => Category::all()
        ]);
    }
    
    public function store(Request $request){}

    public function update(Request $request, $id)
    {
        // Validate and update category logic here
    }

    public function destroy($id)
    {
        // Delete category logic here
    }
}
