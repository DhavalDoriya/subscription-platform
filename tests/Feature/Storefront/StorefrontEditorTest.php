<?php

use App\Enums\ProviderStatus;
use App\Models\Activity;
use App\Models\PageBlock;
use App\Models\Provider;
use App\Models\StorefrontPage;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function block(string $type, array $data = [], array $extra = []): array
{
    $defaults = [
        'hero' => ['headline' => 'Train smarter', 'subheadline' => 'Plans for everyone', 'button_label' => 'Join', 'button_url' => 'https://acme.test/join'],
        'about' => ['heading' => 'About us', 'body' => '<p>We are a <strong>gym</strong>.</p>'],
        'text' => ['body' => '<p>Hello</p>'],
        'image' => ['image_url' => 'https://cdn.test/a.jpg', 'alt' => 'The gym'],
        'feature_list' => ['heading' => 'Why us', 'items' => [['title' => 'Coaches', 'description' => 'Certified']]],
        'testimonials' => ['items' => [['quote' => 'Great', 'author' => 'Sam']]],
        'faq' => ['items' => [['question' => 'Refunds?', 'answer' => '<p>Yes</p>']]],
        'cta' => ['heading' => 'Ready?', 'button_label' => 'Start', 'button_url' => 'https://acme.test/start'],
        'plans' => ['heading' => 'Pricing'],
        'contact' => ['heading' => 'Contact', 'email' => 'help@acme.test'],
    ];

    return array_merge(['type' => $type, 'visible' => true, 'data' => array_merge($defaults[$type] ?? [], $data)], $extra);
}

function saveDraft(array $blocks, array $seo = []): TestResponse
{
    return test()->putJson('/api/v1/provider/storefront', ['seo' => $seo, 'blocks' => $blocks]);
}

it('FR-S2 starts with an empty draft that is not published', function () {
    providerOwnerSession();

    $this->getJson('/api/v1/provider/storefront')->assertOk()
        ->assertJsonPath('data.blocks', [])->assertJsonPath('data.published_at', null)->assertJsonPath('data.has_unpublished_changes', false);
});

it('FR-S2 keeps the storefront editor for provider members only', function () {
    $this->getJson('/api/v1/provider/storefront')->assertUnauthorized();
    $stranger = User::factory()->create(['password' => 'Password123!'])->assignRole('customer');
    $this->withToken(loginAs($stranger)->json('data.access_token'));

    $this->getJson('/api/v1/provider/storefront')->assertNotFound()->assertJsonPath('code', 'provider_not_found');
});

it('FR-S2 saves every block type and returns them in order with ids', function () {
    providerOwnerSession();
    $types = ['hero', 'about', 'text', 'image', 'feature_list', 'testimonials', 'faq', 'cta', 'plans', 'contact'];

    $response = saveDraft(array_map(fn ($t) => block($t), $types))->assertOk();

    expect(array_column($response->json('data.blocks'), 'type'))->toBe($types)
        ->and(Str::isUuid($response->json('data.blocks.0.id')))->toBeTrue()
        ->and(PageBlock::count())->toBe(10);
});

it('FR-S2 reorders and hides blocks, keeping their ids stable across saves', function () {
    providerOwnerSession();
    $first = saveDraft([block('hero'), block('about'), block('cta')])->json('data.blocks');
    [$hero, $about, $cta] = $first;

    $second = saveDraft([
        block('cta', [], ['id' => $cta['id']]),
        block('hero', [], ['id' => $hero['id'], 'visible' => false]),
        block('about', [], ['id' => $about['id']]),
    ])->assertOk()->json('data.blocks');

    expect(array_column($second, 'id'))->toBe([$cta['id'], $hero['id'], $about['id']])
        ->and($second[1]['visible'])->toBeFalse()
        ->and(PageBlock::count())->toBe(3);
});

it('FR-S2 removes blocks left out of a save, softly', function () {
    providerOwnerSession();
    $blocks = saveDraft([block('hero'), block('about')])->json('data.blocks');

    saveDraft([block('hero', [], ['id' => $blocks[0]['id']])])->assertOk()->assertJsonCount(1, 'data.blocks');

    expect(PageBlock::count())->toBe(1)->and(PageBlock::withTrashed()->count())->toBe(2);
});

