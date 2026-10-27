<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phones record and share audio as .m4a, so uploads take it too. Adds it to
     * the lists admins already have, keeping whatever else they changed.
     */
    public function up(): void
    {
        $this->change(fn(array $exts) => in_array('.m4a', $exts, true) ? $exts : [...$exts, '.m4a']);
    }

    public function down(): void
    {
        $this->change(fn(array $exts) => array_values(array_diff($exts, ['.m4a'])));
    }

    /**
     * @param  callable(array<int, string>): array<int, string>  $update
     */
    protected function change(callable $update): void
    {
        foreach (['allowed_audio_exts', 'allowed_assets_exts'] as $key) {
            $value = DB::table('configurations')
                ->where('key', $key)
                ->value('value');

            if ($value === null) {
                continue;
            }

            $exts = array_values((array) (json_decode((string) $value, true) ?: []));
            $changed = json_encode($update($exts));

            DB::table('configurations')
                ->where('key', $key)
                ->update(['value' => $changed]);
        }

        Cache::forget('configuration::build');
    }
};
