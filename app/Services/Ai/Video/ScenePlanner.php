<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\Track;
use App\Services\Composer\Ai\JsonAnswer;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Lyrics\Lyrics;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Plans a legacy story music video with the Composer writing model: a look
 * for the whole video and a new scene every few seconds, timed to the song's
 * lyrics, which ShotList then cuts on the beat.
 *
 * @phpstan-type Character array{key: string, name: string, description: string, singer: bool}
 * @phpstan-type Frame array{key: string, kind: string, prompt: string, characters: list<string>}
 * @phpstan-type Location array{key: string, name: string, setting: string, frames: list<Frame>}
 * @phpstan-type Plan array{style: array<string, string>, characters: list<Character>, shots: list<array{start: float, end: float, kind: string, prompt: string, characters: list<string>, location?: ?string, frame?: ?string}>, locations: list<Location>}
 */
class ScenePlanner
{
    /** Lyric lines the planner reads at most */
    protected const MAX_LINES = 150;

    /** Tokens each planned shot may take in the answer */
    protected const SHOT_TOKENS = 100;

    protected const SYSTEM = <<<'TXT'
        You direct music videos. You plan one video for a song as JSON only, with no other text.

        Answer with this shape:
        {"style": {"look": "...", "setting": "...", "palette": "...", "wardrobe": "...", "character": "..."},
         "characters": [{"key": "singer", "name": "The singer", "description": "..."}],
         "shots": [{"start": 0, "kind": "broll", "prompt": "...", "characters": ["singer"]}]}

        Rules:
        - "start" is the second the shot begins. Shots run until the next one starts and the last runs to the end of the song.
        - Plan a shot every 3 to 6 seconds.
        - "kind" is "performance" when the artist sings to the camera, else "broll". Use performance only where lyric lines are sung, for about 40 percent of the sung time. Instrumental parts are always broll.
        - "prompt" describes one still frame for an image model: subject, action, setting, camera angle and light, in one or two sentences. Never put text, logos, brand names or real people's names in it.
        - Performance prompts show the singer from the chest up, facing the camera, mouth visible, in the video's setting.
        - "characters" lists everyone who appears in more than one shot, at most 4. The first is always key "singer", the artist. "key" is a short lowercase handle; "description" fixes how they look for a character sheet: age, build, face, hair, skin tone and the exact outfit they wear in every shot.
        - Each shot's "characters" lists the keys of the characters seen in it, or [] when there are none. Performance shots always include "singer". Refer to characters in prompts by their name, not by a new description.
        - Keep one look for the whole video: the same singer (described in "character"), wardrobe, palette and world, so the shots cut together.
        - Follow the creator's brief when there is one.
        TXT;

    public function __construct(
        protected LyricModel $model,
        protected Lyrics $lyrics,
    ) {}

    public function available(): bool
    {
        return $this->model->available();
    }

    /**
     * @return Plan
     */
    public function plan(MusicVideo $video, float $maxShotSeconds): array
    {
        $track = $video->track;
        $cast = $video
            ->characters()
            ->where('provided', true)
            ->get();
        $answer = $this->model->ask(static::SYSTEM, $this->prompt($video, $track, $cast), $this->budget($video));
        $data = JsonAnswer::decode($answer);
        $locations = $this->locations($data);

        $shots = (new ShotList($video->duration, $track->bpm ? (float) $track->bpm : null, $maxShotSeconds))
            ->build($this->plannedShots($data, $locations));

        if (! $shots) {
            throw new RuntimeException('The AI planner returned no shots.');
        }

        $shots = $this->arrange($shots, $locations);

        $style = collect((array) ($data['style'] ?? []))
            ->only(['look', 'setting', 'palette', 'wardrobe', 'character'])
            ->map(fn($value) => str((string) $value)
                ->squish()
                ->limit(300)
                ->toString())
            ->filter()
            ->all();

        $planned = $this->characters((array) ($data['characters'] ?? []), $style);
        $characters = $cast->isNotEmpty()
            ? $cast
                ->map(fn(MusicVideoCharacter $character) => $character->only(['key', 'name', 'description', 'singer']))
                ->when(! $cast->contains('singer', true), fn(Collection $given) => $given->prepend($planned[0]))
                ->values()
                ->all()
            : $planned;
        $keys = array_column($characters, 'key');

        foreach ($shots as $i => $shot) {
            $seen = array_values(array_intersect($shot['characters'], $keys));

            if ($shot['kind'] === MusicVideoShot::PERFORMANCE && ! in_array(MusicVideoCharacter::SINGER, $seen, true)) {
                $seen[] = MusicVideoCharacter::SINGER;
            }

            $shots[$i]['characters'] = $seen;
        }

        return ['style' => $style, 'characters' => $characters, 'shots' => $shots, 'locations' => $locations];
    }

