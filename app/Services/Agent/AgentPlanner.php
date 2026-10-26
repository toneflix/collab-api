<?php

namespace App\Services\Agent;

use App\Enums\ArtworkKind;
use App\Models\AgentRun;
use App\Models\Genre;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Tasks\GenerateArtwork;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns a run's prompt into a plan of artists, albums, singles and songs,
 * then into the steps that build them.
 */
class AgentPlanner
{
    /** Most tracks one run can make */
    public const MAX_TRACKS = 30;

    /** Most artists one run can make */
    public const MAX_ARTISTS = 10;

    /** Track length when the run doesn't set one, in seconds */
    public const DEFAULT_SECONDS = 120;

    public function __construct(protected AgentWriter $writer) {}

    /**
     * Ask for a plan and tidy it into the shape the steps are built from.
     *
     * @return array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, songs: list<array<string, mixed>>}
     */
    public function plan(AgentRun $run): array
    {
        $genres = Genre::query()
            ->orderBy('title')
            ->limit(200)
            ->pluck('title', 'id');
        $existing = $run->owner
            ->artists()
            ->latest('id')
            ->limit(50)
            ->pluck('name');

        $answer = $this->writer->json(
            $run,
            'plan',
            $this->system(),
            $this->prompt($run, $genres, $existing),
            4000,
        );

        return $this->normalize($answer, $genres, $this->maxTracks($run));
    }

    /**
     * The steps that build a plan, in the order they are listed.
     *
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
     * @return list<array{key: string, kind: string, depends_on: list<string>, input: array<string, mixed>}>
     */
    public function steps(AgentRun $run, array $plan): array
    {
        $steps = [];
        $seconds = $this->seconds($run);
        $artwork = $this->artwork($run);
        $genres = Genre::query()
            ->whereKey(array_column($plan['albums'], 'genre_id'))
            ->pluck('title', 'id');
        $names = array_column($plan['artists'], 'name', 'key');

        foreach ($plan['artists'] as $artist) {
            $steps[] = $this->step("artist:{$artist['key']}", 'artist', [], [
                'name' => $artist['name'],
                'about' => $artist['about'],
            ]);

            if ($artwork) {
                $steps[] = $this->step("portrait:{$artist['key']}", 'artwork', ["artist:{$artist['key']}"], [
                    'title' => $artist['name'],
                    'artwork_kind' => ArtworkKind::ARTIST->value,
                    'prompt' => ($artist['look'] ?? '') ?: $artist['about'] ?: $artist['name'],
                    'artist' => $artist['name'],
                    'target' => "artist:{$artist['key']}",
                ]);
            }
        }

        foreach ($plan['albums'] as $album) {
            $project = "project:{$album['key']}";
            $artist = "artist:{$album['artist']}";
            $mixes = [];
            $art = $artwork ? "cover:{$album['key']}" : null;

            $steps[] = $this->step($project, 'project', [], [
                'title' => $album['title'],
                'description' => $album['description'],
            ]);

            if ($art) {
                $steps[] = $this->step($art, 'artwork', [$project], [
                    'title' => $album['title'],
                    'artwork_kind' => count($album['tracks']) === 1 ? ArtworkKind::TRACK->value : ArtworkKind::RELEASE->value,
                    'prompt' => ($album['cover'] ?? '') ?: $album['description'] ?: $album['title'],
                    'artist' => $names[$album['artist']] ?? null,
                    'genre' => $genres[$album['genre_id']] ?? null,
                    'target' => $project,
                ]);
            }

            foreach ($album['tracks'] as $track) {
                $kind = $track['instrumental'] ? 'track' : 'song';
                $source = "{$kind}:{$track['key']}";

                $steps[] = $this->step($source, $kind, [$artist, $project], [
                    'title' => $track['title'],
                    'theme' => $track['theme'],
                    'style' => $track['style'],
                    'mood' => $track['mood'],
                    'artist' => $artist,
                    'project' => $project,
                    'genre_id' => $album['genre_id'],
                ]);
                $steps[] = $this->step("music:{$track['key']}", 'music', [$source], [
                    'title' => $track['title'],
                    'source' => $source,
                    'style' => $track['style'],
                    'instrumental' => $track['instrumental'],
                    'seconds' => $seconds,
                ]);
                $steps[] = $mixes[] = $this->step("mix:{$track['key']}", 'mix', ["music:{$track['key']}"], [
                    'title' => $track['title'],
                    'source' => $source,
                ]);
            }

            $steps[] = $this->step("release:{$album['key']}", 'release', [...array_column($mixes, 'key'), ...array_filter([$art])], [
                'title' => $album['title'],
                'description' => $album['description'],
                'genre_id' => $album['genre_id'],
                'artist' => $artist,
                'project' => $project,
                'tracks' => array_map(fn(array $mix) => $mix['input']['source'], $mixes),
                'artwork' => $art,
            ]);
        }

        foreach ($plan['songs'] as $song) {
            $steps[] = $this->step("song:{$song['key']}", 'song', [], [
                'title' => $song['title'],
                'theme' => $song['theme'],
                'style' => $song['style'],
                'mood' => $song['mood'],
            ]);
        }

        return $steps;
    }

