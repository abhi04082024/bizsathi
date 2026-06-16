<?php

namespace App\Http\Controllers\API;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends BaseController
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->with('business')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($notifications);
    }

    public function unread(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->where('is_read', false)
            ->with('business')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse([
            'notifications' => $notifications,
            'unread_count' => $notifications->count(),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $notification->markAsRead();
        return $this->successResponse(null, 'Notification marked as read');
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->notifications()
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->successResponse(null, 'All notifications marked as read');
    }

    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $notification->delete();
        return $this->successResponse(null, 'Notification deleted');
    }

    public function clearAll(Request $request)
    {
        $request->user()->notifications()->delete();
        return $this->successResponse(null, 'All notifications cleared');
    }
}
