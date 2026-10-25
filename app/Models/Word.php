<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dictionary word with its pronunciation, the keys used to find rhymes and
 * its rank (10 for the most common English words, 99 for rare ones).
 */
class Word extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'word',
        'phones',
        'syllables',
        'rank',
        'stress',
        'rhyme_key',
        'vowel_key',
        'tail2',
        'tail3',
    ];

    protected function casts(): array
    {
        return [
            'syllables' => 'integer',
            'rank' => 'integer',
        ];
    }
}
