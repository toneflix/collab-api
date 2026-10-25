<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\NotificationCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Unread important notifications pinned above the list */
    public const IMPORTANT_LIMIT = 20;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $query = $user->notifications()->getQuery();

        $query
            ->where(function (Builder $q) {
                $q->whereNull('data->important');
                $q->orWhere('data->important', false);
                $q->orWhereNotNull('read_at');
            })
            ->latest();

        $stats = [
            'read' => $user->notifications()->read()->count(),
            'unread' => $user->notifications()->unread()->count(),
            'important' => $user->notifications()->unread()->whereJsonContains('data->important', true)->count(),
        ];

        $additional = [
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ];

        if ($request->boolean('statsOnly')) {
            $additional['stats'] = $stats;
        } else {
            $notifications = $query->paginate($request->perPage(30));
            $important = $user->notifications()->unread()->whereJsonContains('data->important', true)->latest()->limit(self::IMPORTANT_LIMIT)->get();

            if ($request->boolean('withStats')) {
                $additional['stats'] = $stats;
            }
            $additional['important'] = new NotificationCollection($important ?? []);
        }

        return (new NotificationCollection($notifications ?? []))->additional($additional);
    }

    /**
     * Mark one notification as read.
     */
    public function update(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return $this->stats($request, 'Notification marked as read.');
    }

    /**
     * Mark every notification as read.
     */
    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->stats($request, 'All notifications marked as read.');
    }

    protected function stats(Request $request, string $message)
    {
        $unread = $request
            ->user()
            ->unreadNotifications()
            ->count();

        $result = [];

        return (new ActionResource($result))
            ->additional([
                'stats' => ['unread' => $unread],
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return (new NotificationCollection([]))
            ->additional([
                'message' => __('Notification Deleted'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
