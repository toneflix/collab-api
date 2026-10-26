<?php

namespace App\Services\Settings;

use App\Models\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Toneflix\LaravelFileable\Media;

/**
 * The layout, banner and every piece of text on the sign-in, sign-up, password and verification pages.
 *
 * Admins change them on the Auth pages admin page. They are stored as overrides in the `auth_pages`
 * configuration row; anything left empty falls back to the defaults below.
 */
class AuthPages
{
    public const KEY = 'auth_pages';

    public const MEDIA = 'auth_banner';

    public const CACHE = 'auth_pages::client';

    /**
     * Width of the form and the banner on tablets and desktops, in percent.
     */
    public const LAYOUTS = [
        '45-55' => ['Balanced', 45, 55],
        '40-60' => ['Wide banner', 40, 60],
        '50-50' => ['Even split', 50, 50],
        '60-40' => ['Wide form', 60, 40],
        'form' => ['Form only', 100, 0],
    ];

    public const DEFAULT_LAYOUT = '45-55';

    public const PAGES = [
        'banner' => 'Banner',
        'login' => 'Sign in',
        'register' => 'Sign up',
        'forgot' => 'Forgot password',
        'reset' => 'Reset password',
        'verify' => 'Verification',
        'interests' => 'Interests',
    ];

    /**
     * page => key => [label, default]. `:email`, `:target` and `:seconds` are filled in by the app.
     */
    public const TEXTS = [
        'banner' => [
            'eyebrow' => ['Eyebrow', 'Toneflix Music'],
            'title' => ['Heading', 'Where music meets community.'],
            'text' => ['Text', 'Stream new music, discover artists and make tracks with other creators.'],
            'point_1' => ['Point 1', 'Discover new artists and sounds'],
            'point_2' => ['Point 2', 'Build tracks together in the studio'],
            'point_3' => ['Point 3', 'Release your music and get paid'],
        ],
        'login' => [
            'title' => ['Heading', 'Welcome back'],
            'subtitle' => ['Subheading', 'Sign in to pick up your projects and the music you follow.'],
            'email_label' => ['Email label', 'Email address'],
            'email_placeholder' => ['Email placeholder', 'you@example.com'],
            'password_label' => ['Password label', 'Password'],
            'password_placeholder' => ['Password placeholder', 'Your password'],
            'remember' => ['Remember me', 'Keep me signed in'],
            'forgot_link' => ['Forgot password link', 'Forgot password?'],
            'submit' => ['Button', 'Sign in'],
            'footer_text' => ['Footer text', 'New to Toneflix Music?'],
            'footer_link' => ['Footer link', 'Create an account'],
        ],
        'register' => [
            'step_type' => ['Step 1 name', 'Account'],
            'step_identity' => ['Step 2 name', 'About you'],
            'step_access' => ['Step 3 name', 'Sign-in'],
            'title' => ['Step 1 heading', 'Join Toneflix Music'],
            'subtitle' => ['Step 1 subheading', 'How will you use it? You can change this later.'],
            'creator_title' => ['Creator option', 'Creator'],
            'creator_text' => ['Creator option text', 'I make music and want to release, collaborate and get paid.'],
            'listener_title' => ['Listener option', 'Listener'],
            'listener_text' => ['Listener option text', "I'm here to stream, discover and support artists."],
            'identity_title' => ['Step 2 heading', 'Tell us about you'],
            'identity_subtitle' => ['Step 2 subheading', 'Your name and what you do.'],
            'identities_creator_label' => ['Creator roles label', 'What do you do?'],
            'identities_listener_label' => ['Listener roles label', 'What describes you?'],
            'identities_hint' => ['Roles hint', 'Pick any that fit.'],
            'access_title' => ['Step 3 heading', 'Set up your sign-in'],
            'access_subtitle' => ['Step 3 subheading', "You'll use these to sign in."],
            'next' => ['Next button', 'Continue'],
            'back' => ['Back button', 'Back'],
            'firstname_label' => ['First name label', 'First name'],
            'firstname_placeholder' => ['First name placeholder', 'Ada'],
            'lastname_label' => ['Last name label', 'Last name'],
            'lastname_placeholder' => ['Last name placeholder', 'Okafor'],
            'email_label' => ['Email label', 'Email address'],
            'email_placeholder' => ['Email placeholder', 'you@example.com'],
            'phone_label' => ['Phone label', 'Phone number (optional)'],
            'phone_placeholder' => ['Phone placeholder', '+234 801 234 5678'],
            'password_label' => ['Password label', 'Password'],
            'password_placeholder' => ['Password placeholder', 'At least 8 characters'],
            'password_confirm_label' => ['Confirm password label', 'Confirm password'],
            'password_confirm_placeholder' => ['Confirm password placeholder', 'Type it again'],
            'terms_before' => ['Terms text', 'I agree to the'],
            'terms_link' => ['Terms link', 'Terms of Service'],
            'terms_between' => ['Text between links', 'and'],
            'privacy_link' => ['Privacy link', 'Privacy Policy'],
            'submit' => ['Button', 'Create account'],
            'footer_text' => ['Footer text', 'Already have an account?'],
            'footer_link' => ['Footer link', 'Sign in'],
        ],
        'forgot' => [
            'title' => ['Heading', 'Forgot your password?'],
            'subtitle' => ['Subheading', "Enter your email and we'll send you a code to reset it."],
            'email_label' => ['Email label', 'Email address'],
            'email_placeholder' => ['Email placeholder', 'you@example.com'],
            'submit' => ['Button', 'Send code'],
            'footer_text' => ['Footer text', 'Remembered it?'],
            'footer_link' => ['Footer link', 'Sign in'],
        ],
        'reset' => [
            'title' => ['Heading', 'Reset your password'],
            'subtitle' => ['Subheading', 'Enter the code we sent to :email.'],
            'code_label' => ['Code label', 'Reset code'],
            'code_submit' => ['Code button', 'Continue'],
            'new_code' => ['New code link', 'Send a new code'],
            'password_title' => ['New password heading', 'Choose a new password'],
            'password_label' => ['Password label', 'New password'],
            'password_placeholder' => ['Password placeholder', 'At least 8 characters'],
            'password_confirm_label' => ['Confirm password label', 'Confirm new password'],
            'password_confirm_placeholder' => ['Confirm password placeholder', 'Type it again'],
            'submit' => ['Button', 'Set new password'],
            'done_title' => ['Done heading', 'Password changed'],
            'done_text' => ['Done text', 'You can now sign in with your new password.'],
            'done_button' => ['Done button', 'Sign in'],
            'footer_text' => ['Footer text', 'Remembered it?'],
            'footer_link' => ['Footer link', 'Sign in'],
        ],
        'verify' => [
            'email_title' => ['Email heading', 'Confirm your email'],
            'phone_title' => ['Phone heading', 'Confirm your phone number'],
            'subtitle' => ['Subheading', 'Enter the code we sent to :target.'],
            'submit' => ['Button', 'Verify'],
            'resend' => ['Resend link', 'Send a new code'],
            'resend_wait' => ['Resend countdown', 'Send a new code in :seconds s'],
            'done_title' => ['Done heading', "You're all set"],
            'done_text' => ['Done text', 'Thanks for confirming.'],
            'done_button' => ['Done button', 'Continue'],
            'switch_account' => ['Sign out link', 'Use another account'],
        ],
        'interests' => [
            'title' => ['Heading', 'What are you into?'],
            'subtitle' => ['Subheading', "Pick genres and topics and we'll shape your playlists around them."],
            'genres_label' => ['Genres heading', 'Genres'],
            'topics_label' => ['Topics heading', 'Topics'],
            'add_topic' => ['Add topic button', 'Add your own'],
            'add_topic_placeholder' => ['Add topic placeholder', 'Name a topic'],
            'show_more' => ['Show more link', 'Show more'],
            'show_less' => ['Show less link', 'Show less'],
            'picked' => ['Picked count', ':count picked'],
            'submit' => ['Button', 'Save and continue'],
            'skip' => ['Skip link', 'Skip for now'],
        ],
    ];

