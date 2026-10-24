<?php

use App\Models\Media;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('FR-S5 a provider uploads an image and gets a public url', function () {
    [, $provider] = providerOwnerSession();

    $response = $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->image('logo.png', 400, 300)])
        ->assertCreated()
        ->assertJsonPath('data.original_name', 'logo.png')
        ->assertJsonPath('data.width', 400)
        ->assertJsonPath('data.height', 300);

    $media = Media::findOrFail($response->json('data.id'));
    expect($media->provider_id)->toBe($provider->id)
        ->and($response->json('data.url'))->toStartWith('http')
        ->and($media->path)->toStartWith("providers/{$provider->id}/");
    Storage::disk('public')->assertExists($media->path);
});

it('FR-S5 rejects a file of the wrong type', function () {
    providerOwnerSession();

    $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0);
});

it('FR-S5 rejects an oversize file', function () {
    providerOwnerSession();

    $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->image('big.jpg', 100, 100)->size(5121)])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(Media::count())->toBe(0);
});

it('FR-S5 a file that only pretends to be an image is rejected', function () {
    providerOwnerSession();

    $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('FR-S5 large images are scaled down to the maximum width', function () {
    providerOwnerSession();

    $this->postJson('/api/v1/provider/media', ['file' => UploadedFile::fake()->image('wide.jpg', 3000, 1500)])
        ->assertCreated()
        ->assertJsonPath('data.width', 2000)
        ->assertJsonPath('data.height', 1000);
});

it('FR-S5 lists only own media, newest first, and searches by name', function () {
    [, $provider] = providerOwnerSession();
    $old = Media::factory()->create(['provider_id' => $provider->id, 'original_name' => 'old-banner.png', 'created_at' => now()->subDay()]);
    $new = Media::factory()->create(['provider_id' => $provider->id, 'original_name' => 'team.png']);
    Media::factory()->create(['original_name' => 'foreign.png']);

    $this->getJson('/api/v1/provider/media')
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $new->id);
    $this->getJson('/api/v1/provider/media?search=banner')
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
});

it('FR-S5 updates alt text and deletes own media, never another providers', function () {
    [, $provider] = providerOwnerSession();
    $mine = Media::factory()->create(['provider_id' => $provider->id]);
    $theirs = Media::factory()->create();
    Storage::disk('public')->put($mine->path, 'x');

    $this->patchJson("/api/v1/provider/media/{$mine->id}", ['alt' => 'Our team'])->assertOk()->assertJsonPath('data.alt', 'Our team');
    $this->patchJson("/api/v1/provider/media/{$theirs->id}", ['alt' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/provider/media/{$theirs->id}")->assertNotFound();

    $this->deleteJson("/api/v1/provider/media/{$mine->id}")->assertNoContent();
    expect(Media::find($mine->id))->toBeNull()->and(Media::withTrashed()->find($mine->id))->not->toBeNull();
    Storage::disk('public')->assertMissing($mine->path);
});

it('FR-S5 a customer cannot use the media library', function () {
    $this->postJson('/api/v1/provider/media', [])->assertUnauthorized();
    customerSession();
    $this->getJson('/api/v1/provider/media')->assertNotFound(); // no provider account, same as plans
});

it('FR-NFR media is isolated per tenant', function () {
    assertTenantIsolation(Media::class, fn (Provider $p) => Media::factory()->create(['provider_id' => $p->id]));
});

it('FR-S4 storefront image fields still accept media urls', function () {
    [, $provider] = providerOwnerSession();
    $media = Media::factory()->create(['provider_id' => $provider->id]);

    $this->putJson('/api/v1/provider/storefront', ['seo' => ['og_image_url' => $media->url], 'blocks' => []])->assertOk();
});
