<?php

namespace Tests\Unit;

use App\Services\Studio\Mix\MixStem;
use PHPUnit\Framework\TestCase;

class MixStemAudibleTest extends TestCase
{
    /**
     * @param  list<array{start: float, duration: float}>  $clips
     * @return list<array{0: int, 1: float, 2: float}>
     */
    protected function heard(array $clips): array
    {
        return array_map(
            fn(array $part) => [array_search($part['clip'], $clips, true), $part['from'], $part['to']],
            MixStem::audible($clips),
        );
    }

    public function test_a_later_clip_covers_the_middle_of_an_earlier_one(): void
    {
        $clips = [
            ['start' => 0.0, 'duration' => 4.0],
            ['start' => 1.0, 'duration' => 1.0],
        ];

        $this->assertSame([[0, 0.0, 1.0], [1, 1.0, 2.0], [0, 2.0, 4.0]], $this->heard($clips));
    }

    public function test_order_follows_the_start_not_the_saved_order(): void
    {
        $clips = [
            ['start' => 2.0, 'duration' => 2.0],
            ['start' => 0.0, 'duration' => 3.0],
        ];

        $this->assertSame([[1, 0.0, 2.0], [0, 2.0, 4.0]], $this->heard($clips));
    }

    public function test_clips_that_start_together_keep_their_saved_order(): void
    {
        $clips = [
            ['start' => 0.0, 'duration' => 2.0, 'id' => 'a'],
            ['start' => 0.0, 'duration' => 1.0, 'id' => 'b'],
        ];

        $this->assertSame([[1, 0.0, 1.0], [0, 1.0, 2.0]], $this->heard($clips));
    }

    public function test_a_clip_covered_whole_is_not_heard(): void
    {
        $clips = [
            ['start' => 1.0, 'duration' => 1.0],
            ['start' => 1.0, 'duration' => 3.0],
            ['start' => 5.0, 'duration' => 1.0],
        ];

        $this->assertSame([[1, 1.0, 4.0], [2, 5.0, 6.0]], $this->heard($clips));
    }
}