    /**
     * What the app needs to draw the pages.
     *
     * @return array{layout: string, split: array{form: int, banner: int}, banner: ?string, texts: array<string, array<string, string>>}
     */
    public static function clientConfig(): array
    {
        return Cache::rememberForever(self::cacheKey(), fn() => self::build());
    }

    /**
     * The cache key, which changes with the default texts so a deploy that
     * adds or edits one is picked up without clearing the cache.
     */
    public static function cacheKey(): string
    {
        return self::CACHE . ':' . md5(serialize([self::TEXTS, self::LAYOUTS]));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function build(): array
    {
        $stored = self::stored();
        $layout = self::LAYOUTS[$stored['layout']] ?? self::LAYOUTS[self::DEFAULT_LAYOUT];

        $texts = collect(self::TEXTS)
            ->map(fn(array $fields, string $page) => collect($fields)
                ->map(fn(array $field, string $key) => $stored['texts']["{$page}.{$key}"] ?? $field[1])
                ->all())
            ->all();

        return [
            'layout' => array_key_exists($stored['layout'], self::LAYOUTS) ? $stored['layout'] : self::DEFAULT_LAYOUT,
            'split' => ['form' => $layout[1], 'banner' => $layout[2]],
            'banner' => self::bannerUrl($stored['banner']),
            'texts' => $texts,
        ];
    }

    /**
     * The editable fields with their defaults and saved overrides.
     *
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        $stored = self::stored();

        $layouts = collect(self::LAYOUTS)
            ->map(fn(array $layout, string $key) => ['key' => $key, 'title' => $layout[0], 'form' => $layout[1], 'banner' => $layout[2]])
            ->values()
            ->all();

        $pages = collect(self::PAGES)
            ->map(fn(string $title, string $page) => [
                'key' => $page,
                'title' => $title,
                'fields' => collect(self::TEXTS[$page])
                    ->map(fn(array $field, string $key) => [
                        'key' => "{$page}.{$key}",
                        'label' => $field[0],
                        'default' => $field[1],
                        'value' => $stored['texts']["{$page}.{$key}"] ?? null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'layout' => $stored['layout'],
            'layouts' => $layouts,
            'banner' => self::bannerUrl($stored['banner']),
            'pages' => $pages,
        ];
    }

    /**
     * Save the layout and the text overrides. Empty texts go back to their defaults.
     *
     * @param  array<string, mixed>  $input
     */
    public static function update(array $input): void
    {
        $valid = Validator::make($input, [
            'layout' => ['sometimes', Rule::in(array_keys(self::LAYOUTS))],
            'texts' => ['sometimes', 'array'],
            'texts.*' => ['nullable', 'string', 'max:300'],
        ])->validate();

        $stored = self::stored();
        $keys = self::textKeys();

        if (isset($valid['layout'])) {
            $stored['layout'] = $valid['layout'];
        }

        foreach ($valid['texts'] ?? [] as $key => $value) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            if (blank($value)) {
                unset($stored['texts'][$key]);
            } else {
                $stored['texts'][$key] = trim($value);
            }
        }

        self::save($stored);
    }

