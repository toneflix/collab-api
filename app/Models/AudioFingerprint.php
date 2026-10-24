<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The file hash and Chromaprint codes of a piece of audio. Its distinct codes
 * are kept in audio_fingerprint_keys so likely matches can be found in SQL.
 *
 * @property string $hash
 * @property float $duration
 * @property string|null $codes
 */
class AudioFingerprint extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'hash',
        'duration',
        'codes',
    ];

    protected $hidden = ['codes'];

    protected function casts(): array
    {
        return ['duration' => 'float'];
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<int, int>
     */
    public function codeList(): array
    {
        if (! $this->codes) {
            return [];
        }

        return array_values(unpack('V*', base64_decode($this->codes)) ?: []);
    }

    /**
     * @param  array<int, int>  $codes
     */
    public static function packCodes(array $codes): ?string
    {
        return $codes ? base64_encode(pack('V*', ...$codes)) : null;
    }
}
