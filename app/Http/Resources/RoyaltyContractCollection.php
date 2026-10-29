<?php

namespace App\Http\Resources;

use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

class RoyaltyContractCollection extends ResourceCollection
{
    public $collects = RoyaltyContractResource::class;
}
