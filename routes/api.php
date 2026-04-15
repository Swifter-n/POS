<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Api\BarcodeController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BusinessSettingController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\API\GoodsReceiptController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\OutletController;
use App\Http\Controllers\Api\PickingApiController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\StoreOrderController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PrinterSettingController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\PutawayApiController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\StockCountApiController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\WmsStockCountApiController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// Public routes
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Kita juga tambahkan 'logout' dan 'me' yang dilindungi sanctum
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/fcm-token', [AuthController::class, 'updateFcmToken']);
        Route::get('notifications', [NotificationApiController::class, 'index']);
        Route::post('notifications/{id}/read', [NotificationApiController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationApiController::class, 'markAllRead']);
    });

    Route::middleware(['auth:sanctum', 'user.location.pos'])->group(function () {
    Route::get('/pos/settings', [StoreOrderController::class, 'getPosSettings']);


    // === ROUTE BARU ANDA UNTUK KATEGORI ===
    Route::get('/pos/categories', [StoreOrderController::class, 'getCategories']);


    // --- Route Anda yang sudah ada ---
    Route::get('/pos/printers', [PrinterSettingController::class, 'getPrinterSettings']);
    Route::get('/pos/products', [StoreOrderController::class, 'getProducts']);
    Route::post('/pos/quick-checkout', [StoreOrderController::class, 'storeQuickCheckout']);

    // Rute untuk Open Bill
    Route::apiResource('/pos/tables', TableController::class);
    Route::post('/pos/tables/{id}/clear', [TableController::class, 'clear']);
    Route::post('/pos/tables/positions', [TableController::class, 'updatePositions']);
    

    Route::get('/pos/orders/open', [StoreOrderController::class, 'getOpenOrders']);
    Route::post('/pos/orders/open-bill', [StoreOrderController::class, 'storeOpenBill']);
    Route::get('/pos/orders/{order}', [StoreOrderController::class, 'show']); // Detail 1 bill
    Route::post('/pos/orders/{order}/add-item', [StoreOrderController::class, 'addItem']); // Tambah item
    Route::post('/pos/orders/{order}/pay', [StoreOrderController::class, 'pay']);
    Route::post('/pos/orders/{order}/cancel', [StoreOrderController::class, 'cancel']);
    Route::delete('/pos/orders/items/{item}', [StoreOrderController::class, 'removeItem']);
    Route::post('/pos/orders/{order}/remove-promo', [StoreOrderController::class, 'removePromo']);
    Route::post('/pos/orders/{order}/apply-promo', [StoreOrderController::class, 'applyPromo']);
    Route::post('/pos/orders/{order}/recalculate', [StoreOrderController::class, 'recalculate']);
    Route::post('/pos/orders/{id}/cancel-order', [StoreOrderController::class, 'cancelOrder']);
    Route::post('/pos/orders/{id}/move-table', [StoreOrderController::class, 'moveTable']);
    Route::apiResource('/pos/reservations', ReservationController::class);
    Route::post('/pos/reservations/{id}/status', [ReservationController::class, 'updateStatus']);

    
    Route::get('/pos/members/check', [MemberController::class, 'check']);
    Route::post('/pos/members/register', [MemberController::class, 'register']);
    Route::get('/pos/members', [MemberController::class, 'index']);
    Route::put('/pos/members/{id}', [MemberController::class, 'update']);
    Route::get('/pos/rewards', [RewardController::class, 'index']);
    Route::post('/pos/rewards/{id}/redeem', [RewardController::class, 'redeem']);
    Route::post('/pos/check-promo', [StoreOrderController::class, 'checkPromo']);
    Route::get('/pos/promos', [PromoController::class, 'index']);
    Route::get('/pos/shift/status', [CashRegisterController::class, 'status']);
    Route::post('/pos/shift/open', [CashRegisterController::class, 'open']);
    Route::get('/pos/shift/summary', [CashRegisterController::class, 'summary']);
    Route::post('/pos/shift/close', [CashRegisterController::class, 'close']);

    Route::get('/pos/inventory', [InventoryController::class, 'index']);
    Route::get('/pos/stock-counts', [StockCountApiController::class, 'index']); // <-- Ini yang menyebabkan 404 jika hilang
    Route::get('/pos/stock-counts/{id}', [StockCountApiController::class, 'show']);
    Route::post('/pos/stock-counts/items/{id}', [StockCountApiController::class, 'updateItem']);
    Route::post('/pos/stock-counts/{id}/submit', [StockCountApiController::class, 'submit']);


});

