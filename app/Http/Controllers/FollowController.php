<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\FollowCollection;
use App\Http\Resources\FollowResource;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Overtrue\LaravelFollow\Followable;

class FollowController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $pid, ?string $follow_id = null)
    {
        [$followable] = $this->resolver($request, $pid, $follow_id);

        if ($request->type === 'followings') {
            /** @var Builder $query */
            $query = $followable->followings();
        } else {
            /** @var Builder $query */
            $query = $followable->followers();
        }

        $follows = $query->paginate($request->perPage(45));

        return (new FollowCollection($follows))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $pid)
    {
        $user = $request->user('sanctum');

        [$followable, $follow, $name] = $this->resolver($request, $pid);

        $user->toggleFollow($followable);

        $action = $followable->isFollowedBy($user) ? 'followed' : 'unfollowed';

        return (new FollowResource($followable))->additional([
            'message' => __("You have :0 this $name", [$action]),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }

    /**
     * Resolve the interaction interface
     *
     * @return array{
     *  0:User|Artist,
     *  1:Followable|null,
     *  2:string
     * }
     */
    public function resolver(Request $request, string $followable_id, ?string $follow_id = null)
    {
        // Guess class name
        $name = str($request->route()->getAction('prefix'))
            ->after('{')
            ->before('}');

        $className = $name
            ->studly()
            ->prepend('\\App\\Models\\')
            ->toString();

        /** @var User|Artist $followable */
        $followable = (new $className)->resolveRouteBinding($followable_id);

        $follow = null;

        if ($followable) {
            if ($follow_id) {
                /** @var Followable $follow */
                $follow = $followable->followers()->findOrFail($followable_id);
            }
        }

        return [$followable, $follow, $name->toString()];
    }
}
