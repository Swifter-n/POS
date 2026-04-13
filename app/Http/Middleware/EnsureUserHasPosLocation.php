<?php

namespace App\Http\Middleware;

use App\Models\Outlet;
use Closure;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPosLocation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();


        // Cek 1: Apakah user terautentikasi?
        // (Middleware auth:sanctum sudah menangani ini,
        // tapi $request->user() akan null jika gagal, jadi cek ini aman)
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Cek 2: Apakah user ini adalah user Outlet (POS)?
        // Ini adalah logika yang kita pindahkan dari controller
        if ($user->locationable_type !== Outlet::class || is_null($user->locationable_id)) {
            return response()->json(['message' => 'Hanya user Outlet (POS) yang bisa mengakses.'], 403);
        }

        // Jika lolos, lanjutkan ke controller
        return $next($request);
    }
}
