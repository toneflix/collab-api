<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TopicCollection;
use App\Http\Resources\TopicResource;
use App\Models\Topic;
use App\Services\Lyrics\LyricsCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Music topics people pick as interests. Anyone signed in can add a missing
 * one, and it is then offered to everyone.
 */
class TopicController extends Controller
{
    /**
     * How many topics one person can add.
     */
    public const MAX_ADDED = 20;

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $topics = Topic::query()
            ->popular()
            ->when($search !== '', fn(Builder $q) => $q->search($search))
            ->paginate($request->perPage(30));

        return (new TopicCollection($topics))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Add a topic, or return the one with the same name.
     */
    public function store(Request $request, LyricsCheck $check)
    {
        $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[\pL\pN][\pL\pN &\'\-\.]*$/u'],
        ], [
            'title.regex' => 'Use letters, numbers and spaces.',
        ]);

        $title = Str::ucfirst(Str::squish($request->string('title')));
        $slug = Str::slug($title);

        $topic = Topic::query()
            ->where('slug', $slug)
            ->first();

        if (! $topic) {
            $words = $check->scan($title);

            if ($slug === '' || $words['explicit'] || $words['review']) {
                throw ValidationException::withMessages(['title' => 'Pick another name for this topic.']);
            }

            $added = Topic::query()
                ->where('user_id', $request->user()->id)
                ->count();

            abort_if($added >= self::MAX_ADDED, HttpStatus::UNPROCESSABLE_ENTITY->value, 'You have added as many topics as you can.');

            $topic = Topic::query()->create([
                'slug' => $slug,
                'title' => $title,
                'user_id' => $request->user()->id,
            ]);
        }

        return (new TopicResource($topic))
            ->additional([
                'message' => $topic->wasRecentlyCreated ? 'Topic added.' : 'That topic is already here.',
                'status' => 'success',
                'status_code' => $topic->wasRecentlyCreated ? HttpStatus::CREATED : HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode($topic->wasRecentlyCreated ? HttpStatus::CREATED->value : HttpStatus::OK->value);
    }
}
