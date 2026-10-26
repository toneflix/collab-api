<?php

namespace App\Services\Account;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Validates and saves who a user is (account type and identities) and the
 * genres they want to hear more of.
 */
class Interests
{
    /**
     * How many genres one account can pick.
     */
    public const MAX_GENRES = 20;

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
     * Rules for the picked genres.
     *
     * @return array<string, mixed>
     */
    public static function genreRules(): array
    {
        return [
            'genres' => ['sometimes', 'array', 'min:1', 'max:' . self::MAX_GENRES],
            'genres.*' => ['integer', 'distinct', 'exists:genres,id'],
        ];
    }

    /**
     * Save validated values. Genres mark the interests as set.
     *
     * @param  array{account_type?: string, identities?: list<string>|null, genres?: list<int>}  $values
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

        $user->save();

        return $user;
    }
}
