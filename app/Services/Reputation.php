<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\User;

class Reputation
{
    /**
     * @var int
     */
    public $amount = 0;

    /**
     * Create a new class instance.
     */
    public function __construct(
        public User|Artist $user,
        public string $type
    ) {
        $this->amount = config("settings.reputation.$type");

        if ($this->amount === null) {
            throw new \Exception('Invalid reputation signature', 1);
        }
    }

    public function add(?int $requirement = null): void
    {
        if ($requirement === null || $requirement >= config('settings.delete_reputation_cost', 0)) {
            $this->user->increment('reputation', $this->amount);
        }
    }

    public function remove(): void
    {
        $this->user->decrement('reputation', $this->amount);
    }
}
