<?php

namespace App\Enums;

/**
 * How a released track was made. It ranks the track for stream earnings:
 * collaboration first, then recorded music, then agentic.
 */
enum TrackOrigin: string
{
    /** Made in a studio project where a collaborator added a stem */
    case COLLABORATION = 'collaboration';

    /** Recorded or produced by people, without AI-generated audio taking most of it */
    case RECORDED = 'recorded';

    /** Built by the content agent or mostly from AI-generated audio */
    case AGENTIC = 'agentic';

    public function title(): string
    {
        return match ($this) {
            self::COLLABORATION => 'Collaboration',
            self::RECORDED => 'Recorded music',
            self::AGENTIC => 'Agentic',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
