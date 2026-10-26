<?php

namespace App\Services\Account;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Validates and saves who a user is (account type and identities) and the
 * genres and topics they want more of.
 */
class Interests
{
    /**
     * How many genres one account can pick.
     */
    public const MAX_GENRES = 20;

    /**
     * How many topics one account can pick.
     */
    public const MAX_TOPICS = 30;

    /**
     * Rules for the account type and identities; `$required` makes the type required.
     *
     * @return array<string, mixed>
     */
    public static function identityRules(?string $type, bool $required = false): array
    {
        $accountType = AccountType::tryFrom((string) $type);

        return [
            'account_type' => [$required ? 'required' : 'sometimes', Rule::enum(AccountType::class)],
            'identities' => ['nullable', 'array', 'max:' . AccountType::MAX_IDENTITIES],
            'identities.*' => ['string', 'distinct', Rule::in(array_keys($accountType?->identities() ?? []))],
        ];
    }

    /**
     * Rules for the picked genres and topics.
     *
     * @return array<string, mixed>
     */
    public static function pickRules(): array
    {
        return [
            'genres' => ['sometimes', 'array', 'max:' . self::MAX_GENRES],
            'genres.*' => ['integer', 'distinct', 'exists:genres,id'],
            'topics' => ['sometimes', 'array', 'max:' . self::MAX_TOPICS],
            'topics.*' => ['integer', 'distinct', 'exists:topics,id'],
        ];
    }

    /**
     * Whether the values pick nothing while saving picks: at least one
     * genre or topic is needed.
     *
     * @param  array<string, mixed>  $values
     */
    public static function picksNothing(array $values): bool
    {
        return (Arr::has($values, 'genres') || Arr::has($values, 'topics'))
            && empty($values['genres'] ?? [])
            && empty($values['topics'] ?? []);
    }

    /**
     * Save validated values. Genres or topics mark the interests as set.
     *
     * @param  array{account_type?: string, identities?: list<string>|null, genres?: list<int>, topics?: list<int>}  $values
     */
    public static function apply(User $user, array $values): User
    {
        if (Arr::has($values, 'account_type')) {
            $user->account_type = AccountType::from($values['account_type']);
        }

        if (Arr::has($values, 'identities')) {
            $user->identities = array_values($values['identities'] ?? []);
        }

        if ($user->isDirty('account_type') && ! Arr::has($values, 'identities')) {
            $allowed = array_keys($user->account_type->identities());

            $user->identities = array_values(array_intersect($user->identities ?? [], $allowed));
        }

        if (Arr::has($values, 'genres')) {
            $user->interests()->sync($values['genres']);
            $user->interests_at = now();
        }

        if (Arr::has($values, 'topics')) {
            $user->topics()->sync($values['topics']);
            $user->interests_at = now();
        }

        $user->save();

        return $user;
    }
}
