<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @method \App\Models\User user() Get the authenticated user
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Get the authenticated user
     *
     * @\return \App\Models\User
     */
    // public \Closure $user;
    public ?User $user;

    public function __construct()
    {
        $this->user = auth('sanctum')->user();
        // $this->user = fn () => auth('sanctum')->user();
    }
}
