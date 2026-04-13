<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\StoreCategoryRequest;
use App\Http\Requests\API\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class CategoryController extends Controller
{
    use AuthorizesRequests;
    /**
     * Menampilkan daftar kategori untuk bisnis user.
     * Bisa difilter berdasarkan status.
     * URL: GET /api/categories
     * URL: GET /api/categories?status=active
     */
     public function index(Request $request)
    {
        // Otorisasi manual untuk 'viewAny'
        $this->authorize('viewAny', Category::class);

        $query = Category::where('business_id', $request->user()->business_id);

        if ($request->query('status') === 'active') {
            $query->where('status', true);
        }

        $categories = $query->orderBy('name', 'asc')->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request)
    {
        // Otorisasi sudah ditangani oleh StoreCategoryRequest
        $data = $request->validated();
        $data['business_id'] = $request->user()->business_id;

        if ($request->hasFile('icon')) {
            $data['icon'] = $request->file('icon')->store('category-icons', 'public');
        }

        $category = Category::create($data);
        return new CategoryResource($category);
    }

    public function show(Category $category)
    {
        // Otorisasi manual untuk 'view'
        $this->authorize('view', $category);
        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        // Otorisasi sudah ditangani oleh UpdateCategoryRequest
        $data = $request->validated();

        if ($request->hasFile('icon')) {
            if ($category->icon) {
                Storage::disk('public')->delete($category->icon);
            }
            $data['icon'] = $request->file('icon')->store('category-icons', 'public');
        }

        $category->update($data);
        return new CategoryResource($category);
    }

    public function destroy(Category $category)
    {
        // Otorisasi manual untuk 'delete'
        $this->authorize('delete', $category);

        if ($category->icon) {
            Storage::disk('public')->delete($category->icon);
        }
        $category->delete();
        return response()->noContent();
    }

}
