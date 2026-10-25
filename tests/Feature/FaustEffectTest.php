<?php

namespace Tests\Feature;

use App\Services\Studio\Faust\FaustLibrary;
use Tests\TestCase;

class FaustEffectTest extends TestCase
{
    public function test_the_studio_lists_faust_effects_with_their_source_and_settings(): void
    {
        if (! app(FaustLibrary::class)->available()) {
            $this->markTestSkipped('The Faust compiler is not installed.');
        }

        $this
            ->getJson('/api/studio/faust-effects')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'faust-hall')
            ->assertJsonPath('data.0.label', 'Hall reverb')
            ->assertJsonPath('data.0.params.0.key', 'damping')
            ->assertJsonPath('data.0.params.0.address', '/tf_hall/damping')
            ->assertJsonStructure(['data' => [['type', 'name', 'label', 'group', 'description', 'source', 'params']]]);
    }

    public function test_unknown_or_unsafe_types_are_not_found(): void
    {
        $library = app(FaustLibrary::class);

        $this->assertNull($library->find('faust-../../etc/passwd'));
        $this->assertNull($library->find('faust-missing'));
        $this->assertNull($library->find('tf-eq'));
    }
}