    /**
     * Credits the plan's steps will use, on top of the plan itself.
     *
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
     */
    public function estimate(AgentRun $run, array $plan): int
    {
        $tracks = collect($plan['albums'])->flatMap(fn(array $album) => $album['tracks']);
        $vocals = $tracks
            ->reject(fn(array $track) => $track['instrumental'])
            ->count();
        $seconds = $this->seconds($run);

        $vocalCredits = AiSettings::composerMusicModel()
            ->credits($seconds);
        $instrumentalCredits = AiSettings::musicModel()
            ->credits($seconds);

        $pictures = $this->artwork($run) ? count($plan['artists']) + count($plan['albums']) : 0;

        return ($vocals + count($plan['songs'])) * AgentWriter::credits()
            + $vocals * $vocalCredits
            + ($tracks->count() - $vocals) * $instrumentalCredits
            + $pictures * AiSettings::artworkCredits();
    }

    /**
     * What the agent's form needs: whether it can run now and its limits.
     *
     * @return array{available: bool, maxTracks: int, defaultSeconds: int, maxSeconds: int, vocals: bool, instrumentals: bool, artwork: bool}
     */
    public function clientConfig(): array
    {
        return [
            'available' => AiSettings::enabled() && $this->writer->available(),
            'maxTracks' => self::MAX_TRACKS,
            'defaultSeconds' => self::DEFAULT_SECONDS,
            'maxSeconds' => AiSettings::musicMaxSeconds(),
            'vocals' => self::vocals(),
            'instrumentals' => self::instrumentals(),
            'artwork' => self::drawsArtwork(),
        ];
    }

    /**
     * Whether the run draws album covers and artist pictures.
     */
    public function artwork(AgentRun $run): bool
    {
        return (bool) $run->option('artwork', false) && self::drawsArtwork();
    }

    public function maxTracks(AgentRun $run): int
    {
        return min(self::MAX_TRACKS, max(1, (int) $run->option('max_tracks', 12)));
    }

    public function seconds(AgentRun $run): int
    {
        return min(AiSettings::musicMaxSeconds(), max(10, (int) $run->option('seconds', self::DEFAULT_SECONDS)));
    }

    /**
     * Whether the Composer music model can sing tracks now.
     */
    public static function vocals(): bool
    {
        return AiSettings::composerMusicModel()
            ->engine()
            ->available();
    }

    /**
     * Whether the studio's music model can make tracks without vocals.
     */
    public static function instrumentals(): bool
    {
        $model = AiSettings::musicModel();

        return $model->instrumentalOnly() || $model->instrumentalOption() || ! $model->takesLyrics();
    }

    /**
     * Whether artwork can be drawn now.
     */
    public static function drawsArtwork(): bool
    {
        return GenerateArtwork::model()
            ->engine()
            ->available();
    }

    /**
     * @param  list<string>  $dependsOn
     * @param  array<string, mixed>  $input
     * @return array{key: string, kind: string, depends_on: list<string>, input: array<string, mixed>}
     */
    protected function step(string $key, string $kind, array $dependsOn, array $input): array
    {
        return ['key' => $key, 'kind' => $kind, 'depends_on' => $dependsOn, 'input' => $input];
    }

    protected function system(): string
    {
        return implode("\n", [
            'You plan original music for a streaming platform\'s catalogue, from an admin\'s instructions.',
            'Invent fictional artists only. Never use the name of a real artist, band, album or song, and never imitate a real artist by name.',
            'An album is a list of tracks released together; a single is an album with one track.',
            '"songs" are lyric sheets only, with no music, for when the instructions ask for written songs alone.',
            'Each track has a "style": one or two sentences for a music generator describing genre, tempo, instruments, vocals and mood. Never name real artists in it.',
            'Each track with vocals has a "theme": what its lyrics are about, in one sentence.',
            'Each album has a "cover": one sentence describing its cover image, and each artist a "look": one sentence describing their profile picture. Describe scenes, colours and style, never text or real people.',
            'Keep every name and title short and plain, with no quotes or emoji.',
            'Answer with JSON only, no other text, in this shape:',
            '{"artists": [{"key": "a1", "name": "", "about": "two or three sentence bio", "look": ""}],'
                . ' "albums": [{"key": "al1", "title": "", "artist": "a1", "genre": "", "description": "", "cover": "", "tracks": [{"title": "", "style": "", "theme": "", "mood": "", "instrumental": false}]}],'
                . ' "songs": [{"title": "", "theme": "", "style": "", "mood": ""}]}',
        ]);
    }

