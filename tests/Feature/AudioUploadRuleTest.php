<?php

namespace Tests\Feature;

use App\Http\Controllers\SoundController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AudioUploadRuleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  array<int, string>  $exts
     */
    protected function allow(array $exts): void
    {
        DB::table('configurations')
            ->where('key', 'allowed_audio_exts')
            ->update(['value' => json_encode($exts)]);

        Cache::forget('configuration::build');
    }

    protected function tearDown(): void
    {
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    public function test_m4a_is_allowed_by_default(): void
    {
        $this->assertStringContainsString('m4a', SoundController::audioRule());
    }

    public function test_m4a_also_takes_files_detected_as_mp4(): void
    {
        $this->allow(['.wav', '.M4A ']);

        $this->assertSame('mimes:wav,m4a,mp4', SoundController::audioRule());
    }

    public function test_mp4_stays_out_unless_m4a_is_allowed(): void
    {
        $this->allow(['.wav', '.mp3']);

        $this->assertSame('mimes:wav,mp3', SoundController::audioRule());
    }
}
