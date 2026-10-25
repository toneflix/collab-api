<?php

namespace App\Jobs;

use App\Models\AiReview;
use App\Services\Moderation\Ai\AiModerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lets the AI moderator review one queued claim or release.
 */
class RunAiReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $reviewId) {}

    public function handle(AiModerator $moderator): void
    {
        $review = AiReview::query()->find($this->reviewId);

        if ($review) {
            $moderator->run($review);
        }
    }

    /**
     * A job that died, for example on a timeout, must not leave the review
     * stuck in the queue.
     */
    public function failed(?\Throwable $e): void
    {
        AiReview::query()->whereKey($this->reviewId)->queued()->update([
            'status' => AiReview::FAILED,
            'error' => str($e?->getMessage() ?? 'The review stopped before it finished.')->limit(500)->toString(),
            'updated_at' => now(),
        ]);
    }
}