Route::middleware(['auth:sanctum'])->prefix('wms')->group(function () {
    // --- PUTAWAY TASKS ---
    Route::get('putaway-tasks', [PutawayApiController::class, 'index']);
    Route::get('putaway-tasks/{id}', [PutawayApiController::class, 'show']);
    Route::post('putaway-tasks/{id}/start', [PutawayApiController::class, 'start']);
    Route::post('putaway-tasks/{id}/log-entry', [PutawayApiController::class, 'logEntry']);
    Route::post('putaway-tasks/{id}/execute', [PutawayApiController::class, 'execute']);
    Route::get('master-locations', [PutawayApiController::class, 'getMasterLocations']);

    Route::post('picking-tasks/{id}/start', [PickingApiController::class, 'start']);
    Route::get('picking-tasks', [PickingApiController::class, 'index']);
    Route::get('picking-tasks/{id}', [PickingApiController::class, 'show']);
    Route::post('picking-tasks/{id}/submit', [PickingApiController::class, 'submit']);
    Route::post('picking-tasks/{id}/finish', [PickingApiController::class, 'finish']);

    Route::get('stock-counts', [WmsStockCountApiController::class, 'index']);
    Route::get('stock-counts/{id}', [WmsStockCountApiController::class, 'show']);
    //Route::post('stock-counts/{id}/entry', [WmsStockCountApiController::class, 'submitEntry']);
    Route::post('stock-counts/{id}/entry', [WmsStockCountApiController::class, 'submitEntry']);
    // [BARU] Endpoint Validasi
    Route::post('stock-counts/{id}/validate', [WmsStockCountApiController::class, 'validateItem']);

});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('game/config', [GameController::class, 'index']);
    Route::post('game/spin', [GameController::class, 'spin']);
});

});
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);

// Protected routes (membutuhkan token)
// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/me', [AuthController::class, 'me']);
//     Route::post('/logout', [AuthController::class, 'logout']);

//     Route::get('/businesses/{business}/settings', [BusinessSettingController::class, 'index']);
//     Route::apiResource('business-settings', BusinessSettingController::class)->except(['index']);

//     //outlets
//     Route::apiResource('businesses.outlets', OutletController::class);
//     Route::get('/user/outlet', [OutletController::class, 'showByUser']);

//     Route::apiResource('users', UserController::class);

//     Route::apiResource('categories', CategoryController::class);

//     Route::apiResource('brands', BrandController::class);

//      // Rute untuk mendapatkan produk berdasarkan kategori
//     Route::get('/categories/{category}/products', [ProductController::class, 'indexByCategory']);
//     // Rute untuk mendapatkan produk berdasarkan brand
//     Route::get('/brands/{brand}/products', [ProductController::class, 'indexByBrand']);
//     // Rute standar untuk Product
//     Route::apiResource('products', ProductController::class);

//     // Rute untuk Purchase Order (hanya melihat daftar dan detail)
//     Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'show']);

//     // Rute untuk Goods Receipt
//     Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store', 'show']);

//     Route::get('/barcode-lookup', [BarcodeController::class, 'lookup']);

//     // Rute untuk Stok
//     // Melihat semua stok di sebuah outlet
//     //Route::get('/outlets/{outlet}/stocks', [StockController::class, 'index']);
//     // Menyesuaikan (tambah/kurang/ubah) jumlah stok
//     //Route::post('/stocks/{stock}/adjust', [StockController::class, 'adjust']);
//     // Melihat riwayat dari satu item stok
//     //Route::get('/stocks/{stock}/history', [StockController::class, 'history']);

// });
