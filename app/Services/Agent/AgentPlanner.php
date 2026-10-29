<?php

namespace App\Services\Agent;

use App\Enums\ArtworkKind;
use App\Models\AgentRun;
use App\Models\Genre;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Tasks\GenerateArtwork;
use App\Services\Artists\FeaturedArtists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    /** Most existing projects one run can release */
    public const MAX_RELEASES = 10;

    /** Most existing projects and releases one run can touch up */
    public const MAX_UPDATES = 50;

    /** Existing projects the plan can pick from, of each kind: latest, named by ID, missing artwork */
    public const PROJECT_CHOICES = 50;

    /** Track length when the run doesn't set one, in seconds */
    public const DEFAULT_SECONDS = 120;

    public function __construct(
        protected AgentWriter $writer,
        protected AgentSearch $search,
    ) {}

    /**
     * Ask for a plan and tidy it into the shape the steps are built from.
     *
     * @return array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, releases: list<array<string, mixed>>, updates: list<array<string, mixed>>, songs: list<array<string, mixed>>}
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
        $projects = $this->projects($run);

        if ($this->search->needed($run, self::PROJECT_CHOICES)) {
            $terms = $this->search->terms($run);

            $projects = $this->search
                ->projects($run, $terms, fn() => $this->projectQuery($run))
                ->concat($projects)
                ->unique('id')
                ->values();
            $existing = $this->search
                ->artists($run, $terms)
                ->concat($existing)
                ->unique()
                ->values();
        }

        $answer = $this->writer->json(
            $run,
            'plan',
            $this->system(),
            $this->prompt($run, $genres, $existing, $projects),
            4000,
        );

        return $this->normalize($answer, $genres, $this->maxTracks($run), $projects, $run->owner);
    }

    /**
     * The owner's projects the plan can pick from: the latest, those the
     * prompt names by ID and those missing artwork. Projects found by search
     * are added in plan().
     *
     * @return Collection<int, Project>
     */
    public function projects(AgentRun $run): Collection
    {
        preg_match_all('/#?\b(\d{1,9})\b/', (string) $run->prompt, $matches);

        $ids = array_slice(array_unique(array_map('intval', $matches[1])), 0, self::PROJECT_CHOICES);
        $base = fn() => $this
            ->projectQuery($run)
            ->limit(self::PROJECT_CHOICES);

        $latest = $base()->get();
        $named = $ids ? $base()
            ->whereKey($ids)
            ->get() : collect();
        $bare = $base()
            ->whereNull('image')
            ->get();

        return $named
            ->concat($latest)
            ->concat($bare)
            ->unique('id')
            ->values();
    }

    /**
     * The owner's projects, newest first, with what the planner shows of them.
     *
     * @return HasMany<Project, User>
     */
    protected function projectQuery(AgentRun $run): HasMany
    {
        return $run->owner
            ->projects()
            ->with('release.artist')
            ->withCount([
                'tracks',
                'tracks as untimed_count' => fn(Builder $query) => $query->whereHas(
                    'lyric',
                    fn(Builder $lyric) => $lyric->whereNull('lines->0->start'),
                ),
            ])
            ->latest('id');
    }

    /**
     * The steps that build a plan, in the order they are listed.
     *
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, releases?: list<array<string, mixed>>, updates?: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
     * @return list<array{key: string, kind: string, depends_on: list<string>, input: array<string, mixed>}>
     */
    public function steps(AgentRun $run, array $plan): array
    {
        $steps = [];
        $seconds = $this->seconds($run);
        $artwork = $this->artwork($run);
        $genres = Genre::query()
            ->whereKey([...array_column($plan['albums'], 'genre_id'), ...array_column($plan['releases'] ?? [], 'genre_id')])
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
            $syncs = [];
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
                $featuring = array_map(fn(string $key) => "artist:{$key}", $track['featuring']);

                $steps[] = $this->step($source, $kind, [$artist, ...$featuring, $project], [
                    'title' => $track['title'],
                    'theme' => $track['theme'],
                    'style' => $track['style'],
                    'mood' => $track['mood'],
                    'artist' => $artist,
                    'featuring' => $featuring,
                    'voices' => array_values(array_filter([
                        $names[$album['artist']] ?? null,
                        ...array_map(fn(string $key) => $names[$key] ?? null, $track['featuring']),
                    ])),
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

                if (! $track['instrumental']) {
                    $steps[] = $syncs[] = $this->step("lyrics:{$track['key']}", 'lyrics', ["music:{$track['key']}"], [
                        'title' => $track['title'],
                        'source' => $source,
                        'music' => "music:{$track['key']}",
                    ]);
                }
            }

            if (! ($album['release'] ?? true)) {
                continue;
            }

            $steps[] = $this->step("release:{$album['key']}", 'release', [...array_column($mixes, 'key'), ...array_column($syncs, 'key'), ...array_filter([$art])], [
                'title' => $album['title'],
                'description' => $album['description'],
                'genre_id' => $album['genre_id'],
                'artist' => $artist,
                'project' => $project,
                'tracks' => array_map(fn(array $mix) => $mix['input']['source'], $mixes),
                'artwork' => $art,
            ]);
        }

        $covers = [];
        $upkeep = [];

        foreach ($plan['updates'] ?? [] as $update) {
            $key = $update['key'];
            $projectId = $update['project_id'];

            if ($update['artwork']) {
                $steps[] = $this->step("cover:{$key}", 'artwork', [], [
                    'title' => $update['title'],
                    'artwork_kind' => ArtworkKind::RELEASE->value,
                    'prompt' => $update['cover'] ?: $update['description'] ?: $update['title'],
                    'artist' => $update['artist'],
                    'target' => $projectId ? "fill:{$projectId}" : "fill-release:{$update['release_id']}",
                ]);

                if ($projectId) {
                    $covers[$projectId] = $upkeep[$projectId][] = "cover:{$key}";
                }
            }

            foreach ($update['lyrics'] as $track) {
                $steps[] = $this->step("lyrics:{$key}-{$track['id']}", 'lyrics', [], [
                    'title' => $track['title'],
                    'track_id' => $track['id'],
                ]);
                $upkeep[$projectId][] = "lyrics:{$key}-{$track['id']}";
            }
        }

        foreach ($plan['releases'] ?? [] as $release) {
            $key = $release['key'];
            $artist = "artist:{$release['artist']}";
            $art = $covers[$release['project_id']] ?? ($artwork ? "cover:{$key}" : null);
            $mixes = [];

            if ($art === "cover:{$key}") {
                $steps[] = $this->step($art, 'artwork', [$artist], [
                    'title' => $release['title'],
                    'artwork_kind' => count($release['tracks']) === 1 ? ArtworkKind::TRACK->value : ArtworkKind::RELEASE->value,
                    'prompt' => $release['cover'] ?: $release['description'] ?: $release['title'],
                    'artist' => $names[$release['artist']] ?? null,
                    'genre' => $genres[$release['genre_id']] ?? null,
                    'target' => null,
                ]);
            }

            foreach ($release['tracks'] as $track) {
                $steps[] = $mixes[] = $this->step("mix:{$key}-{$track['id']}", 'mix', [], [
                    'title' => $track['title'],
                    'track_id' => $track['id'],
                ]);
            }

            $steps[] = $this->step("release:{$key}", 'release', array_values(array_unique([$artist, ...array_column($mixes, 'key'), ...($upkeep[$release['project_id']] ?? []), ...array_filter([$art])])), [
                'title' => $release['title'],
                'version' => $release['version'],
                'description' => $release['description'],
                'genre_id' => $release['genre_id'],
                'artist' => $artist,
                'project_id' => $release['project_id'],
                'track_ids' => array_column($release['tracks'], 'id'),
                'retire' => $release['retire'],
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
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, releases?: list<array<string, mixed>>, updates?: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
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

        $updates = collect($plan['updates'] ?? []);
        $fills = $updates
            ->where('artwork', true)
            ->pluck('project_id');
        $timings = $updates->sum(fn(array $update) => count($update['lyrics']));
        $releaseCovers = collect($plan['releases'] ?? [])
            ->reject(fn(array $release) => $fills->contains($release['project_id']))
            ->count();
        $pictures = $fills->count() + ($this->artwork($run) ? count($plan['artists']) + count($plan['albums']) + $releaseCovers : 0);

        return ($vocals + count($plan['songs'])) * AgentWriter::credits()
            + ($vocals + $timings) * AiSettings::lyricsCredits()
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
            'An album is a list of tracks released together. A single is an album with exactly one track: put every single in "albums", never in "songs".',
            '"songs" are lyric sheets only, with no music. Use them only when the instructions ask for written lyrics, lyric sheets or compositions without music; tracks, singles, EPs and albums are never "songs".',
            'Each track has a "style": one or two sentences for a music generator describing genre, tempo, instruments, vocals and mood. Never name real artists in it.',
            'Each track with vocals has a "theme": what its lyrics are about, in one sentence.',
            'A track can feature other artists, a collaboration like "Dave featuring Daniel": list the keys of the featured artists from "artists" in the track\'s "featuring", never the album\'s own artist. Add features only when the instructions ask for features, collaborations or duets, and say in the track\'s "style" how the voices share it, like "duet, male and female vocals".',
            'Albums are new studio projects. A "project" the instructions ask for (rather than an album, EP or single) is a studio project that isn\'t released: list it in "albums" with "release" set to false. Also set "release" to false when the instructions say not to release.',
            '"releases" release the account\'s existing projects, named by "#id" or their exact title, only when the instructions ask for it. "version" labels a new version, like "Deluxe" or "Remastered", when the project was released before; set "retire" to true when the new version should replace the earlier ones.',
            '"updates" touch up the account\'s existing work, only when the instructions ask for it. Name each by a project\'s "#id", or by the exact title of a project, album or track the instructions mention, even when it isn\'t listed below: "artwork" draws a picture for a project and whatever of it has none, "lyrics" times its untimed lyrics to the audio. "cover" describes the picture. A project both updated and released gets the update first.',
            'When the instructions ask to find and fix everything missing artwork (all projects, albums or tracks), set "sweep" "artwork" to true; for all untimed lyrics, set "sweep" "lyrics" to true. The sweep finds them itself, so don\'t list them in "updates" too.',
            'Each album has a "cover": one sentence describing its cover image, and each artist a "look": one sentence describing their profile picture. Describe scenes, colours and style, never text or real people.',
            'Keep every name and title short and plain, with no quotes or emoji.',
            'Answer with JSON only, no other text, in this shape:',
            '{"artists": [{"key": "a1", "name": "", "about": "two or three sentence bio", "look": ""}],'
                . ' "albums": [{"key": "al1", "title": "", "artist": "a1", "genre": "", "description": "", "cover": "", "release": true, "tracks": [{"title": "", "style": "", "theme": "", "mood": "", "instrumental": false, "featuring": []}]}],'
                . ' "releases": [{"project": "#id or existing project title", "title": "", "version": "", "artist": "a1", "genre": "", "description": "", "cover": "", "retire": false}],'
                . ' "updates": [{"project": "#id or existing project title", "artwork": true, "lyrics": true, "cover": ""}],'
                . ' "sweep": {"artwork": false, "lyrics": false},'
                . ' "songs": [{"title": "", "theme": "", "style": "", "mood": ""}]}',
        ]);
    }

    /**
     * @param  Collection<int|string, string>  $genres
     * @param  Collection<int, string>  $existing
     * @param  Collection<int, Project>  $projects
     */
    protected function prompt(AgentRun $run, Collection $genres, Collection $existing, Collection $projects): string
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
            $projects->isNotEmpty()
                ? "The account's existing projects, for \"releases\" and \"updates\" (list a release's artist in \"artists\", by the name it was released under when it has one):\n" . $projects
                    ->map(fn(Project $project) => '- ' . $this->projectLine($project))
                    ->join("\n")
                : null,
        ], fn($line) => $line !== null));
    }

    /**
     * An existing project as the planner sees it.
     */
    protected function projectLine(Project $project): string
    {
        $release = $project->release;

        return collect([
            "#{$project->id} \"{$project->title}\"",
            "{$project->tracks_count} tracks",
            match (true) {
                ! $release => 'never released',
                (bool) $release->draft => 'has a draft release',
                default => 'released' . ($release->artist ? " by {$release->artist->name}" : ''),
            },
            ! $project->image || ($release && ! $release->image) ? 'missing artwork' : null,
            $project->untimed_count ? $project->untimed_count . ' ' . Str::plural('track', $project->untimed_count) . ' with untimed lyrics' : null,
        ])
            ->filter()
            ->join(', ');
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  Collection<int|string, string>  $genres
     * @param  Collection<int, Project>  $projects
     * @return array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, releases: list<array<string, mixed>>, updates: list<array<string, mixed>>, songs: list<array<string, mixed>>}
     */
    protected function normalize(array $answer, Collection $genres, int $maxTracks, Collection $projects, User $owner): array
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

        if ($artists->isEmpty() && [...$this->list($answer['albums'] ?? null), ...$this->list($answer['releases'] ?? null)] !== []) {
            throw new RuntimeException('The plan had albums or releases but no artists.');
        }

        $findArtist = function (mixed $ref) use ($artists): ?string {
            if (! is_scalar($ref)) {
                return null;
            }

            return $artists->firstWhere('ref', (string) $ref)['key']
                ?? $artists->first(fn(array $artist) => mb_strtolower($artist['name']) === mb_strtolower((string) $ref))['key']
                ?? null;
        };
        $artistKey = fn($ref) => $findArtist($ref) ?? $artists->first()['key'];

        $left = $maxTracks;
        $trackNumber = 0;
        $albums = [];

        foreach ($this->list($answer['albums'] ?? null) as $i => $album) {
            $tracks = [];
            $main = $artistKey($album['artist'] ?? null);

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
                    'featuring' => $this->featuring($track['featuring'] ?? null, $findArtist, $main),
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
                'artist' => $main,
                'genre_id' => $byName[mb_strtolower(trim((string) ($album['genre'] ?? '')))] ?? $fallbackGenre,
                'description' => $this->text($album['description'] ?? null, 2000),
                'cover' => $this->text($album['cover'] ?? null, 400),
                'release' => ($album['release'] ?? true) !== false,
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

        $releases = $this->releases($answer, $projects, $artistKey, $byName);
        $updates = $this->sweep($answer, $owner, $this->updates($answer, $projects, $owner));

        if ($albums === [] && $songs === [] && $releases === [] && $updates === []) {
            $named = collect($this->list($answer['updates'] ?? null))
                ->map(fn(array $update) => $this->text($update['project'] ?? null, 200))
                ->filter()
                ->map(fn(string $name) => "\"{$name}\"")
                ->join(', ', ' and ');

            throw new RuntimeException($named !== ''
                ? "Nothing to do: {$named} weren't found on the account, or already have their artwork and synced lyrics."
                : 'The plan had no albums, releases, updates, tracks or songs.');
        }

        $featured = collect($albums)->flatMap(fn(array $album) => array_merge(...array_column($album['tracks'], 'featuring')));
        $used = collect([...$albums, ...$releases])
            ->pluck('artist')
            ->concat($featured);

        return [
            'artists' => $artists
                ->filter(fn(array $artist) => $used->contains($artist['key']))
                ->map(fn(array $artist) => array_diff_key($artist, ['ref' => true]))
                ->values()
                ->all(),
            'albums' => $albums,
            'releases' => $releases,
            'updates' => $updates,
            'songs' => $songs,
        ];
    }

    /**
     * Keys of the planned artists a track features, without its main artist,
     * up to FeaturedArtists::MAX.
     *
     * @return list<string>
     */
    protected function featuring(mixed $refs, callable $findArtist, string $main): array
    {
        return collect(is_array($refs) ? $refs : [$refs])
            ->map(fn($ref) => $findArtist($ref))
            ->filter(fn(?string $key) => $key !== null && $key !== $main)
            ->unique()
            ->take(FeaturedArtists::MAX)
            ->values()
            ->all();
    }

    /**
     * The existing projects the plan releases, each with the tracks it
     * carries, capped at MAX_TRACKS a release.
     *
     * @param  array<string, mixed>  $answer
     * @param  Collection<int, Project>  $projects
     * @param  Collection<string, int|string>  $genreIds
     * @return list<array<string, mixed>>
     */
    protected function releases(array $answer, Collection $projects, callable $artistKey, Collection $genreIds): array
    {
        $releases = [];

        foreach ($this->list($answer['releases'] ?? null) as $release) {
            $project = $this->project($projects, $release['project'] ?? null);

            if (! $project || count($releases) === self::MAX_RELEASES || in_array($project->id, array_column($releases, 'project_id'))) {
                continue;
            }

            $tracks = $project
                ->tracks()
                ->oldest('id')
                ->limit(self::MAX_TRACKS)
                ->get(['id', 'title'])
                ->map(fn($track) => ['id' => $track->id, 'title' => (string) $track->title])
                ->all();

            if ($tracks === []) {
                continue;
            }

            $genre = mb_strtolower(trim((string) ($release['genre'] ?? '')));

            $releases[] = [
                'key' => 'r' . (count($releases) + 1),
                'project_id' => $project->id,
                'project' => $project->title,
                'title' => $this->text($release['title'] ?? null, 120) ?: $project->title,
                'version' => $this->text($release['version'] ?? null, 60) ?: null,
                'artist' => $artistKey($release['artist'] ?? null),
                'genre_id' => $genreIds[$genre] ?? $project->release?->genre_id ?? $genreIds->first(),
                'description' => $this->text($release['description'] ?? null, 2000) ?: (string) $project->description,
                'cover' => $this->text($release['cover'] ?? null, 400),
                'retire' => (bool) ($release['retire'] ?? false),
                'tracks' => $tracks,
            ];
        }

        return $releases;
    }

    /**
     * The existing projects the plan touches up: a picture for whatever of
     * the project has none, and timings for its untimed lyrics. Projects
     * with nothing to do are left out.
     *
     * @param  array<string, mixed>  $answer
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    protected function updates(array $answer, Collection $projects, User $owner): array
    {
        $updates = [];
        $draws = self::drawsArtwork();

        foreach ($this->list($answer['updates'] ?? null) as $update) {
            $target = $this->target($owner, $projects, $update['project'] ?? null);

            if (! $target || count($updates) === self::MAX_UPDATES) {
                continue;
            }

            if ($target instanceof Release) {
                if ($draws && ! empty($update['artwork']) && ! $target->image && ! in_array($target->id, array_column($updates, 'release_id'))) {
                    $updates[] = $this->releaseTouchUp($target, 'u' . (count($updates) + 1), $this->text($update['cover'] ?? null, 400));
                }

                continue;
            }

            $project = $target;

            if (in_array($project->id, array_column($updates, 'project_id'))) {
                continue;
            }

            $touchUp = $this->touchUp(
                $project,
                $draws && ! empty($update['artwork']),
                ! empty($update['lyrics']),
                $this->text($update['cover'] ?? null, 400),
                'u' . (count($updates) + 1),
            );

            if ($touchUp) {
                $updates[] = $touchUp;
            }
        }

        return $updates;
    }

    /**
     * Add every project of the owner with missing artwork or untimed lyrics,
     * and every release of theirs with no project and no picture, when the
     * plan sweeps for them, up to MAX_UPDATES touch-ups in all.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<array<string, mixed>>  $updates
     * @return list<array<string, mixed>>
     */
    protected function sweep(array $answer, User $owner, array $updates): array
    {
        $sweep = is_array($answer['sweep'] ?? null) ? $answer['sweep'] : [];
        $artwork = ! empty($sweep['artwork']) && self::drawsArtwork();
        $lyrics = ! empty($sweep['lyrics']);
        $left = self::MAX_UPDATES - count($updates);

        if ((! $artwork && ! $lyrics) || $left <= 0) {
            return $updates;
        }

        $projects = $owner
            ->projects()
            ->with('release.artist')
            ->whereNotIn('id', array_filter(array_column($updates, 'project_id')))
            ->where(fn(Builder $query) => $query
                ->when($artwork, fn(Builder $missing) => $missing->orWhere(fn(Builder $q) => AgentArtwork::whereMissing($q)))
                ->when($lyrics, fn(Builder $untimed) => $untimed->orWhereHas('tracks', fn(Builder $track) => $track->whereHas(
                    'lyric',
                    fn(Builder $lyric) => $lyric->whereNull('lines->0->start'),
                ))))
            ->oldest('id')
            ->limit($left)
            ->get();

        foreach ($projects as $project) {
            $touchUp = $this->touchUp($project, $artwork, $lyrics, '', 'u' . (count($updates) + 1));

            if ($touchUp) {
                $updates[] = $touchUp;
            }
        }

        $left = self::MAX_UPDATES - count($updates);

        if (! $artwork || $left <= 0) {
            return $updates;
        }

        $releases = $owner
            ->releases()
            ->withoutGlobalScopes()
            ->with('artist')
            ->whereNull('project_id')
            ->whereNull('image')
            ->oldest('id')
            ->limit($left)
            ->get();

        foreach ($releases as $release) {
            $updates[] = $this->releaseTouchUp($release, 'u' . (count($updates) + 1), '');
        }

        return $updates;
    }

    /**
     * What one project needs: a picture when anything of it has none and
     * artwork is asked for, and timings for its untimed lyrics when lyrics
     * are. Null when it needs nothing.
     *
     * @return array<string, mixed>|null
     */
    protected function touchUp(Project $project, bool $artwork, bool $lyrics, string $cover, string $key): ?array
    {
        $artwork = $artwork && AgentArtwork::missing($project);

        $tracks = ! $lyrics ? [] : $project
            ->tracks()
            ->whereHas('lyric', fn(Builder $lyric) => $lyric->whereNull('lines->0->start'))
            ->oldest('id')
            ->limit(self::MAX_TRACKS)
            ->get(['id', 'title'])
            ->map(fn($track) => ['id' => $track->id, 'title' => (string) $track->title])
            ->all();

        if (! $artwork && $tracks === []) {
            return null;
        }

        return [
            'key' => $key,
            'project_id' => $project->id,
            'release_id' => null,
            'project' => $project->title,
            'title' => $project->release?->title ?: $project->title,
            'artist' => $project->release?->artist?->name,
            'description' => (string) $project->description,
            'cover' => $cover,
            'artwork' => $artwork,
            'lyrics' => $tracks,
        ];
    }

    /**
     * A cover for a release with no project.
     *
     * @return array<string, mixed>
     */
    protected function releaseTouchUp(Release $release, string $key, string $cover): array
    {
        return [
            'key' => $key,
            'project_id' => null,
            'release_id' => $release->id,
            'project' => $release->title,
            'title' => $release->title,
            'artist' => $release->artist?->name,
            'description' => (string) $release->description,
            'cover' => $cover,
            'artwork' => true,
            'lyrics' => [],
        ];
    }

    /**
     * What a touch-up names: one of the listed projects by "#id" or title,
     * else the owner's project, release or track with that exact title. A
     * release or track counts as its project when it has one; a release
     * without a project is returned itself.
     *
     * @param  Collection<int, Project>  $projects
     */
    protected function target(User $owner, Collection $projects, mixed $ref): Project|Release|null
    {
        $listed = $this->project($projects, $ref);
        $title = mb_strtolower($this->text($ref, 200));

        if ($listed || $title === '' || preg_match('/^#?\d+$/', $title)) {
            return $listed;
        }

        $match = fn(string $column) => fn(Builder $query) => $query->whereRaw("lower({$column}) = ?", [$title]);

        $project = $owner
            ->projects()
            ->with('release.artist')
            ->where($match('projects.title'))
            ->latest('id')
            ->first();

        if ($project) {
            return $project;
        }

        $release = $owner
            ->releases()
            ->withoutGlobalScopes()
            ->with(['artist', 'project.release.artist'])
            ->where(fn(Builder $query) => $query
                ->where($match('releases.title'))
                ->orWhereHas('tracks', $match('tracks.title')))
            ->latest('id')
            ->first();

        if ($release) {
            return $release->project ?? $release;
        }

        return $owner
            ->projects()
            ->with('release.artist')
            ->whereHas('tracks', $match('project_tracks.title'))
            ->latest('id')
            ->first();
    }

    /**
     * One of the owner's projects, by "#id" or exact title.
     *
     * @param  Collection<int, Project>  $projects
     */
    protected function project(Collection $projects, mixed $ref): ?Project
    {
        $ref = $this->text($ref, 200);

        if (preg_match('/^#?(\d+)$/', $ref, $match)) {
            return $projects->firstWhere('id', (int) $match[1]);
        }

        return $projects->first(fn(Project $project) => mb_strtolower($project->title) === mb_strtolower($ref));
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
