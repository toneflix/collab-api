<?php

namespace App\Http\Resources;

use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

class ReferredUserCollection extends ResourceCollection
{
    public $collects = ReferredUserResource::class;
}
