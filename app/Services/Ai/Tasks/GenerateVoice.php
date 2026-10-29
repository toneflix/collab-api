<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\ArtistVoice;
use App\Services\Ai\Music\MiniMaxMusic25;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\Song;
use App\Services\Artists\ArtistVoices;
use RuntimeException;
use Throwable;

/**
 * Makes a short a cappella sample of an artist's voice with a music model
 * that sings, and keeps it on the artist for later songs.
 */
class GenerateVoice implements AiTask
{
    public function __construct(protected ArtistVoices $voices) {}

    public function credits(array $input): int
    {
        return $this
            ->model($input)
            ->credits((int) ($input['seconds'] ?? ArtistVoices::SAMPLE_SECONDS));
    }

    public function describe(array $input): string
    {
        return 'Voice sample: ' . str((string) ($input['artist'] ?? 'artist'))->limit(80);
    }

    public function engine(array $input): AiEngine
    {
        return $this
            ->model($input)
            ->engine();
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;

        $voice = ArtistVoice::query()
            ->where('artist_id', $input['artist_id'] ?? null)
            ->first() ?? throw new RuntimeException('The artist is gone.');

        try {
            $songs = $this
                ->model($input)
                ->generate($input);
        } catch (Throwable $e) {
            $this->voices->generationFailed($voice, 'The voice sample couldn\'t be made.');

            throw $e;
        }

        try {
            return $this->voices->keepGenerated($voice, $songs[0]);
        } catch (Throwable $e) {
            $this->voices->generationFailed($voice, 'The voice sample couldn\'t be kept.');

            throw $e;
        } finally {
            array_map(fn(Song $song) => $song->forget(), $songs);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function model(array $input): MusicModel
    {
        return MusicModel::find($input['model'] ?? null) ?? new MiniMaxMusic25;
    }
}
