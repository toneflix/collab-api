<?php

namespace App\Services\Ai\Replicate;

use App\Services\Ai\AiJobStopped;
use App\Services\Ai\AiJobStops;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs many predictions a few at a time and waits for all of them, so one
 * job can make dozens of clips without waiting on each in turn.
 *
 * @phpstan-import-type Prediction from ReplicateClient
 */
class ReplicateBatch
{
    /** Seconds between checks on running predictions */
    protected const POLL = 3;

    public function __construct(
        protected ReplicateClient $client,
        protected AiJobStops $stops,
    ) {}

    public static function make(): self
    {
        return new self(ReplicateClient::make(), app(AiJobStops::class));
    }

    /**
     * Run each model with its input, at most `$parallel` at once. Each
     * result is the finished prediction, or what went wrong with it.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{0: string, 1: array<string, mixed>}>  $runs
     * @return array<TKey, Prediction|Throwable>
     */
    public function run(array $runs, int $parallel, int $timeout): array
    {
        $pending = $runs;
        $active = [];
        $results = [];

        while ($pending || $active) {
            while ($pending && count($active) < $parallel) {
                $key = array_key_first($pending);
                [$model, $input] = $pending[$key];
                unset($pending[$key]);

                try {
                    $prediction = $this->client->create($model, $input);
                    $active[$key] = ['prediction' => $prediction, 'model' => $model, 'deadline' => now()->addSeconds($timeout)];
                } catch (Throwable $e) {
                    $results[$key] = $e;
                }
            }

            try {
                $this->stops->check();
            } catch (AiJobStopped $e) {
                foreach ($active as $run) {
                    $this->client->cancel($run['prediction']['id']);
                }

                throw $e;
            }

            foreach ($active as $key => $run) {
                $result = $this->check($run);

                if ($result !== null) {
                    $results[$key] = $result;
                    unset($active[$key]);
                }
            }

            if ($active) {
                Sleep::for(self::POLL)->seconds();

                foreach ($active as $key => $run) {
                    try {
                        $active[$key]['prediction'] = $this->client->prediction($run['prediction']['id']);
                    } catch (Throwable $e) {
                        $results[$key] = $e;
                        unset($active[$key]);
                    }
                }
            }
        }

        return array_replace(array_intersect_key($runs, $results), $results);
    }

    /**
     * The finished prediction or its error, or null while it still runs.
     *
     * @param  array{prediction: Prediction, model: string, deadline: Carbon}  $run
     * @return Prediction|Throwable|null
     */
    protected function check(array $run): array|Throwable|null
    {
        $prediction = $run['prediction'];

        if ($prediction['status'] === 'succeeded') {
            return $prediction;
        }

        if (in_array($prediction['status'], ['failed', 'canceled'], true)) {
            return new ReplicateException($prediction['error'] ?: "{$run['model']} {$prediction['status']}.");
        }

        if (now()->greaterThan($run['deadline'])) {
            $this->client->cancel($prediction['id']);

            return new ReplicateException("{$run['model']} took too long.");
        }

        return null;
    }
}
