<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContentCollection;
use App\Http\Resources\AdminContentResource;
use App\Http\Resources\ContentOwnerCollection;
use App\Services\Admin\UserDirectory;
use App\Services\Catalog\Content\ContentType;
use App\Services\Catalog\ContentCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tracks, releases, compositions, projects and artists, which admins list,
 * add and edit. Removing content goes through moderation.
 */
class ContentController extends Controller
{
    public function __construct(protected ContentCatalog $catalog)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo(ContentCatalog::PERMISSION),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage content.'
            );

            return $next($request);
        });
    }

    public function index(Request $request, string $type)
    {
        $content = $this->catalog->type($type);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in($content->statuses())],
            'user_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(ContentType::SORTS)],
        ]);

        $items = $content
            ->query($filters)
            ->paginate($request->perPage(20, 50));

        $this->catalog->withOwners($items->getCollection());

        return (new AdminContentCollection($items))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function show(string $type, int $id)
    {
        $item = $this->catalog->find($type, $id);

        return (new AdminContentResource($item))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request, string $type)
    {
        $valid = $request->validate($this->catalog->type($type)->rules());

        $item = $this->catalog->create($type, $valid, $request->user());
        $item->refresh();

        return (new AdminContentResource($item))
            ->additional([
                'message' => 'Added.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function update(Request $request, string $type, int $id)
    {
        $item = $this->catalog->find($type, $id);

        $valid = $request->validate($this->catalog->type($type)->rules($item));

        $item = $this->catalog->update($item, $valid, $request->user());
        $item->refresh();

        return (new AdminContentResource($item))
            ->additional([
                'message' => 'Saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Accounts content can be added for.
     */
    public function owners(Request $request, UserDirectory $directory)
    {
        $valid = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $users = $directory
            ->query(['search' => $valid['search'] ?? null, 'sort' => 'name'])
            ->paginate($request->perPage(10, 20));

        return (new ContentOwnerCollection($users))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
