<?php

namespace App\Enums;

/**
 * The 24 major and minor keys, named the way musicians usually spell them.
 */
enum MusicalKey: string
{
    case C_MAJOR = 'C major';
    case DB_MAJOR = 'Db major';
    case D_MAJOR = 'D major';
    case EB_MAJOR = 'Eb major';
    case E_MAJOR = 'E major';
    case F_MAJOR = 'F major';
    case FS_MAJOR = 'F# major';
    case G_MAJOR = 'G major';
    case AB_MAJOR = 'Ab major';
    case A_MAJOR = 'A major';
    case BB_MAJOR = 'Bb major';
    case B_MAJOR = 'B major';
    case C_MINOR = 'C minor';
    case CS_MINOR = 'C# minor';
    case D_MINOR = 'D minor';
    case EB_MINOR = 'Eb minor';
    case E_MINOR = 'E minor';
    case F_MINOR = 'F minor';
    case FS_MINOR = 'F# minor';
    case G_MINOR = 'G minor';
    case GS_MINOR = 'G# minor';
    case A_MINOR = 'A minor';
    case BB_MINOR = 'Bb minor';
    case B_MINOR = 'B minor';

    /**
     * The key on a pitch class, 0 for C through 11 for B.
     */
    public static function fromPitch(int $pitch, bool $minor): self
    {
        $offset = $minor ? 12 : 0;

        return self::cases()[$offset + (($pitch % 12) + 12) % 12];
    }

    /**
     * A key from its full name, short name ("Am", "F#") or Camelot code ("8A").
     */
    public static function parse(?string $value): ?self
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (self::cases() as $key) {
            if (strcasecmp($key->value, $value) === 0 || $key->short() === $value || strcasecmp($key->camelot(), $value) === 0) {
                return $key;
            }
        }

        return null;
    }

    public function pitch(): int
    {
        return array_search($this, self::cases(), true) % 12;
    }

    public function minor(): bool
    {
        return str_ends_with($this->value, 'minor');
    }

    /**
     * Short name, like "Am" or "F#".
     */
    public function short(): string
    {
        return strtok($this->value, ' ') . ($this->minor() ? 'm' : '');
    }

    /**
     * The key's Camelot wheel code DJs mix by, like "8A" for A minor.
     */
    public function camelot(): string
    {
        // Steps of a fifth (7 semitones) move one number around the wheel.
        $number = $this->minor()
            ? (($this->pitch() - 8 + 12) * 7 % 12) + 1
            : (($this->pitch() - 11 + 12) * 7 % 12) + 1;

        return $number . ($this->minor() ? 'A' : 'B');
    }

    /**
     * Keys a DJ can mix into from this one: itself, its relative key and its
     * neighbours on the Camelot wheel.
     *
     * @return list<self>
     */
    public function compatible(): array
    {
        $number = (int) $this->camelot();
        $letter = $this->minor() ? 'A' : 'B';
        $codes = [
            $number . $letter,
            $number . ($letter === 'A' ? 'B' : 'A'),
            (($number + 10) % 12 + 1) . $letter,
            ($number % 12 + 1) . $letter,
        ];

        return array_map(fn(string $code) => self::parse($code), $codes);
    }
}
