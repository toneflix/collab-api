<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\NotificationRequestCollection;
use App\Http\Resources\NotificationRequestResource;
use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ApprovableNotifications\Models\Notification;

class NotificationRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $query = $user->approvableNotifications()->getQuery();
        $query->when(
            in_array($request->status, ['pending', 'unread', 'approved', 'rejected']),
            fn() => $query->{$request->status}()
        );

        $notifications = $query
            ->paginate($request->perPage(30))
            ->onEachSide(0)
            ->withQueryString();

        return (new NotificationRequestCollection($notifications))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
                'pending_count' => $user->approvableNotifications()->pending()->count(),
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Show the specified resource.
     */
    public function show(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Notification $notification */
        $notification = $user->approvableNotifications()->findOrFail($id);
        $notification->markAsRead();

        return (new NotificationRequestResource($notification))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
                'pending_count' => $user->approvableNotifications()->pending()->count(),
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Notification $notification */
        $notification = $user->approvableNotifications()->findOrFail($id);
        $notification->markAsRead();

        $this->validate($request, [
            'status' => ['required', 'numeric', 'in:0,2'],
        ]);

        if ($request->integer('status') === 0) {
            $notification->markAsRejected();
        } else {
            $notification->markAsApproved();
        }

        return (new NotificationRequestResource($notification))
            ->additional([
                'message' => __('Notification :0 successfully', [$notification->approved ? 'approved' : 'rejected']),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
                'pending_count' => $user->approvableNotifications()->pending()->count(),
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Notification $notification */
        $notification = $user->approvableNotifications()->findOrFail($id);
        $notification->delete();

        $pending = $user
            ->approvableNotifications()
            ->pending()
            ->count();

        return (new NotificationRequestResource($notification))
            ->additional([
                'message' => 'Notifcation deleted successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
                'pending_count' => $pending,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