    /**
     * Replace the banner image, or remove it when no file is given.
     */
    public static function setBanner(?UploadedFile $file): void
    {
        $stored = self::stored();

        if ($file) {
            $stored['banner'] = (new Media)->save(self::MEDIA, $file, $stored['banner']);
        } elseif ($stored['banner']) {
            (new Media)->delete(self::MEDIA, $stored['banner']);
            $stored['banner'] = null;
        }

        self::save($stored);
    }

    /**
     * @param  array{layout: string, banner: ?string, texts: array<string, string>}  $stored
     */
    protected static function save(array $stored): void
    {
        $configuration = Configuration::query()
            ->firstOrNew(['key' => self::KEY]);

        if (! $configuration->exists) {
            $configuration->forceFill(['key' => self::KEY, 'type' => 'json', 'title' => 'Auth pages', 'secret' => true]);
        }

        $configuration->value = $stored;
        $configuration->save();

        Cache::forget('configuration::build');
        Cache::forget(self::cacheKey());
    }

    /**
     * @return list<string>
     */
    public static function textKeys(): array
    {
        return collect(self::TEXTS)
            ->flatMap(fn(array $fields, string $page) => array_map(fn(string $key) => "{$page}.{$key}", array_keys($fields)))
            ->values()
            ->all();
    }

    /**
     * @return array{layout: string, banner: ?string, texts: array<string, string>}
     */
    protected static function stored(): array
    {
        $value = Configuration::query()
            ->where('key', self::KEY)
            ->first()
            ?->value;

        return [
            'layout' => $value['layout'] ?? self::DEFAULT_LAYOUT,
            'banner' => $value['banner'] ?? null,
            'texts' => $value['texts'] ?? [],
        ];
    }

    protected static function bannerUrl(?string $file): ?string
    {
        return $file ? (new Media)->getMedia(self::MEDIA, $file) : null;
    }
}
