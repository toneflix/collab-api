<?php

namespace App\Services\Artists;

use Illuminate\Http\Request;

/**
 * The featured artists a request sends for a track: their rules, and the
 * list read from either an array or one comma-separated value, which is how
 * multipart forms send it.
 */
class FeaturedArtists
{
    /** Most artists one track can feature */
    public const MAX = 5;

    /**
     * Validation rules for a featured artists field, like `featured_ids` or
     * `tracks.*.featured_ids`.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $field): array
    {
        return [
            $field => ['nullable', 'array', 'max:' . self::MAX],
            "{$field}.*" => ['integer', 'distinct', 'exists:artists,id'],
        ];
    }

    /**
     * Turn a comma-separated `featured_ids` into a list, on the request and
     * on each of its tracks, before validation.
     */
    public static function prepare(Request $request): void
    {
        if ($request->has('featured_ids')) {
            $request->merge(['featured_ids' => self::list($request->input('featured_ids'))]);
        }

        if (! is_array($request->input('tracks'))) {
            return;
        }

        $tracks = $request->input('tracks');

        foreach ($tracks as $i => $track) {
            if (is_array($track) && array_key_exists('featured_ids', $track)) {
                $tracks[$i]['featured_ids'] = self::list($track['featured_ids']);
            }
        }

        $request->merge(['tracks' => $tracks]);
    }

    /**
     * The ids in a list or comma-separated value; empty when nothing is sent.
     *
     * @return list<string>
     */
    public static function list(mixed $value): array
    {
        $ids = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn($id) => trim((string) $id), $ids), 'strlen'));
    }

    /**
     * The featured ids as integers, without the primary artist or repeats.
     *
     * @param  list<int|string>  $featured
     * @return list<int>
     */
    public static function without(array $featured, int|string|null $primary): array
    {
        return collect($featured)
            ->map(fn($id) => (int) $id)
            ->reject(fn(int $id) => $id === (int) $primary || $id === 0)
            ->unique()
            ->take(self::MAX)
            ->values()
            ->all();
    }
}
