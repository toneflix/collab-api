<?php

namespace App\Http\Resources;

use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

class StreamTrackCollection extends ResourceCollection
{
    public $collects = StreamTrackResource::class;
}
