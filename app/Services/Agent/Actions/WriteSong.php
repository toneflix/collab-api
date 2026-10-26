<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Project;
use App\Models\Song;
use App\Services\Agent\AgentWriter;
use App\Services\Ai\AiSettings;
use App\Services\Composer\SongProjects;
use App\Services\Composer\Songs;
use App\Services\Lyrics\LyricsCheck;
use RuntimeException;

/**
 * Writes a song's lyrics into the owner's Composer and, for an album track,
 * puts the song on a track of the album's project with its lyric sheet.
 */
class WriteSong implements AgentAction
{
    /** Longest lyrics for a song that won't be sung, in characters */
    public const MAX_LENGTH = 3000;

    public function __construct(
        protected AgentWriter $writer,
        protected Songs $songs,
        protected SongProjects $projects,
        protected LyricsCheck $check,
    ) {}

    public function advance(AgentStep $step): bool
    {
        $input = $step->input;
        $owner = $step->run->owner;
        $song = Song::query()->find($step->output['song_id'] ?? null);

        if (! $song) {
            $body = $this->lyrics($step);

            $song = $this->songs->create($owner, [
                'title' => $input['title'],
                'body' => $body,
                'notes' => $input['style'] ?: null,
                'settings' => ['mood' => $input['mood'] ?? null, 'explicit' => false],
            ]);

            $step->remember(['song_id' => $song->id]);
        }

        if (empty($input['project']) || ($step->output['project_track_id'] ?? null)) {
            return true;
        }

        $project = Project::query()->findOrFail($step->from($input['project'], 'project_id'));

        ['track' => $track] = $this->projects->create($song, $owner, [
            'title' => $input['title'],
            'genre_id' => $input['genre_id'],
            'artist_id' => $step->from($input['artist'], 'artist_id'),
        ], $project);

        $step->remember(['project_track_id' => $track->id, 'project_id' => $project->id]);

        return true;
    }

    /**
     * Original lyrics with section lines, short enough for the music model to sing.
     */
    protected function lyrics(AgentStep $step): string
    {
        $input = $step->input;
        $max = empty($input['project'])
            ? self::MAX_LENGTH
            : min(self::MAX_LENGTH, AiSettings::composerMusicModel()->lyricsLength()[1] ?? self::MAX_LENGTH);

        $answer = $this->writer->json($step->run, 'lyrics', implode("\n", [
            'You write original song lyrics for a streaming platform.',
            'Never quote or closely paraphrase existing songs, and never name real artists.',
            'Keep it clean: no profanity, slurs or sexual content.',
            'Start each section with a line in square brackets on its own, like [Verse 1], [Chorus] or [Bridge].',
            'Answer with JSON only, no other text: {"lines": ["[Verse 1]", "first line", "second line"]}',
        ]), implode("\n", array_filter([
            'Song: ' . $input['title'],
            $input['theme'] ? 'About: ' . $input['theme'] : null,
            $input['style'] ? 'Sound: ' . $input['style'] : null,
            ! empty($input['mood']) ? 'Mood: ' . $input['mood'] : null,
            "Keep the whole lyrics under {$max} characters, line breaks included.",
        ])), 2000);

        $body = '';

        foreach (is_array($answer['lines'] ?? null) ? $answer['lines'] : [] as $line) {
            $line = is_string($line) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $line)), 0, 200) : '';
            $found = $this->check->scan($line);

            if ($found['explicit'] !== [] || $found['review'] !== []) {
                continue;
            }

            if ($line === '') {
                continue;
            }

            // A blank line goes before each section after the first.
            $gap = preg_match('/^\[[^\]]*\]$/u', $line) ? "\n\n" : "\n";
            $next = $body === '' ? $line : $body . $gap . $line;

            if (mb_strlen($next) > $max) {
                break;
            }

            $body = $next;
        }

        $sung = collect(preg_split('/\R/u', $body))
            ->reject(fn(string $line) => trim($line) === '' || preg_match('/^\[[^\]]*\]$/u', trim($line)))
            ->count();

        if ($sung < 4) {
            throw new RuntimeException('The AI didn\'t write usable lyrics.');
        }

        return $body;
    }
}
