<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StemMeta implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array  $arguments
     * @return \Illuminate\Contracts\Database\Eloquent\CastsAttributes<\Illuminate\Support\Collection<array-key, mixed>, iterable>
     */
    public static function castUsing(array $arguments)
    {
        return new class($arguments) implements CastsAttributes
        {
            public function __construct(protected array $arguments)
            {
            }

            public function get(Model $model, $key, $value, $attributes)
            {
                if (!isset($attributes[$key])) {
                    return;
                }

                $data = Json::decode($attributes[$key]);

                $collectionClass = $this->arguments[0] ?? Collection::class;

                if (!is_a($collectionClass, Collection::class, true)) {
                    throw new InvalidArgumentException('The provided class must extend [' . Collection::class . '].');
                }

                $output = is_array($data) ? new $collectionClass($data) : null;

                if ($output) {
                    $output = $output->merge([
                        'src' => $model->mediaFileInfo['src']['dynamicLink'],
                        'end' =>  isset($output['end']) ? (float) $output['end'] :  null,
                        'gain' =>  isset($output['gain']) ? (float) $output['gain'] :  null,
                        'title' =>  $model->title ?? '',
                        'muted' =>  isset($output['muted']) ? filter_var($output['muted'], FILTER_VALIDATE_BOOL) :  null,
                        'start' =>  isset($output['start']) ? (float) $output['start'] :  null,
                        'cuein' =>  isset($output['cuein']) ? (float) $output['cuein'] :  null,
                        'cueout' =>  isset($output['cueout']) && $output['cueout'] > 0 ? (float) $output['cueout'] :  null,
                        'soloed' =>  isset($output['soloed']) ? filter_var($output['soloed'], FILTER_VALIDATE_BOOL) :  null,
                        'duration' =>  isset($output['duration']) ? (float) $output['duration'] :  null,
                        'stereoPan' =>  isset($output['stereoPan']) ? (float) $output['stereoPan'] :  null,
                    ])->filter(fn ($v, $k) => !str($k)->startsWith(':') && $v !== null);

                    if (isset($output['fadeIn']['duration'])) {
                        $output = $output->merge([
                            'fadeIn' => [
                                'shape' => $output['fadeIn']['shape'],
                                'duration' => (float) $output['fadeIn']['duration']
                            ]
                        ]);
                    }

                    if (isset($output['fadeOut']['duration'])) {
                        $output = $output->merge([
                            'fadeOut' => [
                                'shape' => $output['fadeOut']['shape'],
                                'duration' => (float) $output['fadeOut']['duration']
                            ]
                        ]);
                    }

                    if (isset($output['enabledStates'])) {
                        $output = $output->merge([
                            'enabledStates' => collect($output['enabledStates'])
                                ->map(fn ($state) => filter_var($state, FILTER_VALIDATE_BOOL))
                                ->toArray()
                        ]);
                    }
                }

                return $output;
            }

            public function set(Model $model, $key, $value, $attributes)
            {
                if (is_array($value)) {
                    unset($value['src']);
                }

                return [$key => Json::encode($value)];
            }
        };
    }

    /**
     * Specify the collection for the cast.
     *
     * @param  class-string  $class
     * @return string
     */
    public static function using($class)
    {
        return static::class . ':' . $class;
    }
}