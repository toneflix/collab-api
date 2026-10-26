<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ArtworkKind;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobCollection;
use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Tasks\GenerateArtwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI artwork for projects, releases, tracks, songs and artist pages. A job
 * draws a few images; the form it was drawn for saves the one picked.
 */
class AiArtworkController extends Controller
{
    /**
     * The person's latest artwork jobs, of one kind when asked.
     */
    public function index(Request $request)
    {
        $valid = $request->validate([
            'kind' => ['nullable', Rule::enum(ArtworkKind::class)],
        ]);

        $kind = $valid['kind'] ?? null;

        $jobs = AiJob::query()
            ->where('user_id', $request->user()->id)
            ->where('kind', 'artwork')
            ->when($kind, fn(Builder $q) => $q->where('input->artwork_kind', $kind))
            ->latest('id')
            ->paginate($request->perPage(10, 30));

        return (new AiJobCollection($jobs))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Start drawing artwork from a prompt. The title, artist and genre of
     * what it's for help the model.
     */
    public function store(Request $request, AiStudio $studio)
    {
        $valid = $request->validate([
            'kind' => ['required', Rule::enum(ArtworkKind::class)],
            'prompt' => ['required', 'string', 'min:3', 'max:1000'],
            'title' => ['nullable', 'string', 'max:200'],
            'artist' => ['nullable', 'string', 'max:100'],
            'genre' => ['nullable', 'string', 'max:100'],
        ]);

        $model = GenerateArtwork::model();

        $input = array_filter([
            'artwork_kind' => $valid['kind'],
            'prompt' => trim($valid['prompt']),
            'title' => isset($valid['title']) ? trim($valid['title']) : null,
            'artist' => isset($valid['artist']) ? trim($valid['artist']) : null,
            'genre' => isset($valid['genre']) ? trim($valid['genre']) : null,
            'count' => AiSettings::artworkImages(),
            'artwork_engine' => $model->engine()->value,
            'artwork_model' => $model->model(),
        ], fn($value) => $value !== null && $value !== '');

        $job = $studio->start($request->user(), null, 'artwork', $input);

        return (new AiJobResource($job))
            ->additional([
                'message' => 'Drawing your artwork.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Show an image an artwork job drew, from a signed link.
     */
    public function image(ArtworkImages $images, AiJob $job, int $image)
    {
        return $images->stream($job, $image);
    }
}
