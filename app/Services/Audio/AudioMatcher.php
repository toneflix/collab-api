<?php

namespace App\Services\Audio;

use App\Models\AudioFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps fingerprints of uploaded audio and finds earlier uploads that sound
 * the same. Likely matches come from codes shared in SQL, then each one is
 * compared bit by bit at its best alignment.
 */
class AudioMatcher
{
    /** Bits that must agree, from 0 to 1, to call two pieces of audio the same */
    public const THRESHOLD = 0.85;

    /** About ten seconds of codes must overlap to count as a match */
    public const MIN_OVERLAP = 80;

    /** Likely matches compared closely for each upload */
    public const CANDIDATES = 10;

    public function __construct(protected Fingerprinter $fingerprinter) {}

    /**
     * Fingerprint a local file for a model, replacing its old fingerprint.
     */
    public function store(Model $model, string $path, float $duration = 0): AudioFingerprint
    {
        $codes = $this->fingerprinter->codes($path);

        return DB::transaction(function () use ($model, $path, $duration, $codes) {
            $fingerprint = AudioFingerprint::query()->updateOrCreate([
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
            ], [
                'hash' => $this->fingerprinter->hash($path),
                'duration' => $duration,
                'codes' => AudioFingerprint::packCodes($codes),
            ]);

            DB::table('audio_fingerprint_keys')->where('audio_fingerprint_id', $fingerprint->id)->delete();

            foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
                DB::table('audio_fingerprint_keys')->insert(array_map(fn (int $code) => [
                    'audio_fingerprint_id' => $fingerprint->id,
                    'code' => $code,
                ], $chunk));
            }

            return $fingerprint;
        });
    }

    /**
     * Earlier fingerprints of the same kind of model that sound like this one,
     * best first.
     *
     * @return Collection<int, array{fingerprint: AudioFingerprint, score: float, kind: string}>
     */
    public function matches(AudioFingerprint $fingerprint): Collection
    {
        $exact = AudioFingerprint::query()
            ->where('model_type', $fingerprint->model_type)
            ->where('hash', $fingerprint->hash)
            ->where('id', '<', $fingerprint->id)
            ->oldest('id')
            ->limit(self::CANDIDATES)
            ->get()
            ->map(fn (AudioFingerprint $match) => ['fingerprint' => $match, 'score' => 1.0, 'kind' => 'exact']);

        $codes = $fingerprint->codeList();
        if (count($codes) < self::MIN_OVERLAP) {
            return $exact->values();
        }

        $skip = $exact->pluck('fingerprint.id')->push($fingerprint->id)->all();

        $candidates = AudioFingerprint::query()
            ->whereIn('id', $this->candidateIds($fingerprint, $codes, $skip))
            ->get();

        $acoustic = $candidates
            ->map(fn (AudioFingerprint $match) => [
                'fingerprint' => $match,
                'score' => $this->similarity($codes, $match->codeList()),
                'kind' => 'acoustic',
            ])
            ->filter(fn (array $match) => $match['score'] >= self::THRESHOLD);

        return $exact->concat($acoustic)->sortByDesc('score')->values();
    }

    /**
     * Earlier fingerprints sharing the most codes with this one.
     *
     * @param  array<int, int>  $codes
     * @param  array<int, int>  $skip
     * @return array<int, int>
     */
    protected function candidateIds(AudioFingerprint $fingerprint, array $codes, array $skip): array
    {
        $distinct = array_values(array_unique($codes));

        return DB::table('audio_fingerprint_keys as k')
            ->join('audio_fingerprints as f', 'f.id', '=', 'k.audio_fingerprint_id')
            ->where('f.model_type', $fingerprint->model_type)
            ->where('f.id', '<', $fingerprint->id)
            ->whereNotIn('f.id', $skip)
            ->whereIn('k.code', $distinct)
            ->groupBy('k.audio_fingerprint_id')
            ->havingRaw('COUNT(*) >= ?', [max(5, (int) (count($distinct) * 0.05))])
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::CANDIDATES)
            ->pluck('k.audio_fingerprint_id')
            ->all();
    }

    /**
     * How alike two code lists are, from 0 to 1, at the offset where the most
     * codes line up. Unrelated audio scores about 0.5.
     *
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    public function similarity(array $a, array $b): float
    {
        $positions = [];
        foreach ($b as $j => $code) {
            $positions[$code][] = $j;
        }

        $offsets = [];
        foreach ($a as $i => $code) {
            foreach ($positions[$code] ?? [] as $j) {
                $offsets[$j - $i] = ($offsets[$j - $i] ?? 0) + 1;
            }
        }

        arsort($offsets);
        $minOverlap = min(self::MIN_OVERLAP, (int) (0.8 * min(count($a), count($b))));
        $best = 0.0;

        foreach (array_slice($offsets, 0, 5, true) as $offset => $hits) {
            $bits = 0;
            $overlap = 0;

            foreach ($a as $i => $code) {
                if (isset($b[$i + $offset])) {
                    $bits += $this->popcount($code ^ $b[$i + $offset]);
                    $overlap++;
                }
            }

            if ($overlap >= $minOverlap && $overlap > 0) {
                $best = max($best, 1 - $bits / (32 * $overlap));
            }
        }

        return round($best, 4);
    }

    protected function popcount(int $value): int
    {
        $value &= 0xFFFFFFFF;
        $value -= ($value >> 1) & 0x55555555;
        $value = ($value & 0x33333333) + (($value >> 2) & 0x33333333);
        $value = ($value + ($value >> 4)) & 0x0F0F0F0F;

        return (($value * 0x01010101) & 0xFFFFFFFF) >> 24;
    }
}
