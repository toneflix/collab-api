<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lets the planner find the account's own projects and artists by words in
 * the instructions, when the account has more than the plan's lists show.
 * One short ask turns the instructions into search terms, then each term
 * matches project, release and track titles, and artist names.
 */
class AgentSearch
{
    /** Most search terms one run looks up */
    public const MAX_TERMS = 5;

    /** Most matches each term adds, of projects and of artists */
    public const PER_TERM = 10;

    public function __construct(protected AgentWriter $writer) {}

    /**
     * Whether the account has more projects or artists than the plan lists.
     */
    public function needed(AgentRun $run, int $shown): bool
    {
        $owner = $run->owner;

        $projects = $owner
            ->projects()
            ->count();
        $artists = $owner
            ->artists()
            ->count();

        return $projects > $shown || $artists > $shown;
    }

    /**
     * The search terms the instructions point to.
     *
     * @return list<string>
     */
    public function terms(AgentRun $run): array
    {
        $answer = $this->writer->json($run, 'search', implode("\n", [
            'You find what an admin\'s instructions refer to in a music account\'s catalogue.',
            'List up to ' . self::MAX_TERMS . ' short search terms: titles of projects, albums or tracks, or artist names, that the instructions name or describe.',
            'Leave it empty when the instructions only ask for new music.',
            'Answer with JSON only: {"search": ["term"]}',
        ]), "Instructions:\n\"\"\"\n" . trim((string) $run->prompt) . "\n\"\"\"", 300);

        return collect(is_array($answer['search'] ?? null) ? $answer['search'] : [])
            ->filter(fn($term) => is_string($term))
            ->map(fn(string $term) => mb_substr(trim($term), 0, 80))
            ->filter(fn(string $term) => mb_strlen($term) >= 2)
            ->unique(fn(string $term) => mb_strtolower($term))
            ->take(self::MAX_TERMS)
            ->values()
            ->all();
    }

    /**
     * The owner's projects matching any term by its title, a release title
     * or a track title.
     *
     * @param  list<string>  $terms
     * @return Collection<int, Project>
     */
    public function projects(AgentRun $run, array $terms, callable $query): Collection
    {
        return collect($terms)
            ->flatMap(fn(string $term) => $query()
                ->where(fn(Builder $match) => $match
                    ->whereLike('projects.title', $this->like($term))
                    ->orWhereHas('releases', fn(Builder $release) => $release
                        ->withoutGlobalScopes()
                        ->whereLike('title', $this->like($term)))
                    ->orWhereHas('tracks', fn(Builder $track) => $track->whereLike('title', $this->like($term))))
                ->limit(self::PER_TERM)
                ->get())
            ->unique('id')
            ->values();
    }

    /**
     * The owner's artists matching any term by name.
     *
     * @param  list<string>  $terms
     * @return Collection<int, string>
     */
    public function artists(AgentRun $run, array $terms): Collection
    {
        return collect($terms)
            ->flatMap(fn(string $term) => $run->owner
                ->artists()
                ->whereLike('name', $this->like($term))
                ->limit(self::PER_TERM)
                ->pluck('name'))
            ->unique()
            ->values();
    }

    protected function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
