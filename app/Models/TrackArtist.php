<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TrackArtist extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    public $table = 'track_artist';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'type' => 'primary',
    ];
}