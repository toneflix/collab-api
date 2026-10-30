<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\GenreCollection;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use App\Services\Catalog\Genres;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Genres admins add, rename, give cover photos and remove.
 */
class GenreController extends Controller
{
    public function __construct(protected Genres $genres)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo(Genres::PERMISSION),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage genres.'
            );

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'cover' => ['nullable', Rule::in(['with', 'without'])],
            'sort' => ['nullable', Rule::in(['title', 'used'])],
        ]);

        $genres = $this->genres
            ->query($filters)
            ->paginate($request->perPage(30));

        return (new GenreCollection($genres))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request)
    {
        $valid = $request->validate($this->rules());

        $genre = $this->genres->save(new Genre, $valid);

        return (new GenreResource($genre))
            ->additional([
                'message' => 'Genre added.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function show(Genre $genre)
    {
        $genre->loadCount(['tracks', 'releases', 'fans']);

        return (new GenreResource($genre))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function update(Request $request, Genre $genre)
    {
        $valid = $request->validate($this->rules($genre));

        $this->genres->save($genre, $valid);

        $genre->loadCount(['tracks', 'releases', 'fans']);

        return (new GenreResource($genre))
            ->additional([
                'message' => 'Genre saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove a genre, moving its music to another one when it is in use.
     */
    public function destroy(Request $request, Genre $genre)
    {
        $valid = $request->validate([
            'move_to' => ['nullable', 'integer', 'exists:genres,id'],
        ]);

        $moveTo = isset($valid['move_to']) ? Genre::query()->find($valid['move_to']) : null;

        $this->genres->delete($genre, $moveTo);

        $result = ['deleted' => true, 'id' => $genre->id, 'movedTo' => $moveTo?->id];

        return (new ActionResource($result))
            ->additional([
                'message' => 'Genre removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(?Genre $genre = null): array
    {
        return [
            'title' => ['required', 'string', 'max:60', Rule::unique('genres', 'title')->ignore($genre?->id)],
            'slug' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('genres', 'slug')->ignore($genre?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'prompt' => ['nullable', 'string', 'max:300'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }
}