    /**
     * The locations the video is shot in; story scenes have none.
     *
     * @param  array<string, mixed>  $data
     * @return list<Location>
     */
    protected function locations(array $data): array
    {
        return [];
    }

    /**
     * The shots of the answer, ready for the cut list.
     *
     * @param  array<string, mixed>  $data
     * @param  list<Location>  $locations
     * @return list<array<string, mixed>>
     */
    protected function plannedShots(array $data, array $locations): array
    {
        return (array) ($data['shots'] ?? []);
    }

    /**
     * The cut shots as they are stored.
     *
     * @param  list<array<string, mixed>>  $shots
     * @param  list<Location>  $locations
     * @return list<array<string, mixed>>
     */
    protected function arrange(array $shots, array $locations): array
    {
        return $shots;
    }

    /**
     * Most characters the planner may cast.
     */
    protected function castSize(): int
    {
        return MusicVideoCharacter::MAX;
    }

    /**
     * The planned characters, with the singer always first.
     *
     * @param  array<int, mixed>  $planned
     * @param  array<string, string>  $style
     * @return list<Character>
     */
    protected function characters(array $planned, array $style): array
    {
        $clean = fn(mixed $value, int $limit) => str((string) $value)
            ->squish()
            ->limit($limit)
            ->toString();

        $characters = collect($planned)
            ->filter(fn($character) => is_array($character) && filled($character['key'] ?? null) && filled($character['description'] ?? null))
            ->map(fn(array $character) => [
                'key' => str((string) $character['key'])
                    ->lower()
                    ->slug('_')
                    ->limit(40, '')
                    ->toString(),
                'name' => $clean($character['name'] ?? $character['key'], 80),
                'description' => $clean($character['description'], 600),
            ])
            ->filter(fn(array $character) => $character['key'] !== '')
            ->unique('key')
            ->keyBy('key');

        if (! $characters->has(MusicVideoCharacter::SINGER)) {
            $characters->prepend([
                'key' => MusicVideoCharacter::SINGER,
                'name' => 'The singer',
                'description' => $style['character'] ?? 'The artist',
            ], MusicVideoCharacter::SINGER);
        }

        $singer = $characters->pull(MusicVideoCharacter::SINGER);

        return collect([$singer])
            ->merge($characters->values())
            ->take($this->castSize())
            ->map(fn(array $character) => [...$character, 'singer' => $character['key'] === MusicVideoCharacter::SINGER])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MusicVideoCharacter>  $cast  Characters the creator gave, the only ones to use
     */
    protected function prompt(MusicVideo $video, Track $track, Collection $cast): string
    {
        $lines = collect($this->lyrics->for($track)?->lines ?? [])
            ->filter(fn(array $line) => isset($line['start']) && filled($line['text'] ?? null))
            ->take(self::MAX_LINES)
            ->map(fn(array $line) => sprintf('%.1f-%.1f %s', $line['start'], $line['end'] ?? $line['start'], $line['text']))
            ->implode("\n");

        $facts = collect([
            'Title' => $track->title,
            'Genre' => $track->genre?->name,
            'Moods' => implode(', ', (array) ($track->moods ?? [])),
            'Tempo' => $track->bpm ? "{$track->bpm} BPM" : null,
            'Length' => sprintf('%.1f seconds', $video->duration),
            'Frame' => $video->ratio,
            'Brief' => $video->brief,
        ])
            ->filter(fn($value) => filled($value))
            ->map(fn($value, string $label) => "{$label}: {$value}")
            ->implode("\n");

        if ($cast->isNotEmpty()) {
            $singer = $cast->contains('singer', true) ? '' : ' Add the singer, key "singer", and no one else.';
            $facts .= "\n\nThe creator gave the cast. Use only these characters, with these keys, and copy them into \"characters\" as given.{$singer}\n"
                . $cast
                    ->map(fn(MusicVideoCharacter $character) => "- {$character->key}: {$character->name}"
                        . ($character->singer ? ' (the singer)' : '')
                        . ($character->description ? " ({$character->description})" : ''))
                    ->implode("\n");
        }

        return $facts . "\n\nTimed lyrics:\n" . ($lines ?: '(none, treat the song as instrumental)');
    }

    protected function budget(MusicVideo $video): int
    {
        $shots = (int) ceil($video->duration / 4);

        return min(12000, 1400 + $shots * self::SHOT_TOKENS);
    }
}
