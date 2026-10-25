<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Calls every read endpoint once and checks that none of them crash.
 * It uses the records already in the test database (see README for seeding).
 */
class EndpointSmokeTest extends TestCase
{
    public static function publicRoutes(): array
    {
        return [
            ['api/initialize'],
            ['api/explore'],
            ['api/genres'],
            ['api/genres/{genre}'],
            ['api/artists'],
            ['api/artists/{artist}'],
            ['api/users'],
            ['api/users/{user}'],
            ['api/releases'],
            ['api/releases/{release}'],
            ['api/releases/{release}/tracks'],
            ['api/tracks'],
            ['api/tracks/{track}'],
            ['api/projects'],
            ['api/projects/{project}'],
            ['api/playlist'],
        ];
    }

    public static function privateRoutes(): array
    {
        return [
            ['api/account'],
            ['api/account/notifications'],
            ['api/account/notification-requests'],
            ['api/creator/projects'],
            ['api/creator/releases'],
            ['api/safety/blocklist'],
            ['api/projects/{project}/comments'],
            ['api/releases/{release}/comments'],
            ['api/tracks/{track}/comments'],
            ['api/users/{user}/follows'],
            ['api/artists/{artist}/follows'],
        ];
    }

    #[DataProvider('publicRoutes')]
    public function test_public_endpoint_does_not_fail(string $uri): void
    {
        $this
            ->get($this->resolve($uri))
            ->assertSuccessful();
    }

    #[DataProvider('privateRoutes')]
    public function test_private_endpoint_does_not_fail(string $uri): void
    {
        $user = User::query()
            ->first();

        if (! $user) {
            $this->markTestSkipped('The test database has no users.');
        }

        Sanctum::actingAs($user, ['*']);

        $this
            ->get($this->resolve($uri))
            ->assertSuccessful();
    }

    protected function resolve(string $uri): string
    {
        $models = [
            '{genre}' => Genre::class,
            '{artist}' => Artist::class,
            '{user}' => User::class,
            '{release}' => Release::class,
            '{track}' => Track::class,
            '{project}' => Project::class,
        ];

        foreach ($models as $param => $model) {
            if (str_contains($uri, $param)) {
                $record = $model::query()
                    ->first();

                if (! $record) {
                    $this->markTestSkipped("The test database has no {$param} records.");
                }

                $uri = str_replace($param, (string) $record->getRouteKey(), $uri);
            }
        }

        return $uri;
    }
}
