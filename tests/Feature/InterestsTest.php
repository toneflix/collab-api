<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Topic;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterestsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<int>
     */
    protected array $releases = [];

    /**
     * @return array<string, mixed>
     */
    protected function signUp(array $values = []): array
    {
        return [
            'firstname' => 'Ada',
            'lastname' => 'Okafor',
            'email' => 'signup-' . uniqid() . '@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept' => true,
            ...$values,
        ];
    }

    protected function makeRelease(Genre $genre, int $tracks = 1): Release
    {
        $release = Release::factory()
            ->has(Track::factory()->count($tracks)->state(['genre_id' => $genre->id, 'bpm' => null]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);

        $this->releases[] = $release->id;

        return $release;
    }

    protected function onlyTheseReleases(): void
    {
        Release::query()
            ->whereNotIn('id', $this->releases)
            ->update(['retired_at' => now()]);
    }

    public function test_sign_up_needs_an_account_type(): void
    {
        $this->postJson('/api/auth/register', $this->signUp())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_type');
    }

    public function test_identities_must_fit_the_account_type(): void
    {
        $this->postJson('/api/auth/register', $this->signUp([
            'account_type' => 'creator',
            'identities' => ['producer', 'fan'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identities.1');
    }

    public function test_sign_up_saves_who_the_user_is(): void
    {
        $values = $this->signUp([
            'account_type' => 'creator',
            'identities' => ['producer', 'dj'],
        ]);

        $this->postJson('/api/auth/register', $values)
            ->assertCreated()
            ->assertJsonPath('data.accountType', 'creator')
            ->assertJsonPath('data.identities', ['producer', 'dj'])
            ->assertJsonPath('data.interestsAt', null);

        $user = User::query()
            ->where('email', $values['email'])
            ->firstOrFail();

        $this->assertSame(AccountType::CREATOR, $user->account_type);
    }

    public function test_the_account_types_come_with_the_app_settings(): void
    {
        $this->getJson('/api/initialize')
            ->assertOk()
            ->assertJsonPath('data.accountTypes.0.value', 'creator')
            ->assertJsonPath('data.accountTypes.1.identities.0.value', 'fan');
    }

    public function test_saving_genres_sets_the_interests(): void
    {
        $user = User::factory()
            ->create(['account_type' => 'listener']);
        $genres = Genre::factory()
            ->count(2)
            ->create();

        Sanctum::actingAs($user);

        $this->putJson('/api/account/interests', [
            'identities' => ['fan', 'curator'],
            'genres' => $genres
                ->pluck('id')
                ->all(),
        ])
            ->assertAccepted()
            ->assertJsonPath('data.identities', ['fan', 'curator'])
            ->assertJsonCount(2, 'data.genres');

        $this->assertNotNull($user->refresh()->interests_at);
        $this->assertEqualsCanonicalizing(
            $genres
                ->pluck('id')
                ->all(),
            $user->interests
                ->pluck('id')
                ->all(),
        );

        $this->getJson('/api/account/interests')
            ->assertOk()
            ->assertJsonCount(2, 'data.genres');
    }

    public function test_at_least_one_genre_is_needed(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/account/interests', ['genres' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('genres');
    }

    public function test_changing_the_account_type_keeps_only_fitting_identities(): void
    {
        $user = User::factory()
            ->create(['account_type' => 'listener', 'identities' => ['fan', 'curator']]);

        Sanctum::actingAs($user);

        $this->putJson('/api/account/interests', ['account_type' => 'creator'])
            ->assertAccepted()
            ->assertJsonPath('data.accountType', 'creator')
            ->assertJsonPath('data.identities', []);
    }

    public function test_for_you_holds_tracks_in_the_interest_genres(): void
    {
        $liked = Genre::factory()
            ->create();
        $other = Genre::factory()
            ->create();
        $user = User::factory()
            ->create();

        $user->interests()->attach($liked);

        $picked = $this
            ->makeRelease($liked, 2)->tracks
            ->pluck('id');

        $this->makeRelease($other, 2);
        $this->onlyTheseReleases();

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/tracks?filters=for_you')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertEqualsCanonicalizing($picked->all(), $ids->all());

        $this->getJson('/api/explore?for_you=6')
            ->assertOk()
            ->assertJsonCount(2, 'data.forYou.list');
    }

    public function test_up_next_plays_interest_genres_first(): void
    {
        $liked = Genre::factory()
            ->create();
        $other = Genre::factory()
            ->create();
        $user = User::factory()
            ->create();

        $user->interests()->attach($liked);

        $this->makeRelease($other, 3);

        $picked = $this
            ->makeRelease($liked, 2)->tracks
            ->pluck('id');

        $this->onlyTheseReleases();

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/playlist?limit=10')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertCount(5, $ids);
        $this->assertEqualsCanonicalizing($picked->all(), $ids->take(2)->all());
    }

    public function test_topics_list_most_picked_first(): void
    {
        $topic = Topic::query()
            ->where('slug', 'vinyl')
            ->firstOrFail();
        $users = User::factory()
            ->count(2)
            ->create();

        $users->each(fn(User $user) => $user->topics()->attach($topic));

        $this->getJson('/api/topics?limit=5')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vinyl')
            ->assertJsonPath('data.0.usersCount', 2);

        $this->getJson('/api/topics?search=beat')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beatmaking');
    }

    public function test_people_can_add_a_missing_topic_for_everyone(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $id = $this->postJson('/api/topics', ['title' => '  bedroom   pop  '])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Bedroom pop')
            ->assertJsonPath('data.custom', true)
            ->json('data.id');

        $this->postJson('/api/topics', ['title' => 'Bedroom Pop'])
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->getJson('/api/topics?search=bedroom')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_topics_with_explicit_words_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/topics', ['title' => 'Shit music'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_guests_cannot_add_topics(): void
    {
        $this->postJson('/api/topics', ['title' => 'Lo-fi beats'])
            ->assertUnauthorized();
    }

    public function test_topics_alone_set_the_interests(): void
    {
        $user = User::factory()
            ->create();
        $topics = Topic::query()
            ->whereIn('slug', ['vinyl', 'djing'])
            ->pluck('id');

        Sanctum::actingAs($user);

        $this->putJson('/api/account/interests', ['topics' => $topics->all()])
            ->assertAccepted()
            ->assertJsonCount(2, 'data.topics');

        $this->assertNotNull($user->refresh()->interests_at);

        $this->putJson('/api/account/interests', ['genres' => [], 'topics' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('genres');
    }
}
