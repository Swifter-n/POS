<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use App\Http\Requests\API\StoreOutletRequest;
use App\Http\Resources\OutletResource;
use App\Models\Outlet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class OutletController extends Controller
{
    use AuthorizesRequests;

    /**
     * Menampilkan daftar outlet untuk sebuah bisnis.
     * URL: GET /api/businesses/{business}/outlets
     */
    public function index(Business $business)
    {
        $this->authorize('viewAny', [Outlet::class, $business]);
        return OutletResource::collection($business->outlets()->where('status', true)->get());
        //return OutletResource::collection($business->outlets);
    }

    /**
     * Menyimpan outlet baru untuk sebuah bisnis.
     * URL: POST /api/businesses/{business}/outlets
     */
    public function store(StoreOutletRequest $request, Business $business)
    {
        $data = $request->validated();
        $outlet = $business->outlets()->create($data);

        return new OutletResource($outlet);
    }

    /**
     * Menampilkan satu outlet spesifik.
     * URL: GET /api/outlets/{outlet} (Rute ini tidak dibuat oleh `businesses.outlets`, perlu dibuat terpisah jika butuh)
     */
    public function show(Business $business, Outlet $outlet)
    {
        $this->authorize('view', $outlet);
        return new OutletResource($outlet);
    }

    /**
     * Mengupdate data outlet.
     * URL: PUT/PATCH /api/outlets/{outlet}
     */
    public function update(Request $request, Business $business, Outlet $outlet)
    {
        $this->authorize('update', $outlet);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'sometimes|boolean',
        ]);

        $outlet->update($validated);
        return new OutletResource($outlet);
    }

    /**
     * Menghapus outlet.
     * URL: DELETE /api/outlets/{outlet}
     */
    public function destroy(Business $business, Outlet $outlet)
    {
        $this->authorize('delete', $outlet);
        $outlet->delete();
        return response()->noContent(); // Standar respons untuk delete
    }

    /**
 * Mendapatkan outlet berdasarkan user yang sedang login.
 * Jika owner, ambil outlet pertama. Jika staf/manajer, ambil outlet spesifiknya.
 */
public function showByUser(Request $request)
{
    $user = $request->user();
    $outlet = null;

    // Pastikan user punya business_id sebelum melanjutkan
    if (!$user->business_id) {
        return response()->json(['message' => 'User is not associated with any business.'], 404);
    }

    if ($user->role_id === 1) { // Asumsi 1 = Owner
        // Ambil outlet pertama dari bisnisnya yang aktif
        $outlet = Outlet::where('business_id', $user->business_id)->where('status', true)->first();
    } else if ($user->outlet_id) {
        // Ambil outlet spesifik milik staf/manajer
        $outlet = Outlet::where('id', $user->outlet_id)->where('status', true)->first();
    }

    // Jika tidak ada outlet yang ditemukan
    if (!$outlet) {
        return response()->json(['message' => 'No active outlet found for this user.'], 404);
    }

    return new OutletResource($outlet);
}

}
