<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\StoreBrandRequest;
use App\Http\Requests\API\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class BrandController extends Controller
{
     use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Brand::class);
        $brands = Brand::where('business_id', $request->user()->business_id)->get();
        return BrandResource::collection($brands);
    }

    public function store(StoreBrandRequest $request)
    {
        $data = $request->validated();
        $data['business_id'] = $request->user()->business_id;

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('brand-logos', 'public');
        }

        $brand = Brand::create($data);
        return new BrandResource($brand);
    }

    public function show(Brand $brand)
    {
        $this->authorize('view', $brand);
        return new BrandResource($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand)
    {
        $this->authorize('update', $brand);

        // Anda bisa membuat UpdateBrandRequest jika perlu
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($brand->logo) {
                Storage::disk('public')->delete($brand->logo);
            }
            $data['logo'] = $request->file('logo')->store('brand-logos', 'public');
        }

        $brand->update($data);
        return new BrandResource($brand);
    }

    public function destroy(Brand $brand)
    {
        $this->authorize('delete', $brand);

        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }
        $brand->delete();
        return response()->noContent();
    }
}
