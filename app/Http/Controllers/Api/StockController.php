<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\AdjustStockRequest;
use App\Http\Resources\StockHistoryResource;
use App\Http\Resources\StockResource;
use App\Models\Outlet;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class StockController extends Controller
{
    use AuthorizesRequests;

    /**
     * Menampilkan daftar stok untuk sebuah outlet.
     */
    public function index(Outlet $outlet)
    {
        $this->authorize('viewAny', [Stock::class, $outlet]);
        $stocks = $outlet->stocks()->with('product')->get();
        return StockResource::collection($stocks);
    }

    /**
     * Menyesuaikan jumlah stok dan mencatat riwayatnya.
     */
    public function adjust(AdjustStockRequest $request, Stock $stock)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $stock, $request) {
            $currentStock = $stock->quantity;
            $changeAmount = $data['quantity'];
            $newStock = 0;

            switch ($data['type']) {
                case 'in':
                    $newStock = $currentStock + $changeAmount;
                    break;
                case 'out':
                    // Mencegah stok menjadi negatif
                    if ($currentStock < $changeAmount) {
                        return response()->json(['message' => 'Stock is not sufficient.'], 422);
                    }
                    $newStock = $currentStock - $changeAmount;
                    break;
                case 'adjustment':
                    $newStock = $changeAmount; // Langsung set ke nilai baru
                    break;
            }

            // 1. Update jumlah stok saat ini
            $stock->update(['quantity' => $newStock]);

            // 2. Buat catatan riwayat
            $stock->stockHistories()->create([
                'quantity' => $data['type'] === 'out' ? -$changeAmount : $changeAmount,
                'current_stock' => $newStock,
                'type' => $data['type'],
                'note' => $data['note'],
                'user_id' => $request->user()->id,
                'reference' => 'ADJ-' . $stock->id . '-' . now()->timestamp,
            ]);

            return new StockResource($stock->load('product', 'outlet'));
        });
    }

    /**
     * Menampilkan riwayat dari sebuah item stok.
     */
    public function history(Stock $stock)
    {
        $this->authorize('viewHistory', $stock);
        $histories = $stock->stockHistories()->with('user')->latest()->get();
        return StockHistoryResource::collection($histories);
    }
}
