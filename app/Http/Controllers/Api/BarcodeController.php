<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUom;
use Illuminate\Http\Request;

class BarcodeController extends Controller
{
    public function lookup(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $productUom = ProductUom::where('barcode', $request->code)
            ->with('product:id,name,material_code') // Hanya ambil data produk yg relevan
            ->first();

        if (!$productUom) {
            return response()->json(['message' => 'Barcode not found'], 404);
        }

        // Anda bisa membuat API Resource untuk ini agar lebih rapi
        return response()->json([
            'data' => [
                'product_id' => $productUom->product->id,
                'product_name' => $productUom->product->name,
                'material_code' => $productUom->product->material_code,
                'uom_name' => $productUom->uom_name,
                'conversion_rate' => $productUom->conversion_rate,
            ]
        ]);
    }
}
