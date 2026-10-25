<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlockCollection;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BlockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $list = $user->blocklist()->getQuery()
            ->when($request->has('search'), function (Builder $query) use ($request) {
                $query->whereHasMorph(
                    'blockable',
                    [User::class, Project::class],
                    function (Builder $query, string $type) use ($request) {
                        $query->where(function (Builder $q) use ($request, $type) {
                            $nameColumn = $type === User::class ? 'firstname' : 'title';
                            $nameColumn2 = $type === User::class ? DB::raw("concat(firstname, ' ', lastname)") : 'title';
                            $unameColumn = $type === User::class ? 'username' : 'slug';

                            $q->whereLike($nameColumn, "%{$request->search}%");
                            $q->orWhereLike($nameColumn2, "%{$request->search}%");
                            $q->orWhereLike($unameColumn, "%{$request->search}%");
                        });
                    }
                );
            })
            ->paginate();

        return (new BlockCollection($list))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    /**
     * Block a user or an project
     */
    public function store(Request $request)
    {
        $request->validate([
            'reason' => ['required', 'string', Rule::in(Block::$allowedReasons)],
            'type' => ['required', 'string', 'in:project,user'],
            'id' => [Rule::exists(str($request->type)->plural()->toString(), 'id')],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var User|Project $model */
        $model = app(str($request->type)->ucfirst()->prepend('\\App\\Models\\')->toString())->find($request->id);

        if ($model) {
            $item = $user->blocklist()->firstOrNew([
                'blockable_type' => $model->getMorphClass(),
                'blockable_id' => $model->id,
            ], [
                'reason' => $request->reason,
            ]);

            $item->save();

            return (new BlockResource($item))
                ->additional([
                    'message' => __('This :0 has been blocked and will no longer bother you.', [$request->type]),
                    'status' => 'success',
                    'status_code' => HttpStatus::CREATED,
                ])
                ->response()
                ->setStatusCode(HttpStatus::CREATED->value);
        }

        return Providers::response()->error([
            'message' => __('This :0 is currently not available.', [$request->type]),
            'status_code' => HttpStatus::UNPROCESSABLE_ENTITY,
        ]);
    }

    /**
     * Unblock a blocked user or an organization
     */
    public function destroy(Request $request, string $id)
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->type) {
            $type = str($request->type)->ucfirst()->prepend('App\\Models\\')->toString();
            $item = $user->blocklist()->whereBlockableType($type)->whereBlockableId($id)->firstOrFail();
        } else {
            $item = $user->blocklist()->findOrFail($id);
        }

        $item->delete();

        return (new BlockResource($item))
            ->additional([
                'message' => __(':0 has now been unblocked.', [$item->name]),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
