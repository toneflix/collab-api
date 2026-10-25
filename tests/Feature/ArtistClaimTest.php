<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ArtistClaimTest extends TestCase
{
    use DatabaseTransactions;

    protected User $claimant;

    protected User $admin;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake(config('filesystems.default'));

        $this->claimant = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));

        $this->artist = Artist::factory()->create(['user_id' => null, 'verified' => false]);
    }

    protected function claim(?User $user = null, array $data = [])
    {
        Sanctum::actingAs($user ?? $this->claimant);

        return $this->postJson("/api/artists/{$this->artist->username}/claims", [
            'role' => 'manager',
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'links' => ['https://instagram.com/artist', 'https://distrokid.com/artist'],
            'message' => 'I have managed this artist since 2021 and handle all their releases.',
            ...$data,
        ]);
    }

    public function test_user_can_claim_an_unmanaged_artist_with_proof(): void
    {
        $this->claim(data: ['document' => UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.hasDocument', true);

        $claim = ArtistClaim::query()->where('artist_id', $this->artist->id)->sole();
        Storage::disk(config('filesystems.default'))->assertExists($claim->document);
    }

    public function test_claiming_again_updates_the_pending_claim(): void
    {
        $this->claim()->assertCreated();
        $this->claim(data: ['role' => 'label'])->assertCreated();

        $this->assertSame(1, ArtistClaim::query()->where('artist_id', $this->artist->id)->count());
        $this->assertSame('label', ArtistClaim::query()->where('artist_id', $this->artist->id)->value('role'));
    }

    public function test_managed_artists_cannot_be_claimed(): void
    {
        $this->artist->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->claim()->assertForbidden();
    }

    public function test_claims_need_links_and_a_message(): void
    {
        $this->claim(data: ['links' => [], 'message' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['links', 'message']);
    }

    public function test_artist_page_shows_your_claim(): void
    {
        $this->claim()->assertCreated();

        $token = $this->claimant->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson("/api/artists/{$this->artist->username}")
            ->assertOk()
            ->assertJsonPath('data.claimable', true)
            ->assertJsonPath('data.yourClaim.status', 'pending');
    }

    public function test_approving_links_the_artist_and_its_unowned_catalogue(): void
    {
        $this->claim()->assertCreated();
        $other = User::factory()->create();
        $this->claim($other)->assertCreated();

        $owned = User::factory()->create();
        Release::query()->withoutGlobalScopes()->where('artist_id', $this->artist->id)->update(['user_id' => null]);
        Release::factory()->create(['artist_id' => $this->artist->id, 'user_id' => $owned->id]);
        $unowned = Release::query()->withoutGlobalScopes()->where('artist_id', $this->artist->id)->whereNull('user_id')->pluck('id');

        $this->assertNotEmpty($unowned);

        $claim = ArtistClaim::query()->where('user_id', $this->claimant->id)->sole();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/moderation/claims')
            ->assertOk()
            ->assertJsonPath('stats.claims', 2)
            ->assertJsonPath('data.0.artist.unownedReleases', $unowned->count());

        $this->postJson("/api/admin/moderation/claims/{$claim->id}/approve")->assertAccepted();

        $this->artist->refresh();
        $this->assertSame($this->claimant->id, (int) $this->artist->user_id);
        $this->assertTrue($this->artist->verified);
        $this->assertSame(
            $unowned->count(),
            Release::query()->withoutGlobalScopes()->whereIn('id', $unowned)->where('user_id', $this->claimant->id)->count()
        );
        $this->assertSame(1, Release::query()->withoutGlobalScopes()->where('artist_id', $this->artist->id)->where('user_id', $owned->id)->count());

        $this->assertSame(ArtistClaim::REJECTED, ArtistClaim::query()->where('user_id', $other->id)->value('status'));
        Notification::assertSentTo($this->claimant, ModerationNotice::class);
        Notification::assertSentTo($other, ModerationNotice::class);

        Sanctum::actingAs($this->claimant);
        $this->post("/api/creator/artists/{$this->artist->id}", ['_method' => 'PUT', 'name' => 'Renamed'], ['Accept' => 'application/json'])
            ->assertStatus(202);
    }

    public function test_rejecting_needs_a_note_and_tells_the_claimant(): void
    {
        $this->claim()->assertCreated();
        $claim = ArtistClaim::query()->where('artist_id', $this->artist->id)->sole();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/reject")->assertUnprocessable();
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/reject", ['note' => 'The links don\'t mention you.'])->assertAccepted();

        $this->assertSame(ArtistClaim::REJECTED, $claim->refresh()->status);
        $this->assertNull($this->artist->refresh()->user_id);
        Notification::assertSentTo($this->claimant, ModerationNotice::class);

        $this->postJson("/api/admin/moderation/claims/{$claim->id}/approve")->assertUnprocessable();
    }

    public function test_only_moderators_review_claims_and_see_documents(): void
    {
        $this->claim(data: ['document' => UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf')])->assertCreated();
        $claim = ArtistClaim::query()->where('artist_id', $this->artist->id)->sole();

        Sanctum::actingAs($this->claimant);
        $this->getJson('/api/admin/moderation/claims')->assertForbidden();
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/approve")->assertForbidden();
        $this->get("/api/admin/moderation/claims/{$claim->id}/document")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->get("/api/admin/moderation/claims/{$claim->id}/document")->assertOk()->assertDownload();
    }
}
