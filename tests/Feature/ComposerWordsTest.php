<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Word;
use App\Services\Composer\ComposerSettings;
use App\Services\Composer\Words\CmuDictionary;
use App\Services\Composer\Words\Pronunciation;
use App\Services\Composer\Words\SyllableGuess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComposerWordsTest extends TestCase
{
    use DatabaseTransactions;

    protected const BASE = 'https://datamuse.test';

    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.datamuse.base_url' => self::BASE, 'services.datamuse.key' => null]);
        ComposerSettings::flush();
        Cache::flush();

        // Only the sample words, even when the full dictionary was imported.
        Word::query()->delete();
        (new CmuDictionary)->import(base_path('tests/Fixtures/cmudict-sample.dict'));

        $this->writer = User::factory()
            ->create();
    }

    protected function useSource(string $source): void
    {
        ComposerSettings::update(['composer_word_source' => $source]);
    }

    public function test_pronunciation_keys(): void
    {
        $touch = Pronunciation::parse('T AH1 CH');
        $tomorrow = Pronunciation::parse('T AH0 M AA1 R OW2');

        $this->assertSame(1, $touch->syllables());
        $this->assertSame('AH CH', $touch->rhymeKey());
        $this->assertSame('AH', $touch->vowelKey());
        $this->assertSame(3, $tomorrow->syllables());
        $this->assertSame('012', $tomorrow->stress());
        $this->assertSame('AA R OW', $tomorrow->rhymeKey());
        $this->assertSame('AA OW', $tomorrow->tail(2));
        $this->assertSame('AH AA OW', $tomorrow->tail(3));
        $this->assertNull($touch->tail(2));
    }

    public function test_import_keeps_the_first_pronunciation_and_skips_comments(): void
    {
        $touch = Word::query()
            ->where('word', 'touch')
            ->first();

        $this->assertSame('T AH1 CH', $touch->phones);
        $this->assertSame('AH CH', $touch->rhyme_key);
        $this->assertTrue(Word::query()->where('word', 'touching')->exists());
        $this->assertFalse(Word::query()->where('word', 'like', '%(%')->exists());
        $this->assertFalse(Word::query()->where('word', 'like', ';%')->exists());
    }

    public function test_the_bundled_dictionary_parses(): void
    {
        $handle = gzopen(CmuDictionary::defaultPath(), 'rb');
        $line = gzgets($handle);
        gzclose($handle);

        $this->assertNotNull((new CmuDictionary)->row($line));
    }

    public function test_guests_cannot_look_words_up(): void
    {
        $this->getJson('/api/words/touch/rhymes')
            ->assertUnauthorized();
    }

    public function test_local_rhymes_by_type(): void
    {
        $this->useSource('local');
        Sanctum::actingAs($this->writer);

        $perfect = $this->getJson('/api/words/touch/rhymes?type=perfect')
            ->assertOk()
            ->assertJsonPath('source', 'local')
            ->json('data.*.word');

        $this->assertContains('much', $perfect);
        $this->assertContains('clutch', $perfect);
        $this->assertNotContains('hot', $perfect);
        $this->assertNotContains('touch', $perfect);

        $near = $this->getJson('/api/words/hot/rhymes?type=near')
            ->assertOk()
            ->json('data.*.word');

        $this->assertContains('rock', $near);
        $this->assertContains('top', $near);
        $this->assertNotContains('got', $near);

        $multi = $this->getJson('/api/words/' . rawurlencode('touch me') . '/rhymes?type=multi')
            ->assertOk()
            ->json('data');

        $this->assertContains('lovely', array_column($multi, 'word'));
        $this->assertContains('buddy', array_column($multi, 'word'));
        $this->assertSame(2, collect($multi)->firstWhere('word', 'lovely')['syllables']);
    }

    public function test_local_completion(): void
    {
        $this->useSource('local');
        Sanctum::actingAs($this->writer);

        $words = $this->getJson('/api/words/complete?q=tou')
            ->assertOk()
            ->json('data.*.word');

        $this->assertSame(['touch', 'touching'], array_values(array_intersect($words, ['touch', 'touching'])));
    }

    public function test_datamuse_answers_are_mapped_and_cached(): void
    {
        $this->useSource('datamuse');
        Sanctum::actingAs($this->writer);

        Http::fake([
            self::BASE . '/words*' => Http::response([
                ['word' => 'much', 'score' => 3000, 'numSyllables' => 1],
                ['word' => 'clutch', 'score' => 2500, 'numSyllables' => 1],
            ]),
        ]);

        $this->getJson('/api/words/touch/rhymes')
            ->assertOk()
            ->assertJsonPath('source', 'datamuse')
            ->assertJsonPath('data.0.word', 'much')
            ->assertJsonPath('data.0.syllables', 1)
            ->assertJsonPath('data.0.score', 3000);

        $this->getJson('/api/words/touch/rhymes')
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn(ClientRequest $request) => $request['rel_rhy'] === 'touch' && $request['md'] === 's' && ! isset($request['key']));
    }

    public function test_datamuse_receives_the_key_when_set(): void
    {
        config(['services.datamuse.key' => 'dm-key']);
        $this->useSource('datamuse');
        Sanctum::actingAs($this->writer);

        Http::fake([
            self::BASE . '/sug*' => Http::response([['word' => 'touch', 'score' => 100]]),
        ]);

        $this->getJson('/api/words/complete?q=tou')
            ->assertOk()
            ->assertJsonPath('data.0.word', 'touch');

        Http::assertSent(fn(ClientRequest $request) => $request['s'] === 'tou' && $request['key'] === 'dm-key');
    }

    public function test_a_failing_datamuse_falls_back_to_the_dictionary_without_caching(): void
    {
        $this->useSource('datamuse');
        Sanctum::actingAs($this->writer);

        Http::fake([
            self::BASE . '/words*' => Http::response('busy', 503),
        ]);

        $words = $this->getJson('/api/words/touch/rhymes')
            ->assertOk()
            ->json('data.*.word');

        $this->assertContains('much', $words);

        $this->getJson('/api/words/touch/rhymes')
            ->assertOk();

        Http::assertSentCount(2);
    }

    public function test_related_words_and_definitions_from_datamuse(): void
    {
        $this->useSource('datamuse');
        Sanctum::actingAs($this->writer);

        Http::fake([
            self::BASE . '/words?ml=*' => Http::response([['word' => 'contact', 'score' => 90]]),
            self::BASE . '/words?sp=*' => Http::response([
                ['word' => 'touch', 'defs' => ["n\tthe act of putting two things together", "v\tmake physical contact with"]],
            ]),
        ]);

        $this->getJson('/api/words/touch/related?type=means')
            ->assertOk()
            ->assertJsonPath('data.0.word', 'contact');

        $this->getJson('/api/words/touch/definitions')
            ->assertOk()
            ->assertJsonPath('data.0.partOfSpeech', 'noun')
            ->assertJsonPath('data.0.text', 'the act of putting two things together')
            ->assertJsonPath('data.1.partOfSpeech', 'verb');
    }

    public function test_syllables_for_known_and_unknown_words(): void
    {
        Sanctum::actingAs($this->writer);

        $rows = collect($this->postJson('/api/words/syllables', ['words' => ['Tomorrow', 'touch', 'gyalchester', 'tomorrow']])
            ->assertOk()
            ->json('data'))
            ->keyBy('word');

        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows['tomorrow']['syllables']);
        $this->assertSame('012', $rows['tomorrow']['stress']);
        $this->assertTrue($rows['tomorrow']['known']);
        $this->assertFalse($rows['gyalchester']['known']);
        $this->assertSame(3, $rows['gyalchester']['syllables']);
    }

    public function test_rejects_unknown_types_and_oversized_batches(): void
    {
        Sanctum::actingAs($this->writer);

        $this->getJson('/api/words/touch/rhymes?type=slant')
            ->assertUnprocessable();

        $this->postJson('/api/words/syllables', ['words' => array_fill(0, 201, 'touch')])
            ->assertUnprocessable();
    }

    public function test_syllable_guesses(): void
    {
        $this->assertSame(1, SyllableGuess::count('rate'));
        $this->assertSame(2, SyllableGuess::count('table'));
        $this->assertSame(1, SyllableGuess::count('played'));
        $this->assertSame(2, SyllableGuess::count('wanted'));
        $this->assertSame(2, SyllableGuess::count('gyalis'));
    }
}
