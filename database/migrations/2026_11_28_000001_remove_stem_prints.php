<?php

use App\Models\ProjectTrackStem;
use App\Services\Studio\StemFiles;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Delete the audio the studio used to upload for stems with plugins, now
     * that it uploads the whole mix instead.
     */
    public function up(): void
    {
        $files = app(StemFiles::class);

        ProjectTrackStem::withTrashed()
            ->whereNotNull('meta->print')
            ->chunkById(100, function ($stems) use ($files) {
                foreach ($stems as $stem) {
                    $meta = collect($stem->meta);

                    $files->delete($meta['print']['file'] ?? null);

                    $stem->meta = $meta
                        ->except('print')
                        ->all();
                    $stem->saveQuietly();
                }
            });
    }

    public function down(): void {}
};
