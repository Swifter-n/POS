<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        // Asumsi user punya policy 'viewAny' untuk PO
        $this->authorize('viewAny', PurchaseOrder::class);

        // Ambil PO yang statusnya 'shipped' untuk bisnis user
        $purchaseOrders = PurchaseOrder::where('business_id', $request->user()->business_id)
            ->where('status', 'shipped')
            ->with('items.product') // Eager load detail item & produk
            ->latest()
            ->get();

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);
        return new PurchaseOrderResource($purchaseOrder->load('items.product'));
    }
}
