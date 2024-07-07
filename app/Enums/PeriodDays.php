<?php

namespace App\Enums;

/**
 * HTTP Status codes.
 */
enum PeriodDays: int
{
    case DAY = 1;
    case WEEK = 7;
    case MONTH = 30;
    case YEAR = 365;
    case DAYS_3 = 3;
    case WEEKS_2 = self::WEEK->value * 2;
    case MONTHS_3 = self::MONTH->value * 3;
    case YEARS_2 = self::YEAR->value * 2;
}
