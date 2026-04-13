<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use App\Http\Requests\API\StoreBusinessSettingRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Models\Business;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class BusinessSettingController extends Controller
{
    use AuthorizesRequests;
    // public function __construct()
    // {
    //     // Menerapkan Policy ke semua method di controller ini
    //     $this->authorizeResource(BusinessSetting::class, 'business_setting');
    // }

    /**
     * Menampilkan daftar setting untuk sebuah bisnis.
     */
    public function index(Business $business)
    {
        // Pengecekan hak akses tambahan (opsional tapi bagus)
    $this->authorize('viewAnyBusinessSettings', $business);

    $settings = BusinessSetting::where('business_id', $business->id)
                               ->where('status', true)
                               ->get();

    return BusinessSettingResource::collection($settings);
    }

    /**
     * Menyimpan business setting baru.
     */
    public function store(StoreBusinessSettingRequest $request)
    {
        $data = $request->validated();
        // Otomatis isi business_id dari user yang login, bukan dari input
        $data['business_id'] = $request->user()->business_id;

        $businessSetting = BusinessSetting::create($data);

        return new BusinessSettingResource($businessSetting);
    }

    /**
     * Menampilkan satu business setting spesifik.
     */
    public function show(BusinessSetting $businessSetting)
    {
        $this->authorize('view', $businessSetting);
        return new BusinessSettingResource($businessSetting);
    }

    /**
     * Mengupdate business setting.
     * (Untuk update, kita buat Form Request terpisah jika validasinya berbeda)
     */
    public function update(Request $request, BusinessSetting $businessSetting)
    {
        // Anda bisa membuat UpdateBusinessSettingRequest jika perlu
        $this->authorize('update', $businessSetting);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'charge_type' => 'sometimes|string|in:fixed,percent',
            'type' => 'sometimes|string|max:255',
            'value' => 'sometimes|string|max:255',
            'status' => 'sometimes|boolean',
        ]);

        $businessSetting->update($validated);

        return new BusinessSettingResource($businessSetting);
    }

    /**
     * Menghapus business setting.
     */
    public function destroy(BusinessSetting $businessSetting)
    {
        $this->authorize('delete', $businessSetting);
        $businessSetting->delete();

        return response()->json(['message' => 'Business setting deleted successfully'], 200);
    }

}
