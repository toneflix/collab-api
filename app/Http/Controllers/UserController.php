<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Freq;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::query();

        $query->when($request->has('search'), function (Builder $query) use ($request) {
            if (str($request->search)->contains('@')) {
                $query->where('username', str($request->search)->remove('@')->toString());
            } elseif (str($request->search)->contains(['>', '<']) && str($request->search)->numbers()->toInteger() > 0) {
                $query->where(
                    'reputation',
                    substr($request->search, 0, 1),
                    str($request->search)->numbers()
                );
            } else {
                $query->where(function (Builder $query) use ($request) {
                    $query->whereRaw("CONCAT(firstname, ' ', lastname) LIKE ?", ["%{$request->search}%"])
                        ->orWhere('firstname', 'like', "%$request->search%")
                        ->orWhere('lastname', 'like', "%$request->search%")
                        ->orWhere('country', $request->search)
                        ->orWhere('state', $request->search)
                        ->orWhere('city', $request->search);
                });
            }
        })->when(
            $request->has('skip'),
            fn (Builder $q) => $q->whereNotIn('id', str($request->skip)->remove(' ')->explode(','))
        );

        $filters = str($request->filters)->remove(' ')->explode(',');

        $query
            ->when($request->has('genre'), fn (Builder $q) => $q->genre($request->genre))
            ->when($request->has('project_id'), fn (Builder $q) => $q->forProject($request->project_id))
            ->when($filters->contains('creator'), fn (Builder $q) => $q->creator())
            ->when($filters->contains('collabing'), fn (Builder $q) => $q->collabing($request->user('sanctum')))
            ->when($filters->contains('random'), fn (Builder $q) => $q->inRandomOrder())
            ->when(
                $filters->contains('top'),
                function (Builder $q) use ($filters, $request) {
                    $days = Freq::period($request->period);

                    if ($filters->contains('creator')) {
                        $q->orderByUserProjectVotesLastDays($days);
                    } else {
                        $q->orderByFollowersLastDays($days);
                    }
                }
            )
            ->when(
                $filters->contains('latest'),
                function (Builder $q) use ($filters) {
                    if ($filters->contains('creator')) {
                        $q->withMax('projects as latest_project_date', 'created_at');
                        $q->doesntHave('projects');
                        $q->latest('latest_project_date');
                    } else {
                        $q->latest();
                    }
                }
            );

        $users = $query->paginate($request->input('limit', 15));

        return (new UserCollection($users))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return (new UserResource($user))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
    }
}