it('FR-S2 will not adopt another provider\'s block id', function () {
    providerOwnerSession();
    $other = Provider::factory()->create();
    $page = StorefrontPage::factory()->create(['provider_id' => $other->id]);
    $theirs = PageBlock::factory()->create(['provider_id' => $other->id, 'storefront_page_id' => $page->id]);

    $saved = saveDraft([block('hero', [], ['id' => $theirs->id])])->assertOk()->json('data.blocks');

    expect($saved[0]['id'])->not->toBe($theirs->id)->and($theirs->fresh()->provider_id)->toBe($other->id);
});

it('FR-S2 validates blocks by type', function (array $blocks, string $field) {
    providerOwnerSession();

    saveDraft($blocks)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown type' => [[['type' => 'carousel', 'visible' => true, 'data' => []]], 'blocks.0.type'],
    'hero without headline' => [[block('hero', ['headline' => ''])], 'blocks.0.data.headline'],
    'button url must be http(s)' => [[block('hero', ['button_url' => 'javascript:alert(1)'])], 'blocks.0.data.button_url'],
    'image needs alt text' => [[block('image', ['alt' => ''])], 'blocks.0.data.alt'],
    'image url must be http(s)' => [[block('image', ['image_url' => 'data:text/html,x'])], 'blocks.0.data.image_url'],
    'faq needs a question' => [[block('faq', ['items' => [['question' => '', 'answer' => '<p>x</p>']]])], 'blocks.0.data.items.0.question'],
    'testimonials need items' => [[block('testimonials', ['items' => []])], 'blocks.0.data.items'],
    'too many features' => [[block('feature_list', ['items' => array_fill(0, 13, ['title' => 'x'])])], 'blocks.0.data.items'],
    'cta needs a button' => [[block('cta', ['button_label' => ''])], 'blocks.0.data.button_label'],
    'contact email must be an email' => [[block('contact', ['email' => 'nope'])], 'blocks.0.data.email'],
]);

it('FR-S2 caps the number of blocks on a page', function () {
    providerOwnerSession();

    saveDraft(array_fill(0, 31, block('text')))->assertUnprocessable()->assertJsonValidationErrors('blocks');
});

it('FR-S4 validates the SEO fields', function (array $seo, string $field) {
    providerOwnerSession();

    saveDraft([block('hero')], $seo)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'title too long' => [['title' => str_repeat('a', 71)], 'seo.title'],
    'description too long' => [['description' => str_repeat('a', 161)], 'seo.description'],
    'og image must be http(s)' => [['og_image_url' => 'ftp://x/y.png'], 'seo.og_image_url'],
]);

it('FR-S4 stores the SEO fields with the draft', function () {
    providerOwnerSession();

    saveDraft([block('hero')], ['title' => 'Acme Gym', 'description' => 'Best gym', 'og_image_url' => 'https://cdn.test/og.png'])->assertOk()
        ->assertJsonPath('data.seo.title', 'Acme Gym')->assertJsonPath('data.seo.og_image_url', 'https://cdn.test/og.png');
});

it('FR-S7 strips scripts, event handlers and unsafe links from rich text before storing it', function () {
    providerOwnerSession();
    $dirty = '<p onclick="steal()">Hi <script>alert(1)</script><a href="javascript:evil()">x</a><a href="https://ok.test" onmouseover="bad()">ok</a><img src=x onerror=bad()><iframe src="https://evil.test"></iframe><style>*{display:none}</style></p>';

    $response = saveDraft([block('text', ['body' => $dirty]), block('faq', ['items' => [['question' => 'Q', 'answer' => $dirty]]])])->assertOk();

    foreach ([$response->json('data.blocks.0.data.body'), $response->json('data.blocks.1.data.items.0.answer')] as $html) {
        expect($html)->not->toContain('<script')->not->toContain('onclick')->not->toContain('onmouseover')->not->toContain('onerror')
            ->not->toContain('javascript:')->not->toContain('<iframe')->not->toContain('<style')->not->toContain('<img')
            ->and($html)->toContain('https://ok.test');
    }
});

