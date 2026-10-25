<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an admin did to a user's account, kept as an audit trail.
 */
class AdminAction extends Model
{
    protected $fillable = [
        'admin_id',
        'user_id',
        'action',
        'reason',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this
            ->belongsTo(User::class, 'admin_id')
            ->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
