<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\StoreProductRequest;
use App\Http\Requests\API\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ProductController extends Controller
{
      use AuthorizesRequests;
    // Method __construct() dihapus

    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);
        $products = Product::where('business_id', $request->user()->business_id)
            ->with(['category', 'brand'])
            ->get();
        return ProductResource::collection($products);
    }

    public function indexByCategory(Request $request, Category $category)
    {
        $this->authorize('view', $category);
        $products = $category->products()->where('status', true)->get();
        return ProductResource::collection($products);
    }

    public function indexByBrand(Request $request, Brand $brand)
    {
        // Otorisasi: pastikan user boleh melihat brand ini terlebih dahulu
        $this->authorize('view', $brand);

        // Ambil semua produk yang statusnya aktif dari brand tersebut
        $products = $brand->products()->where('status', true)->get();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        // Otorisasi sudah ditangani oleh StoreProductRequest
        $data = $request->validated();

        return DB::transaction(function () use ($request, $data) {
            $data['business_id'] = $request->user()->business_id;
            if ($request->hasFile('thumbnail')) {
                $data['thumbnail'] = $request->file('thumbnail')->store('product-thumbnails', 'public');
            }
            if (isset($data['is_promo']) && $data['is_promo'] && isset($data['percent'])) {
                $discount = ($data['price'] * $data['percent']) / 100;
                $data['price_afterdiscount'] = $data['price'] - $discount;
            }
            $product = Product::create($data);

            if ($request->has('productsizes')) { $product->productsizes()->createMany($request->productsizes); }
            if ($request->has('productIngredients')) { $product->productIngredients()->createMany($request->productIngredients); }
            if ($request->hasFile('productphotos')) {
                foreach ($request->file('productphotos') as $photoData) {
                    $path = $photoData['photo']->store('product-photos', 'public');
                    $product->productphotos()->create(['photo' => $path]);
                }
            }
            return new ProductResource($product);
        });
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);
        $product->load(['category', 'brand', 'stocks.outlet', 'productsizes', 'productphotos', 'productIngredients']);
        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
{
    // Otorisasi sudah ditangani oleh UpdateProductRequest
    $data = $request->validated();

    return DB::transaction(function () use ($request, $data, $product) {
        // 1. Update data utama produk
        if ($request->hasFile('thumbnail')) {
            if ($product->thumbnail) {
                Storage::disk('public')->delete($product->thumbnail);
            }
            $data['thumbnail'] = $request->file('thumbnail')->store('product-thumbnails', 'public');
        }
        if (isset($data['is_promo']) && $data['is_promo'] && isset($data['percent'])) {
            $price = $data['price'] ?? $product->price;
            $discount = ($price * $data['percent']) / 100;
            $data['price_afterdiscount'] = $price - $discount;
        } elseif (isset($data['is_promo']) && !$data['is_promo']) {
            $data['price_afterdiscount'] = null;
            $data['percent'] = null;
        }
        $product->update($data);

        // 2. Sinkronisasi Product Sizes
        if ($request->has('productsizes')) {
            $sizeIds = [];
            foreach ($request->productsizes as $sizeData) {
                $size = $product->productsizes()->updateOrCreate(
                    ['id' => $sizeData['id'] ?? null],
                    ['size' => $sizeData['size']]
                );
                $sizeIds[] = $size->id;
            }
            $product->productsizes()->whereNotIn('id', $sizeIds)->delete();
        }

        // 3. Sinkronisasi Product Ingredients
        if ($request->has('productIngredients')) {
            $ingredientIds = [];
            foreach ($request->productIngredients as $ingredientData) {
                $ingredient = $product->productIngredients()->updateOrCreate(
                    ['id' => $ingredientData['id'] ?? null],
                    ['name' => $ingredientData['name']]
                );
                $ingredientIds[] = $ingredient->id;
            }
            $product->productIngredients()->whereNotIn('id', $ingredientIds)->delete();
        }

        // 4. Sinkronisasi Product Photos
        if ($request->has('productphotos')) {
            $photoIds = [];
             if($request->hasFile('productphotos')) {
                foreach ($request->file('productphotos') as $key => $fileData) {
                    if (isset($fileData['photo'])) {
                        $path = $fileData['photo']->store('product-photos', 'public');
                        $photoId = $request->productphotos[$key]['id'] ?? null;
                        $photo = $product->productphotos()->updateOrCreate(
                            ['id' => $photoId],
                            ['photo' => $path]
                        );
                        $photoIds[] = $photo->id;
                    }
                }
            }
            $photosToDelete = $product->productphotos()->whereNotIn('id', $photoIds)->get();
            foreach ($photosToDelete as $photo) {
                Storage::disk('public')->delete($photo->photo);
                $photo->delete();
            }
        }

        return new ProductResource($product->fresh()->load(['productsizes', 'productIngredients', 'productphotos']));
    });
}

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);

        Storage::disk('public')->delete($product->thumbnail);
        foreach($product->productphotos as $photo) {
            Storage::disk('public')->delete($photo->photo);
        }

        $product->delete();
        return response()->noContent();
    }
}
