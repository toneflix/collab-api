<?php

namespace App\Enums;

/**
 * The kinds of activity people can be told about outside the app, each with
 * its own email and push switches.
 */
enum NotificationCategory: string
{
    case COLLABS = 'collabs';
    case MESSAGES = 'messages';
    case SALES = 'sales';
    case PAYOUTS = 'payouts';

    public function title(): string
    {
        return match ($this) {
            self::COLLABS => 'Collabs',
            self::MESSAGES => 'Messages',
            self::SALES => 'Sales and tips',
            self::PAYOUTS => 'Payouts',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::COLLABS => 'Invitations and requests to join projects, and answers to yours.',
            self::MESSAGES => 'New messages in your conversations that you haven\'t read yet.',
            self::SALES => 'Someone bought your release or stems, or sent you a tip.',
            self::PAYOUTS => 'Updates on money you withdraw.',
        };
    }
}
