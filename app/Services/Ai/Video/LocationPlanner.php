<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoShot;

/**
 * Plans a music video shot in two or three locations, the way most music
 * videos are made. Each location has a few frames, each drawn once, and the
 * shots start from those frames with their own camera move and action.
 *
 * @phpstan-import-type Location from ScenePlanner
 */
class LocationPlanner extends ScenePlanner
{
    /** Most locations one video is shot in */
    public const MAX_LOCATIONS = 3;

    /** Most frames drawn for one location */
    public const MAX_FRAMES = 3;

    /** Most characters, the singer included */
    protected const CAST = 2;

    protected const SYSTEM = <<<'TXT'
        You direct music videos. You plan one video for a song as JSON only, with no other text. Like most music videos, it is shot in a few locations.

        Answer with this shape:
        {"style": {"look": "...", "palette": "...", "wardrobe": "...", "character": "..."},
         "characters": [{"key": "singer", "name": "The singer", "description": "..."}],
         "locations": [{"key": "rooftop", "name": "...", "setting": "...",
           "frames": [{"key": "rooftop_sing", "kind": "performance", "prompt": "...", "characters": ["singer"]}]}],
         "shots": [{"start": 0, "frame": "rooftop_wide", "action": "..."}]}

        Rules:
        - Use 2 locations for songs under two minutes and 3 for longer songs. "setting" describes the place, time of day and light in one sentence.
        - Each location has one "performance" frame and one or two "broll" frames. A frame's "prompt" describes one still for an image model: subject, setting, camera angle and light, in one or two sentences. Never put text, logos, brand names or real people's names in it.
        - Performance frames show the singer from the chest up, facing the camera, mouth visible, in that location. B-roll frames show the location wide, a detail of it, or the singer or the other character there.
        - "characters" lists the singer, key "singer", and at most one other person. "description" fixes how they look for a character sheet: age, build, face, hair, skin tone and the exact outfit they wear in every shot.
        - Each frame's "characters" lists the keys of the characters seen in it, or [] when there are none. Refer to characters in prompts by their name, not by a new description.
        - "start" is the second a shot begins. Shots run until the next one starts and the last runs to the end of the song. Plan a shot every 4 to 8 seconds.
        - "frame" is the key of the frame the shot starts from. "action" says in one sentence how the camera moves and what happens in the shot, different for every shot.
        - Use performance frames only where lyric lines are sung, for about half of the sung time. Instrumental parts are always b-roll.
        - Stay in one location for a part of the song, like a verse or a chorus, before moving to the next. Never start two shots in a row from the same frame.
        - Keep one look for the whole video: the same singer (described in "character"), wardrobe and palette, so the locations cut together.
        - Follow the creator's brief when there is one.
        TXT;

    /**
     * @return list<Location>
     */
    protected function locations(array $data): array
    {
        $keys = [];
        $locations = [];

        foreach (array_slice(array_filter((array) ($data['locations'] ?? []), 'is_array'), 0, self::MAX_LOCATIONS) as $planned) {
            $key = $this->key($planned['key'] ?? $planned['name'] ?? 'location', $keys, 40);
            $frames = [];

            foreach (array_filter((array) ($planned['frames'] ?? []), 'is_array') as $frame) {
                if (count($frames) === self::MAX_FRAMES || blank($frame['prompt'] ?? null)) {
                    continue;
                }

                $frames[] = [
                    'key' => $this->key($frame['key'] ?? "{$key}_frame", $keys, 60),
                    'kind' => ($frame['kind'] ?? null) === MusicVideoShot::PERFORMANCE ? MusicVideoShot::PERFORMANCE : MusicVideoShot::BROLL,
                    'prompt' => $this->clean($frame['prompt'], 600),
                    'characters' => collect((array) ($frame['characters'] ?? []))
                        ->filter(fn($character) => is_string($character) && $character !== '')
                        ->unique()
                        ->values()
                        ->all(),
                ];
            }

            if ($frames) {
                $locations[] = [
                    'key' => $key,
                    'name' => $this->clean($planned['name'] ?? $key, 80),
                    'setting' => $this->clean($planned['setting'] ?? '', 300),
                    'frames' => $frames,
                ];
            }
        }

        return $locations;
    }

