<?php

namespace App\Services\Ai\Music;

use App\Services\Ai\AiSettings;

/**
 * A music generation model on Replicate: what it can do, what a song costs
 * in credits and how the studio's form becomes the model's inputs.
 */
abstract class MusicModel
{
    /**
     * Every model admins can switch to, in menu order.
     *
     * @var list<class-string<MusicModel>>
     */
    public const MODELS = [
        ElevenLabsMusic::class,
        MiniMaxMusic25::class,
        MiniMaxMusic15::class,
        StableAudio25::class,
    ];

    /** Credits are charged for every 10 seconds asked for */
    public const PER_10_SECONDS = 'per10s';

    /** Credits are charged once per song */
    public const PER_SONG = 'song';

    /** The model on Replicate, "owner/name" */
    public const ID = '';

    public const NAME = '';

    /** One line for admins on what the model is good at */
    public const DESCRIPTION = '';

    /** The admin setting that holds its credit price */
    public const CREDITS_SETTING = '';

    public const PRICING = self::PER_SONG;

    /** Shortest reference audio the model takes, in seconds */
    public const REFERENCE_MIN_SECONDS = 15;

    /**
     * @return array<string, MusicModel>
     */
    public static function all(): array
    {
        return collect(self::MODELS)
            ->mapWithKeys(fn(string $class) => [$class::ID => new $class])
            ->all();
    }

    public static function find(?string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    public function name(): string
    {
        return static::NAME;
    }

    /**
     * The longest track it makes on request, or null when the model picks
     * the length itself.
     */
    public function maxSeconds(): ?int
    {
        return null;
    }

    /**
     * Shortest and longest prompt, in characters.
     *
     * @return array{0: int, 1: int}
     */
    public function promptLength(): array
    {
        return [3, 2000];
    }

    /**
     * Shortest and longest lyrics, or null when the model doesn't take any.
     * Lyrics are required when given.
     *
     * @return array{0: int, 1: int}|null
     */
    public function lyricsLength(): ?array
    {
        return null;
    }

    /**
     * Whether people can ask for no vocals.
     */
    public function instrumentalOption(): bool
    {
        return false;
    }

    /**
     * Whether it only makes instrumentals.
     */
    public function instrumentalOnly(): bool
    {
        return false;
    }

    /**
     * Kinds of reference audio it takes: song, voice or instrumental.
     *
     * @return list<string>
     */
    public function references(): array
    {
        return [];
    }

    /**
     * Credits per song, or per 10 seconds, as admins set it.
     */
    public function rate(): int
    {
        return max(1, (int) AiSettings::get(static::CREDITS_SETTING));
    }

    /**
     * Credits a song of this many seconds uses.
     */
    public function credits(int $seconds): int
    {
        return static::PRICING === self::PER_10_SECONDS
            ? max(1, (int) ceil($seconds / 10 * $this->rate()))
            : $this->rate();
    }

    /**
     * Validation rules for the studio's music form.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        [$min, $max] = $this->promptLength();
        $lyrics = $this->lyricsLength();

        return array_filter([
            'prompt' => ['required', 'string', "min:{$min}", "max:{$max}"],
            'seconds' => $this->maxSeconds()
                ? ['required', 'integer', 'min:3', 'max:' . min($this->maxSeconds(), AiSettings::musicMaxSeconds())]
                : ['nullable', 'integer'],
            'lyrics' => $lyrics ? ['required', 'string', "min:{$lyrics[0]}", "max:{$lyrics[1]}"] : null,
            'instrumental' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'reference_kind' => $this->references() ? ['nullable', 'string', 'in:' . implode(',', $this->references())] : null,
        ]);
    }

    /**
     * The model's inputs for a job's input, on top of the prompt. A reference
     * is the URL of audio the model can fetch.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract public function input(array $input, ?string $reference = null): array;

    /**
     * What the studio needs to show the model's form and its cost.
     *
     * @return array<string, mixed>
     */
    public function toClient(): array
    {
        return [
            'id' => static::ID,
            'name' => $this->name(),
            'description' => static::DESCRIPTION,
            'pricing' => static::PRICING,
            'credits' => $this->rate(),
            'creditsSetting' => static::CREDITS_SETTING,
            'maxSeconds' => $this->maxSeconds() ? min($this->maxSeconds(), AiSettings::musicMaxSeconds()) : null,
            'promptLength' => $this->promptLength(),
            'lyricsLength' => $this->lyricsLength(),
            'instrumentalOption' => $this->instrumentalOption(),
            'instrumentalOnly' => $this->instrumentalOnly(),
            'references' => $this->references(),
            'referenceMinSeconds' => static::REFERENCE_MIN_SECONDS,
        ];
    }
}
