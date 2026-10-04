<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * List notifications belonging to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $notifications = $request->user()
            ->notifications()
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'message' => 'Notifications loaded successfully.',

            'notifications' => collect($notifications->items())
                ->map(
                    fn (DatabaseNotification $notification): array =>
                        $this->formatNotification($notification)
                )
                ->values(),

            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * List unread notifications belonging to the authenticated user.
     */
    public function unread(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $notifications = $request->user()
            ->unreadNotifications()
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'message' => 'Unread notifications loaded successfully.',

            'notifications' => collect($notifications->items())
                ->map(
                    fn (DatabaseNotification $notification): array =>
                        $this->formatNotification($notification)
                )
                ->values(),

            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * Mark one notification belonging to the authenticated user as read.
     */
    public function markAsRead(
        Request $request,
        string $id
    ): JsonResponse {
        /*
         * Ownership protection:
         *
         * Search through the authenticated user's notifications.
         * A user cannot access or modify another user's notification.
         */
        $notification = $request->user()
            ->notifications()
            ->whereKey($id)
            ->first();

        if (! $notification) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $notification->refresh();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Mark all unread notifications belonging to the authenticated user as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $request->user()
            ->unreadNotifications()
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated_count' => $updated,
        ]);
    }

    /**
     * Return only safe notification information required by the frontend.
     */
    private function formatNotification(
        DatabaseNotification $notification
    ): array {
        return [
            'id' => $notification->id,
            'type' => $notification->data['type'] ?? null,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}