    protected function plannedShots(array $data, array $locations): array
    {
        $frames = $this->frames($locations);

        return collect((array) ($data['shots'] ?? []))
            ->filter(fn($shot) => is_array($shot) && isset($frames[$shot['frame'] ?? null]))
            ->map(fn(array $shot) => [
                'start' => $shot['start'] ?? 0,
                'kind' => $frames[$shot['frame']]['kind'],
                'prompt' => $frames[$shot['frame']]['prompt'],
                'characters' => $frames[$shot['frame']]['characters'],
                'location' => $frames[$shot['frame']]['location'],
                'frame' => $shot['frame'],
                'action' => $this->clean($shot['action'] ?? '', 300),
            ])
            ->values()
            ->all();
    }

    /**
     * Start a shot from another frame of its location, the one used least
     * lately, when it would repeat the frame before it. Then write each
     * shot's prompt from its frame and its action.
     */
    protected function arrange(array $shots, array $locations): array
    {
        $frames = $this->frames($locations);
        $used = [];
        $previous = null;

        foreach ($shots as $i => $shot) {
            if ($shot['frame'] === $previous) {
                $other = collect($frames)
                    ->filter(fn(array $frame, string $key) => $key !== $previous && $frame['location'] === $shot['location'])
                    ->reject(fn(array $frame) => $frame['kind'] === MusicVideoShot::PERFORMANCE && $shot['kind'] !== MusicVideoShot::PERFORMANCE)
                    ->sortBy([
                        fn(array $a, array $b) => ($a['kind'] !== $shot['kind']) <=> ($b['kind'] !== $shot['kind']),
                        fn(array $a, array $b) => ($used[$a['key']] ?? -1) <=> ($used[$b['key']] ?? -1),
                    ])
                    ->keys()
                    ->first();

                if ($other) {
                    $shot = [
                        ...$shot,
                        'frame' => $other,
                        'kind' => $frames[$other]['kind'],
                        'characters' => $frames[$other]['characters'],
                    ];
                }
            }

            $previous = $shot['frame'];
            $used[$previous] = $i;
            $prompt = $frames[$previous]['prompt'];
            $action = $shot['action'] ?? '';

            unset($shot['action']);

            $shots[$i] = [...$shot, 'prompt' => trim("{$prompt} {$action}")];
        }

        return $shots;
    }

    protected function castSize(): int
    {
        return self::CAST;
    }

    protected function budget(MusicVideo $video): int
    {
        $shots = (int) ceil($video->duration / 5);

        return min(9000, 2000 + $shots * 70);
    }

    /**
     * Every frame by its key, with the location it is in.
     *
     * @param  list<Location>  $locations
     * @return array<string, array{kind: string, prompt: string, characters: list<string>, location: string}>
     */
    protected function frames(array $locations): array
    {
        return collect($locations)
            ->flatMap(fn(array $location) => collect($location['frames'])
                ->mapWithKeys(fn(array $frame) => [$frame['key'] => [...$frame, 'location' => $location['key']]]))
            ->all();
    }

    /**
     * A short unique lowercase handle.
     *
     * @param  list<string>  $taken
     */
    protected function key(mixed $value, array &$taken, int $limit): string
    {
        $base = str((string) $value)
            ->lower()
            ->slug('_')
            ->limit($limit - 3, '')
            ->toString() ?: 'frame';
        $key = $base;

        for ($i = 2; in_array($key, $taken, true); $i++) {
            $key = "{$base}_{$i}";
        }

        $taken[] = $key;

        return $key;
    }

    protected function clean(mixed $value, int $limit): string
    {
        return str((string) $value)
            ->squish()
            ->limit($limit)
            ->toString();
    }
}
