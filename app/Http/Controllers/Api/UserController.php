<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\StoreUserRequest;
use App\Http\Requests\API\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UserController extends Controller
{
    use AuthorizesRequests;

    // public function __construct()
    // {
    //     $this->authorizeResource(User::class, 'user');

    // }

    /**
     * Menampilkan daftar user (staf/manajer) di dalam bisnis milik user yang login.
     * URL: GET /api/users
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::where('business_id', $request->user()->business_id)
                     ->with(['role', 'outlet'])
                     ->get();

        return UserResource::collection($users);
    }

    /**
     * Menambahkan user baru (staf atau manajer) ke dalam bisnis.
     * URL: POST /api/users
     */
    public function store(StoreUserRequest $request)
    {
        $validatedData = $request->validated();
        $user = User::create([
            'nik' => $validatedData['nik'],
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'phone' => $validatedData['phone'],
            'password' => Hash::make($validatedData['password']),
            'role_id' => $validatedData['role_id'],
            'outlet_id' => $validatedData['outlet_id'],
            'business_id' => $request->user()->business_id,
            'status' => true,
        ]);
        return new UserResource($user);
    }

    /**
     * Menampilkan detail satu user.
     * URL: GET /api/users/{user}
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);
        return new UserResource($user->load(['role', 'outlet']));
    }

    /**
     * Mengupdate data user (staf/manajer).
     * URL: PUT/PATCH /api/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $validatedData = $request->validated();

        // Cek jika ada password baru, jika tidak, jangan update password
        if (!empty($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        } else {
            unset($validatedData['password']);
        }

        $user->update($validatedData);

        return new UserResource($user);
    }

    /**
     * Menghapus user.
     */
    public function destroy(User $user)
    {
        // Otorisasi manual untuk 'delete'
        $this->authorize('delete', $user);

        $user->delete();

        return response()->noContent();
    }
}
