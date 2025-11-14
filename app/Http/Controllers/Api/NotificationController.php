<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification;
use App\Events\NotificationSent;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = Notification::where('receiver_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Store a newly created notification and broadcast it.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'title'       => 'required|string|max:255',
            'body'        => 'required|string',
            'data'        => 'nullable|array',
        ]);

        $notification = Notification::create([
            'sender_id'   => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'title'       => $validated['title'],
            'body'        => $validated['body'],
            'data'        => $validated['data'] ?? null,
            'is_read'     => false,
        ]);

        // Fire broadcast event
        broadcast(new NotificationSent($notification))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully.',
            'data'    => $notification
        ], 201);
    }

    /**
     * Mark the specified notification as read.
     */
    public function markAsRead($id, Request $request)
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('receiver_id', $user->id)
            ->firstOrFail();

        $notification->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data'    => $notification
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        Notification::where('receiver_id', $user->id)
            ->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.'
        ]);
    }
}
