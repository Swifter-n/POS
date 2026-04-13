<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
   /**
     * GET: List Notifikasi User
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Ambil notifikasi (unread dulu, lalu read)
        // Laravel otomatis memparsing kolom 'data' JSON
        $notifications = $user->notifications()
            ->latest()
            ->limit(20) // Batasi agar ringan
            ->get()
            ->map(function ($n) {
                return [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? 'Notification',
                    'body' => $n->data['body'] ?? '',
                    'type' => $n->data['type'] ?? 'general', // putaway, picking
                    'reference_id' => $n->data['reference_id'] ?? null,
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $notifications
        ]);
    }

    /**
     * POST: Tandai Sudah Dibaca
     */
    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * POST: Tandai Semua Sudah Dibaca
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['status' => 'success']);
    }
}
