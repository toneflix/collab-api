<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LyricSuggestionResource;
use App\Models\Song;
use App\Services\Composer\Ai\LyricWriter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI lyric suggestions for a song: the next line, a block, a verse or other takes on a line.
 */
class SongSuggestionController extends Controller
{
    public function store(Request $request, LyricWriter $writer, Song $song)
    {
        $this->authorize('update', $song);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(LyricWriter::KINDS))],
            'line' => ['required', 'integer', 'min:1', 'max:5000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'instruction' => ['nullable', 'string', 'max:200'],
        ]);

        $suggestion = $writer->suggest(
            $song,
            $this->user,
            $data['kind'],
            $data['body'] ?? (string) $song->body,
            $data['line'],
            $data['instruction'] ?? null,
        );

        return (new LyricSuggestionResource($suggestion))
            ->additional([
                'message' => 'Here are some ideas.',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }
}
