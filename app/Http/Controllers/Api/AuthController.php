<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\LoginRequest;
use App\Http\Requests\API\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function login(LoginRequest $request): JsonResponse
    {
        $credentialField = filter_var($request->credential, FILTER_VALIDATE_EMAIL) ? 'email' : 'nik';

        $credentials = [
            $credentialField => $request->credential,
            'password' => $request->password,
        ];

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'credential' => ['Email/NIK atau password salah.'],
            ]);
        }

        $user = Auth::user();

        if ($user->status == false) {
            Auth::logout();
            return response()->json([
                'message' => 'Akun Anda tidak aktif. Silakan hubungi administrator.'
            ], 403);
        }

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil!',
            'token' => $token,
            'user' => new UserResource($user)
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logout berhasil.']);
    }

    public function updateFcmToken(Request $request)
{
    $request->validate([
        'fcm_token' => 'required|string',
    ]);

    $user = $request->user();
    $user->update(['fcm_token' => $request->fcm_token]);

    return response()->json(['message' => 'FCM Token updated successfully']);
}


}