it('FR-S7 keeps the formatting a page needs', function () {
    providerOwnerSession();
    $html = '<h2>Title</h2><p>Some <strong>bold</strong>, <em>italic</em> and a <a href="https://ok.test">link</a>.</p><ul><li>One</li></ul><blockquote>Quote</blockquote>';

    $saved = saveDraft([block('text', ['body' => $html])])->json('data.blocks.0.data.body');

    expect($saved)->toContain('<h2>Title</h2>')->toContain('<strong>bold</strong>')->toContain('<em>italic</em>')->toContain('<li>One</li>')->toContain('<blockquote>Quote</blockquote>')->toContain('href="https://ok.test"');
});

it('FR-S7 opens links safely', function () {
    providerOwnerSession();

    $saved = saveDraft([block('text', ['body' => '<p><a href="https://ok.test">x</a></p>'])])->json('data.blocks.0.data.body');

    expect($saved)->toContain('rel="nofollow noopener"');
});

it('FR-S3 tracks whether the draft differs from what is published', function () {
    providerOwnerSession(['status' => ProviderStatus::Active]);
    saveDraft([block('hero')])->assertJsonPath('data.has_unpublished_changes', false); // nothing published, nothing to compare

    $this->postJson('/api/v1/provider/storefront/publish')->assertOk()->assertJsonPath('data.has_unpublished_changes', false);
    saveDraft([block('hero', ['headline' => 'Changed'])])->assertJsonPath('data.has_unpublished_changes', true);
});

it('FR-S3 refuses to publish an empty page or a page with every block hidden', function () {
    providerOwnerSession(['status' => ProviderStatus::Active]);

    $this->postJson('/api/v1/provider/storefront/publish')->assertStatus(409)->assertJsonPath('code', 'storefront_empty');
    saveDraft([block('hero', [], ['visible' => false])]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertStatus(409)->assertJsonPath('code', 'storefront_empty');
});

it('FR-P5 will not publish for a provider that is not approved', function (ProviderStatus $status) {
    providerOwnerSession(['status' => $status]);
    saveDraft([block('hero')]);

    $this->postJson('/api/v1/provider/storefront/publish')->assertStatus(409)->assertJsonPath('code', 'provider_not_approved');
})->with([ProviderStatus::Pending, ProviderStatus::Rejected, ProviderStatus::Inactive, ProviderStatus::Suspended]);

it('FR-S3 unpublishes, keeping the draft', function () {
    providerOwnerSession(['status' => ProviderStatus::Active]);
    saveDraft([block('hero')]);
    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();

    $this->postJson('/api/v1/provider/storefront/unpublish')->assertOk()->assertJsonPath('data.published_at', null)->assertJsonCount(1, 'data.blocks');
});

it('FR-L1 audits publishing and unpublishing', function () {
    [$user] = providerOwnerSession(['status' => ProviderStatus::Active]);
    saveDraft([block('hero')]);

    $this->postJson('/api/v1/provider/storefront/publish')->assertOk();
    $this->postJson('/api/v1/provider/storefront/unpublish')->assertOk();

    $log = Activity::whereIn('description', ['storefront.published', 'storefront.unpublished'])->orderBy('created_at')->pluck('description', 'causer_id');
    expect($log->count())->toBe(1)->and(Activity::where('description', 'storefront.published')->first()->causer_id)->toBe($user->id)
        ->and(Activity::where('description', 'storefront.unpublished')->count())->toBe(1);
});

it('FR-S3 hands out a time-limited preview link for the draft', function () {
    [, $provider] = providerOwnerSession(['slug' => 'acme']);
    config(['app.base_domain' => 'platform.test']);

    $response = $this->postJson('/api/v1/provider/storefront/preview')->assertOk();

    expect($response->json('data.url'))->toStartWith('http')->toContain('acme.platform.test')->toContain('preview=')
        ->and($response->json('data.expires_at'))->not->toBeNull();
});
