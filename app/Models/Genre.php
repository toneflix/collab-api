<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Genre extends Model
{
    use Fileable, HasFactory;

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'artwork',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });
    }
}
