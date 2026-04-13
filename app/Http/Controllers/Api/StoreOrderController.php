<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Barcode;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promo;
use App\Models\BusinessSetting; // <-- Impor
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Category;
use App\Models\DiscountRule;
use App\Models\Member;
use App\Models\MemberVoucher;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Resources\Json\JsonResource; // <-- Impor
use App\Models\User;
use App\Services\PointService;
use App\Services\PosDiscountService;
use App\Services\PosInventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

class StoreOrderController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;


    private function getOutletId($user)
    {
        if (!empty($user->outlet_id)) {
            return $user->outlet_id;
        }
        if ($user->locationable_type === 'App\\Models\\Outlet' && !empty($user->locationable_id)) {
            return $user->locationable_id;
        }
        // Fallback: Jika tidak ada outlet, return 0 atau throw error
        // Di sini kita return null agar query menghasilkan kosong, bukan error
        return null;
    }

    private function ensureTableIsOccupied(Order $order)
    {
        if (empty($order->table_number)) return;

        $tableCode = trim($order->table_number); // Bersihkan spasi

        // Log untuk debug
        Log::info("POS: Attempting to occupy table '$tableCode' for Outlet ID: {$order->outlet_id}");

        // Cari Table
        $table = \App\Models\Table::where('code', $tableCode)
            ->where('outlet_id', $order->outlet_id)
            ->first();

        if (!$table) {
            Log::warning("POS: Table '$tableCode' NOT FOUND in DB.");
            return;
        }

        // 1. Update Status Fisik Meja (PENTING)
        $table->status = 'occupied';
        $table->save();

        Log::info("POS: Table {$table->id} ($tableCode) status updated to 'occupied'.");

        // 2. Buat Reservasi Bayangan (untuk history & kompatibilitas logic lama)
        $existingRes = Reservation::where('table_id', $table->id)
            ->where('status', 'seated')
            ->exists();

        if (!$existingRes) {
            Reservation::create([
                'outlet_id' => $order->outlet_id,
                'customer_name' => $order->customer_name ?? 'Guest',
                'table_id' => $table->id,
                'guest_count' => $order->guest_count ?? 1,
                'reservation_time' => now(),
                'status' => 'seated',
                'notes' => 'Quick Order #' . $order->order_number,
            ]);
            Log::info("POS: Ghost reservation created for table {$table->id}.");
        }
    }

     /**
     * Helper: Bakar voucher berdasarkan string kode
     * FIX: Menggunakan LOWER() agar Case Insensitive di PostgreSQL
     */
    private function burnVouchers(?string $promoCodeInput)
    {
        if (empty($promoCodeInput)) return;
        $codes = explode(',', $promoCodeInput);
        foreach ($codes as $code) {
            // Case insensitive search
            $v = MemberVoucher::whereRaw('LOWER(code) = ?', [strtolower(trim($code))])
                ->where('is_used', false)
                ->first();
            if ($v) {
                $v->update(['is_used' => true, 'used_at' => now()]);
                Log::info("Voucher Burned (Manual): " . $v->code);
            }
        }
    }

    /**
     * Helper 2: Bakar voucher berdasarkan NAMA RULE (Auto Detect)
     * Ini menangani kasus di mana voucher ter-apply otomatis (tanpa input kode)
     */
    private function detectAndBurnFromRules(array $appliedRules, int $memberId)
    {
        Log::info("Smart Burn Check. Rules: " . json_encode($appliedRules));

        // 1. Bersihkan Nama Rule dari Prefix (Voucher: / Promo:)
        $cleanNames = array_map(function($name) {
            $name = str_replace(['Voucher: ', 'Promo: '], '', $name);
            return trim($name);
        }, $appliedRules);

        // 2. Cari Rule ID di database berdasarkan Nama
        $rules = DiscountRule::whereIn('name', $cleanNames)->get();

        foreach ($rules as $rule) {
            // 3. Cari apakah Member punya voucher AKTIF untuk Rule ID ini?
            // (Ambil voucher yang paling dulu expire atau paling lama dibuat)
            $voucherToBurn = MemberVoucher::where('member_id', $memberId)
                ->where('discount_rule_id', $rule->id)
                ->where('is_used', false)
                ->orderBy('valid_until', 'asc') // Prioritas bakar yang mau kadaluwarsa
                ->orderBy('created_at', 'asc')
                ->first();

            if ($voucherToBurn) {
                $voucherToBurn->update(['is_used' => true, 'used_at' => now()]);
                Log::info("Smart Burn Success: Voucher {$voucherToBurn->code} for Rule '{$rule->name}'");
            }
        }
    }

    public function getPosSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        $taxSetting = BusinessSetting::where('business_id', $user->business_id)
            ->where('type', 'tax')->where('status', true)->first();

        $discountSetting = BusinessSetting::where('business_id', $user->business_id)
            ->where('type', 'discount')->where('status', true)->first();

        return response()->json([
            'tax' => [
                'name' => $taxSetting?->name ?? 'PPN',
                'rate_percent' => (float)($taxSetting?->value ?? 11.0),
            ],
            'global_discount' => [
                'name' => $discountSetting?->name,
                'type' => $discountSetting?->charge_type,
                'value' => (float)($discountSetting?->value ?? 0),
            ]
        ]);
    }

    private function getActiveRegisterId(User $user)
    {
        $register = CashRegister::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$register) {
            throw new \Exception("Shift kasir belum dibuka. Silakan buka shift terlebih dahulu.");
        }
        return $register->id;
    }

    /**
     * Helper: Mencatat transaksi penjualan ke dalam log Cash Register.
     */
    private function recordRegisterTransaction($registerId, Order $order)
    {
        // Hitung amount yang benar-benar masuk ke kas (Total Bayar)
        // Jika ada split payment/point redemption, pastikan amount ini adalah UANG FISIK/DIGITAL yang diterima.
        // Dalam logic Opsi B (Poin sbg Diskon), total_price adalah Net Sales (setelah potong poin).
        CashRegisterTransaction::create([
            'cash_register_id' => $registerId,
            'amount' => $order->total_price, // Uang yang diterima (Net Sales)
            'transaction_type' => 'sell',
            'pay_method' => $order->payment_method, // 'cash', 'card', etc
            'type' => 'credit', // Uang Masuk (Credit)
            'order_id' => $order->id,
            'notes' => "Penjualan Order #{$order->order_number}",
        ]);
    }

    /**
     * Mengecek validitas kode promo dan menghitung diskon.
     */
    /**
     * Mengecek validitas kode promo dan menghitung diskon.
     * UPDATE: Mendukung Partial Success (Mengirim data diskon otomatis meski kode manual gagal).
     */
    public function checkPromo(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'items' => 'required|array',
            'sub_total' => 'required|numeric',
            'promo_code_input' => 'nullable|string',
            'member_id' => 'nullable|integer|exists:members,id',
            'use_points' => 'nullable|boolean',
            // === TAMBAHAN ===
            'ignored_rules' => 'nullable|array',
            'ignored_rules.*' => 'string',
            // ================
        ]);

        try {
            $member = null;
            if (!empty($validated['member_id'])) {
                $member = Member::find($validated['member_id']);
            }
            $usePoints = $validated['use_points'] ?? false;
            $ignoredRules = $validated['ignored_rules'] ?? [];

            $discountResult = PosDiscountService::calculate(
                $validated['items'],
                (float)$validated['sub_total'],
                $user->business_id,
                $validated['promo_code_input'],
                $member,
                $usePoints,
                $ignoredRules // <-- Pass ke Service
            );

            $totalDiscount = $discountResult['total_discount'] ?? 0;
            $appliedRules = $discountResult['applied_rules'] ?? [];

            // Validasi Manual Code (Partial Success Logic)
            if (!empty($validated['promo_code_input'])) {
                 $inputCodes = array_map('trim', explode(',', $validated['promo_code_input']));
                 $allManualCodesApplied = true;
                 foreach ($inputCodes as $code) {
                     if (empty($code)) continue;
                     $isFound = false;
                     foreach($appliedRules as $rule) {
                         if (stripos($rule, $code) !== false) { $isFound = true; break; }
                     }
                     if (!$isFound) { $allManualCodesApplied = false; break; }
                 }

                 if (!$allManualCodesApplied) {
                     return response()->json([
                         'message' => 'Kode promo tidak valid atau syarat tidak terpenuhi.',
                         'total_discount' => $totalDiscount,
                         'applied_rules' => $appliedRules,
                         'points_redeemed' => $discountResult['points_redeemed'] ?? 0,
                         'point_value' => $discountResult['point_value'] ?? 0,
                     ], 422);
                 }
            }

            return response()->json([
                'total_discount' => $totalDiscount,
                'applied_rules' => $appliedRules,
                'points_redeemed' => $discountResult['points_redeemed'] ?? 0,
                'point_value' => $discountResult['point_value'] ?? 0,
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order tidak dapat dibatalkan (sudah dibayar/selesai).'], 403);
        }

        DB::transaction(function () use ($order) {
            // 1. Update Status Order
            $order->update(['status' => 'cancelled']);

            // 2. Release Table (Jika ada)
            if ($order->table_number) {
                $table = Table::where('code', $order->table_number)
                    ->where('outlet_id', $order->outlet_id)
                    ->first();

                if ($table) {
                    // Cek apakah ada order LAIN yang masih aktif di meja ini? (Untuk kasus split bill)
                    $otherActiveOrders = Order::where('table_number', $order->table_number)
                        ->where('outlet_id', $order->outlet_id)
                        ->where('id', '!=', $order->id)
                        ->whereIn('status', ['pending', 'processing', 'unpaid'])
                        ->exists();

                    // Jika tidak ada order lain, kosongkan meja
                    if (!$otherActiveOrders) {
                        $table->status = 'available';
                        $table->save();

                        // Update Reservasi jadi cancelled juga
                        Reservation::where('table_id', $table->id)
                            ->where('status', 'seated')
                            ->update(['status' => 'cancelled']);
                    }
                }
            }
        });

        return response()->json(['success' => true, 'message' => 'Order berhasil dibatalkan.']);
    }

    public function getCategories(Request $request): JsonResponse
    {
        $user = $request->user();

        $categories = Category::where('business_id', $user->business_id)
            ->where('status', true)
            ->whereHas('products', function ($query) {
                $query->where('is_sellable_pos', true)
                      ->where('product_type', 'finished_good')
                      ->where('status', true);
            })
            ->select(['id', 'name', 'icon'])
            ->orderBy('name', 'asc')
            ->get();

        // Tambahkan kategori "Semua" (Opsional, tergantung frontend butuh atau tidak)
        // $allCategories = collect([['id' => null, 'name' => 'Semua', 'icon' => null]]);
        // return response()->json($allCategories->merge($categories));

        return response()->json($categories);
    }

    public function getProducts(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->locationable_type !== 'App\\Models\\Outlet') {
             return response()->json([]);
        }

        $outlet = $user->locationable;
        /** @var \App\Models\Outlet $outlet */
        // if (!$outletId) return response()->json([]);

        // $outlet = Outlet::find($outletId);
        $priceListId = $outlet->price_list_id;
        $filterCategoryId = $request->input('category_id');

        $productsQuery = Product::where('business_id', $user->business_id)
            ->where('product_type', 'finished_good')
            ->where('status', true)
            ->where('is_sellable_pos', true)
            ->with([
                'category',
                'uoms' => function ($query) {
                    $query->where('uom_type', 'selling');
                },
                'bom.items.product',
                'addons'
            ]);

        if ($filterCategoryId) {
            $productsQuery->where('category_id', $filterCategoryId);
        }

        $products = $productsQuery
            ->leftJoin('price_list_items', function ($join) use ($priceListId) {
                $join->on('products.id', '=', 'price_list_items.product_id')
                     ->where('price_list_items.price_list_id', '=', $priceListId);
            })
            ->select('products.*', DB::raw('COALESCE(price_list_items.price, products.price) as final_price'))
            ->get();
            
        /** @var \App\Models\Outlet $outlet */
        $enrichedProducts = $products->map(function ($item) use ($outlet) {
            /** @var \App\Models\Product $item */
            $productModel = $item;
            
            $stockInfo = PosInventoryService::getProductAvailability($productModel, $outlet);
            // Panggil Service POS untuk list promo visual
            $promotions = PosDiscountService::getApplicablePromosForProduct($productModel, $outlet);

            return [
                'productId' => $productModel->id,
                'name' => $productModel->name,
                'price' => (float) $productModel->final_price,
                'category' => $productModel->category?->name ?? 'Uncategorized',
                'thumbnail' => $productModel->thumbnail,
                'barcode' => $productModel->sku,
                'uoms' => $productModel->uoms,
                'addons' => $productModel->addons->map(function ($addon) {
                    return [
                        'id' => $addon->id,
                        'name' => $addon->name,
                        'price' => (float) ($addon->pivot->price ?? $addon->price),
                        'is_active' => (bool) $addon->pivot->is_active,
                    ];
                }),
                'is_popular' => (bool) ($productModel->is_popular ?? false),
                'calories' => $productModel->calories,
                'stockInfo' => $stockInfo,
                'promotions' => $promotions,
            ];
        });

        return response()->json($enrichedProducts);
    }

    public function getTables(Request $request): JsonResponse
    {
        $user = $request->user();
        $outlet = $user->locationable;

        $tables = Table::where('outlet_id', $outlet->id)
                         ->orderBy('code', 'asc')
                         ->get();

        return response()->json($tables);
    }

    /**
     * [QUICK CHECKOUT]
     * Menerima cart, member_id, guest_count, dll.
     * Menghitung Poin, Menyimpan Order, dan Membakar Voucher.
     */
    public function storeQuickCheckout(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $registerId = $this->getActiveRegisterId($user);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.uom' => 'required|string',
            'items.*.note' => 'nullable|string',
            'items.*.addons' => 'nullable|array',
            'items.*.addons.*.addon_id' => 'required|exists:products,id',
            'items.*.addons.*.quantity' => 'required|numeric|min:1',
            'items.*.addons.*.price' => 'nullable|numeric',
            'payment_method' => ['required', Rule::in(['cash', 'card', 'qris', 'transfer'])],
            'promo_code_input' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'type_order' => 'required|string', 'table_number' => 'nullable|string', 'guest_count' => 'nullable|integer',
            'member_id' => 'nullable|exists:members,id',
            'use_points' => 'nullable|boolean',
            'ignored_rules' => 'nullable|array',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($request->hasFile('proof')) {
            $validated['proof'] = $request->file('proof')->store('proofs', 'public');
        }

        try {
            $order = $this->recalculateAndCreateOrder($user, $validated, 'paid', $registerId);

            // === BURN LOGIC ===
            $inputCode = $validated['promo_code_input'] ?? null;
            $memberId = $validated['member_id'] ?? null;
            $burned = false;

            // 1. Burn Manual Code
            if (!empty($inputCode)) {
                $this->burnVouchers($inputCode);
                $burned = true;
            }

            // 2. Fallback: Burn dari Rule Name (Smart Detect)
            // Jika manual code kosong ATAU tidak ada kode yang terbakar, cek rule otomatis
            if (!empty($order->applied_rules) && $memberId) {
                $this->detectAndBurnFromRules($order->applied_rules, $memberId);
            }

            return (new OrderResource($order->load(['items.product', 'items.addons.addonProduct', 'promoCode', 'member'])))
                ->response()->setStatusCode(201);

        } catch (\Exception $e) {
            Log::error("POS Quick Checkout Error: " . $e->getMessage());
            return response()->json(['message' => 'Gagal membuat order: ' . $e->getMessage()], 400);
        }
    }

    // ==========================================================
    // === ALUR OPEN BILL (DINE-IN) ===
    // ==========================================================

    public function getOpenOrders(Request $request): JsonResponse
    {
        $user = $request->user();
        $openOrders = Order::where('outlet_id', $user->locationable_id)
            ->whereIn('status', ['pending', 'processing', 'unpaid'])
            ->with(['items.product', 'items.addons.addonProduct'])
            ->latest()
            ->get();

        return OrderResource::collection($openOrders)->response();
    }

    /**
     * Membuat Open Bill.
     * UPDATE: Menyimpan Member ID.
     */
    public function storeOpenBill(Request $request): JsonResponse
    {
        $user = $request->user();
        $outlet = $user->locationable;

        $validated = $request->validate([
            'type_order' => 'required|string',
            'table_number' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'guest_count' => 'nullable|integer|min:1',
            // === MEMBER ID ===
            'member_id' => 'nullable|exists:members,id',
            // =================
        ]);

        // Logic Nama
        $memberName = null;
        if (!empty($validated['member_id'])) {
             $member = Member::find($validated['member_id']);
             $memberName = $member?->name;
        }
        $customerName = $validated['customer_name'] ?? $memberName ?? $validated['table_number'] ?? 'Guest';

        $order = Order::create([
            'order_number' => 'POS-' . random_int(100000, 999999),
            'outlet_id' => $outlet->id,
            'cashier_id' => $user->id,
            'business_id' => $user->business_id,
            'sub_total' => 0,
            'total_price' => 0,
            'total_items' => 0,
            'tax' => 0,
            'discount' => 0,
            'payment_method' => 'cash',
            'status' => 'pending',
            'type_order' => $validated['type_order'],
            'table_number' => $validated['table_number'] ?? null,
            'customer_name' => $customerName,
            'guest_count' => $validated['guest_count'] ?? 1,

            // === SIMPAN MEMBER ID ===
            'member_id' => $validated['member_id'] ?? null,
            // ========================
        ]);

        $this->ensureTableIsOccupied($order);
        $order->load('member');
        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);
        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode']);
        return new OrderResource($order);
    }

    public function addItem(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order sudah ditutup.'], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:1',
            'uom' => 'required|string',
            'note' => 'nullable|string',
            'addons' => 'nullable|array',
            'addons.*.addon_id' => 'required|exists:products,id',
            'addons.*.quantity' => 'required|numeric|min:1',
            'addons.*.price' => 'nullable|numeric',
        ]);

        $outlet = $order->outlet;
        $calculated = $this->calculateItemPrice($validated, $outlet);

        $orderItem = $order->items()->create($calculated['item_data']);
        if (!empty($calculated['addons_data'])) {
            $orderItem->addons()->createMany($calculated['addons_data']);
        }

        // Hitung ulang dengan request (agar user/business_id terbawa)
        $this->recalculateOrderTotals($request, $order);

        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode']);
        return (new OrderResource($order))->response();
    }

    public function removeItem(Request $request, OrderItem $item): JsonResponse
    {
        $order = $item->order;
        $this->authorize('update', $order);

        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order sudah ditutup.'], 403);
        }

        $item->delete();
        $this->recalculateOrderTotals($request, $order);
        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode']);
        return (new OrderResource($order))->response();
    }

    public function removePromo(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);
        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order sudah ditutup.'], 403);
        }

        $order->promo_code = null;
        $order->save();

        // Recalculate dengan promoCodeInput = null (hapus)
        $this->recalculateOrderTotals($request, $order, null);

        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode']);
        return (new OrderResource($order))->response();
    }

    public function applyPromo(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);
        $validated = $request->validate(['promo_code' => 'required|string']);

        try {
             $this->recalculateOrderTotals($request, $order, $validated['promo_code']);
             return (new OrderResource($order->load(['items.product', 'items.addons.addonProduct'])))->response();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * [PAY OPEN BILL]
     * Menutup tagihan (Pembayaran).
     * Logika: Hitung Final -> Update Status -> Handle Poin -> Handle Reservasi -> Burn Voucher.
     */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        try {
            $user = $request->user();
            $registerId = $this->getActiveRegisterId($user);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order sudah dibayar/batal.'], 403);
        }

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['cash', 'card', 'qris', 'transfer'])],
            'promo_code_input' => 'nullable|string',
            'use_points' => 'nullable|boolean',
            'member_id' => 'nullable|exists:members,id',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $proofPath = $order->proof;
        if ($request->hasFile('proof')) {
            $proofPath = $request->file('proof')->store('proofs', 'public');
        }

        $promoCode = $validated['promo_code_input'] ?? null;
        $usePoints = $validated['use_points'] ?? false;

        if (!empty($validated['member_id'])) {
            $order->member_id = $validated['member_id'];
            if ($order->customer_name === 'Guest' || empty($order->customer_name)) {
                 $m = Member::find($validated['member_id']);
                 if($m) $order->customer_name = $m->name;
            }
            $order->save();
            $order->refresh();
        }

        $this->recalculateOrderTotals($request, $order, $promoCode, $usePoints);
        $order->refresh();

        $promo = null;
        if ($promoCode) {
             $promo = Promo::where('code', $promoCode)->where('business_id', $order->business_id)->first();
        }

        DB::transaction(function () use ($order, $validated, $promo, $registerId, $promoCode, $proofPath) {
            $order->update([
                'status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'promo_code' => $promo?->id,
                'cash_register_id' => $registerId,
                'proof' => $proofPath,
            ]);

            $this->recordRegisterTransaction($registerId, $order);

            $order->loadMissing('items.product');
            $pointsEarned = 0;
            if ($order->member_id && $order->total_price > 0) {
                $pointsEarned = PointService::calculateEarnedPoints($order);
            }
            $order->points_earned = $pointsEarned;
            $pointsRedeemed = $order->points_redeemed;
            $order->save();

            if ($order->member_id) {
                $member = Member::find($order->member_id);
                if ($member) {
                    if ($pointsRedeemed > 0) $member->decrement('current_points', $pointsRedeemed);
                    if ($pointsEarned > 0) $member->increment('current_points', $pointsEarned);
                    $member->update(['last_transaction_at' => now()]);
                }
            }

            if ($order->table_number) {
                $table = Table::where('code', $order->table_number)->where('outlet_id', $order->outlet_id)->first();
                if ($table) {
                    $reservation = Reservation::where('table_id', $table->id)->where('status', 'seated')->first();
                    if ($reservation) { $reservation->update(['status' => 'completed']); }
                    $table->status = 'available';
                    $table->save();
                }
            }

            // === BURN LOGIC ===
            if (!empty($promoCode)) {
                $this->burnVouchers($promoCode);
            }
            else if (!empty($order->applied_rules) && $order->member_id) {
                // Fallback: Smart Detect dari Applied Rules
                $this->detectAndBurnFromRules($order->applied_rules, $order->member_id);
            }
        });

        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode', 'member']);
        return (new OrderResource($order))->response();
    }



    /**
     * [BARU] Hitung ulang order (tanpa ubah item/promo)
     * Digunakan untuk fitur "Ignore Rule" dan "Redeem Poin" pada Open Bill.
     */
    public function recalculate(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        if (!in_array(strtolower($order->status), ['pending', 'processing', 'unpaid'])) {
            return response()->json(['message' => 'Order sudah ditutup.'], 403);
        }

        $validated = $request->validate([
            'ignored_rules' => 'nullable|array',
            'ignored_rules.*' => 'string',
            'use_points' => 'nullable|boolean',
            // ================
        ]);

        $usePoints = $validated['use_points'] ?? false;

        // Panggil helper dengan parameter usePoints
        $this->recalculateOrderTotals($request, $order, null, $usePoints);

        $order->load(['items.product', 'items.addons.addonProduct', 'promoCode', 'member']);
        return (new OrderResource($order))->response();
    }



    private function recalculateAndCreateOrder(User $user, array $validatedData, string $status, int $registerId): Order
    {
        $outlet = $user->locationable;
        $businessId = $user->business_id;
        $promoCodeString = $validatedData['promo_code_input'] ?? null;
        $memberId = $validatedData['member_id'] ?? null;
        $member = $memberId ? Member::find($memberId) : null;
        $usePoints = $validatedData['use_points'] ?? false;
        $ignoredRules = $validatedData['ignored_rules'] ?? [];

        $itemsCalculated = new Collection();
        $subTotal = 0;

        foreach ($validatedData['items'] as $item) {
            $calculated = $this->calculateItemPrice($item, $outlet);
            $orderItemModel = new OrderItem($calculated['item_data']);
            $orderItemModel->_addons_data = $calculated['addons_data'];
            $itemsCalculated->push($orderItemModel);
            $subTotal += $calculated['item_data']['total'];
        }

        $taxSetting = BusinessSetting::where('business_id', $businessId)->where('type', 'tax')->where('status', true)->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($subTotal * $taxPercent) / 100;

        // Hitung Diskon
        $itemsForService = $itemsCalculated->map(fn($i) => $i->toArray())->toArray();
        $discountResult = PosDiscountService::calculate($itemsForService, $subTotal, $businessId, $promoCodeString, $member, $usePoints, $ignoredRules);

        $discountAmount = $discountResult['total_discount'];
        $pointsRedeemed = $discountResult['points_redeemed'] ?? 0;
        $appliedRules = $discountResult['applied_rules'] ?? []; // <-- AMBIL INI

        $grandTotal = ($subTotal + $taxAmount) - $discountAmount;
        if ($grandTotal < 0) $grandTotal = 0;

        $totalItemsInBaseUom = 0;
        foreach ($itemsCalculated as $item) {
            $product = Product::with('uoms')->find($item->product_id);
            $uomData = $product->uoms->where('uom_name', $item->uom)->first();
            $conversionRate = $uomData?->conversion_rate ?? 1;
            $totalItemsInBaseUom += $item->quantity * $conversionRate;
        }

        $promo = Promo::where('code', $promoCodeString)->first();
        $pointsEarned = 0;

        return DB::transaction(function () use ($user, $validatedData, $status, $subTotal, $taxAmount, $discountAmount, $grandTotal, $totalItemsInBaseUom, $promo, $itemsCalculated, $businessId, $memberId, $pointsRedeemed, $member, $registerId, $pointsEarned, $promoCodeString, $appliedRules) {

            $order = Order::create([
                'order_number' => 'POS-' . random_int(100000, 999999),
                'outlet_id' => $user->locationable_id,
                'cashier_id' => $user->id,
                'business_id' => $businessId,
                'sub_total' => $subTotal,
                'tax' => $taxAmount,
                'discount' => $discountAmount,
                'total_price' => $grandTotal,
                'total_items' => $totalItemsInBaseUom,
                'payment_method' => $validatedData['payment_method'],
                'status' => $status,
                'type_order' => $validatedData['type_order'] ?? 'Online',
                'table_number' => $validatedData['table_number'] ?? null,
                'customer_name' => $validatedData['customer_name'] ?? ($member ? $member->name : 'Guest'),
                'guest_count' => $validatedData['guest_count'] ?? 1,
                'promo_code' => $promo?->id,
                'member_id' => $memberId,
                'points_redeemed' => $pointsRedeemed,
                'points_earned' => $pointsEarned,
                'cash_register_id' => $registerId,
                'applied_rules' => $appliedRules,
                'proof' => $validatedData['proof'] ?? null,
            ]);
            $order->items()->saveMany($itemsCalculated);
            foreach ($itemsCalculated as $savedItem) {
                if (!empty($savedItem->_addons_data)) {
                    $savedItem->addons()->createMany($savedItem->_addons_data);
                }
            }

            if ($status === 'paid') {
                $this->recordRegisterTransaction($registerId, $order);
                $order->load(['items.product', 'items.addons.addonProduct']);
                $earned = \App\Services\PointService::calculateEarnedPoints($order);
                $order->update(['points_earned' => $earned]);

                if ($member) {
                    if ($pointsRedeemed > 0) $member->decrement('current_points', $pointsRedeemed);
                    if ($earned > 0) $member->increment('current_points', $earned);
                    $member->update(['last_transaction_at' => now()]);
                }
                if (!empty($promoCodeString)) { $this->burnVouchers($promoCodeString); }
            }
            return $order;
        });
    }

    /**
     * Helper Private: Hitung ulang Open Bill (saat add/remove item atau apply promo)
     */
        private function recalculateOrderTotals(Request $request, Order $order, ?string $promoCodeInput = null, bool $usePoints = false)
    {
        $user = $request->user();
        $businessId = $user->business_id;
        $promoCode = $promoCodeInput ?? $order->promoCode?->code;
        $member = $order->member;
        $ignoredRules = $request->input('ignored_rules', []);

        $order->load('items.product.uoms');
        $itemsArray = $order->items->map(fn ($item) => $item->toArray())->toArray();
        $subTotal = $order->items->sum('total');

        $taxSetting = BusinessSetting::where('business_id', $businessId)->where('type', 'tax')->where('status', true)->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($subTotal * $taxPercent) / 100;

        // Hitung Diskon
        $discountResult = PosDiscountService::calculate(
            $itemsArray,
            $subTotal,
            $businessId,
            $promoCode,
            $member,
            $usePoints,
            $ignoredRules
        );

        $discountAmount = $discountResult['total_discount'];
        $pointsRedeemed = $discountResult['points_redeemed'] ?? 0;
        $appliedRules = $discountResult['applied_rules'] ?? []; // <-- AMBIL INI

        $grandTotal = ($subTotal + $taxAmount) - $discountAmount;
        if ($grandTotal < 0) $grandTotal = 0;

        $totalItemsInBaseUom = 0;
        foreach ($order->items as $item) {
            $uomData = $item->product->uoms->where('uom_name', $item->uom)->first();
            $conversionRate = $uomData?->conversion_rate ?? 1;
            $totalItemsInBaseUom += $item->quantity * $conversionRate;
        }

        $order->update([
            'sub_total' => $subTotal,
            'tax' => $taxAmount,
            'discount' => $discountAmount,
            'total_price' => $grandTotal,
            'total_items' => $totalItemsInBaseUom,
            'points_redeemed' => $pointsRedeemed,
            'applied_rules' => $appliedRules,
            // ====================
        ]);
    }


    private function calculateItemPrice(array $item, $outlet): array
    {
        $product = Product::with(['uoms', 'priceListItems'])->find($item['product_id']);
        $basePrice = 0;
        if ($outlet && $outlet->price_list_id) {
            $priceListItem = $product->priceListItems->where('price_list_id', $outlet->price_list_id)->first();
            $basePrice = $priceListItem?->price ?? $product->price ?? 0;
        } else {
            $basePrice = $product->price ?? 0;
        }
        $uomData = $product->uoms->where('uom_name', $item['uom'])->first();
        $conversionRate = $uomData?->conversion_rate ?? 1;
        $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;
        $pricePerSelectedUom = $basePrice * $conversionRate;
        $totalItemPrice = $pricePerSelectedUom * $item['quantity'];

        $addonsData = [];
        $addonsTotal = 0;

        if (!empty($item['addons']) && is_array($item['addons'])) {
            foreach ($item['addons'] as $addon) {
                $addonProduct = Product::find($addon['addon_id']);
                $addonPrice = isset($addon['price']) ? (float)$addon['price'] : (float)($addonProduct->price ?? 0);
                $addonQty = isset($addon['quantity']) ? (float)$addon['quantity'] : 1;
                $addonTotal = $addonPrice * $addonQty;
                
                $addonsTotal += $addonTotal;
                $addonsData[] = [
                    'addon_product_id' => $addon['addon_id'],
                    'quantity' => $addonQty,
                    'price' => $addonPrice,
                    'total' => $addonTotal,
                ];
            }
        }

        return [
            'item_data' => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'uom' => $item['uom'],
                'price' => $pricePerSelectedUom,
                'total' => $totalItemPrice + $addonsTotal,
                'note' => $item['note'] ?? null,
            ],
            'addons_data' => $addonsData
        ];
    }
}
