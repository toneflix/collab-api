<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetAiKeys();

        Http::preventStrayRequests();
    }

    /**
     * Drops the AI provider keys a local `.env` may hold, so tests only reach
     * a provider when they set a key and fake its answers themselves.
     */
    protected function forgetAiKeys(): void
    {
        $keys = [];

        foreach (config('ai.providers', []) as $name => $provider) {
            foreach (array_intersect(['key', 'account'], array_keys($provider)) as $field) {
                $keys["ai.providers.{$name}.{$field}"] = null;
            }
        }

        config($keys);
    }
}
