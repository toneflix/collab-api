<?php

namespace App\Http\Resources;

use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

class StreamCheckCollection extends ResourceCollection
{
    public $collects = StreamCheckResource::class;
}