    /**
     * @param  Collection<int|string, string>  $genres
     * @param  Collection<int, string>  $existing
     */
    protected function prompt(AgentRun $run, Collection $genres, Collection $existing): string
    {
        $vocals = self::vocals();
        $instrumentals = self::instrumentals();

        return implode("\n", array_filter([
            'Instructions:',
            '"""',
            trim((string) $run->prompt),
            '"""',
            '',
            'Make at most ' . $this->maxTracks($run) . ' tracks in total across all albums, and at most ' . self::MAX_ARTISTS . ' artists.',
            match (true) {
                $vocals && $instrumentals => 'Tracks can have vocals or be instrumental.',
                $vocals => 'Every track must have vocals.',
                default => 'Every track must be instrumental.',
            },
            'Pick each album\'s genre from: ' . $genres->join(', ') . '.',
            $existing->isNotEmpty()
                ? 'The account already has these artists; reuse one by its exact name when the instructions point to it: ' . $existing->join(', ') . '.'
                : null,
        ], fn($line) => $line !== null));
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  Collection<int|string, string>  $genres
     * @return array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, songs: list<array<string, mixed>>}
     */
    protected function normalize(array $answer, Collection $genres, int $maxTracks): array
    {
        $byName = $genres->mapWithKeys(fn(string $name, int|string $id) => [mb_strtolower($name) => $id]);
        $fallbackGenre = $genres
            ->keys()
            ->first();
        $vocals = self::vocals();
        $instrumentals = self::instrumentals();

        $artists = collect($this->list($answer['artists'] ?? null))
            ->map(fn(array $artist, int $i) => [
                'key' => 'a' . ($i + 1),
                'ref' => (string) ($artist['key'] ?? ''),
                'name' => $this->text($artist['name'] ?? null, 100),
                'about' => $this->text($artist['about'] ?? null, 2000),
                'look' => $this->text($artist['look'] ?? null, 400),
            ])
            ->filter(fn(array $artist) => $artist['name'] !== '')
            ->unique(fn(array $artist) => mb_strtolower($artist['name']))
            ->take(self::MAX_ARTISTS)
            ->values();

        if ($artists->isEmpty() && $this->list($answer['albums'] ?? null) !== []) {
            throw new RuntimeException('The plan had albums but no artists.');
        }

        $artistKey = fn($ref) => $artists->firstWhere('ref', (string) $ref)['key']
            ?? $artists->first(fn(array $artist) => mb_strtolower($artist['name']) === mb_strtolower((string) $ref))['key']
            ?? $artists->first()['key'];

        $left = $maxTracks;
        $trackNumber = 0;
        $albums = [];

        foreach ($this->list($answer['albums'] ?? null) as $i => $album) {
            $tracks = [];

            foreach ($this->list($album['tracks'] ?? null) as $track) {
                $title = $this->text($track['title'] ?? null, 120);

                if ($left === 0 || $title === '') {
                    continue;
                }

                $instrumental = ! $vocals || ($instrumentals && ! empty($track['instrumental']));

                $tracks[] = [
                    'key' => 't' . (++$trackNumber),
                    'title' => $title,
                    'style' => $this->text($track['style'] ?? null, 400) ?: $title,
                    'theme' => $this->text($track['theme'] ?? null, 400),
                    'mood' => $this->text($track['mood'] ?? null, 40) ?: null,
                    'instrumental' => $instrumental,
                ];
                $left--;
            }

            $title = $this->text($album['title'] ?? null, 120);

            if ($tracks === [] || $title === '') {
                continue;
            }

            $albums[] = [
                'key' => 'al' . ($i + 1),
                'title' => $title,
                'artist' => $artistKey($album['artist'] ?? null),
                'genre_id' => $byName[mb_strtolower(trim((string) ($album['genre'] ?? '')))] ?? $fallbackGenre,
                'description' => $this->text($album['description'] ?? null, 2000),
                'cover' => $this->text($album['cover'] ?? null, 400),
                'tracks' => $tracks,
            ];
        }

        $songs = collect($this->list($answer['songs'] ?? null))
            ->map(fn(array $song, int $i) => [
                'key' => 's' . ($i + 1),
                'title' => $this->text($song['title'] ?? null, 120),
                'theme' => $this->text($song['theme'] ?? null, 400),
                'style' => $this->text($song['style'] ?? null, 400),
                'mood' => $this->text($song['mood'] ?? null, 40) ?: null,
            ])
            ->filter(fn(array $song) => $song['title'] !== '')
            ->take($maxTracks)
            ->values()
            ->all();

        if ($albums === [] && $songs === []) {
            throw new RuntimeException('The plan had no albums, tracks or songs.');
        }

        $used = collect($albums)->pluck('artist');

        return [
            'artists' => $artists
                ->filter(fn(array $artist) => $used->contains($artist['key']))
                ->map(fn(array $artist) => array_diff_key($artist, ['ref' => true]))
                ->values()
                ->all(),
            'albums' => $albums,
            'songs' => $songs,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function list(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    protected function text(mixed $value, int $max): string
    {
        return is_scalar($value)
            ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $value)), 0, $max)
            : '';
    }
}
