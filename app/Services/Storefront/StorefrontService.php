<?php

namespace App\Services\Storefront;

use App\Exceptions\ApiException;
use App\Models\PageBlock;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\StorefrontPage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Draft and published storefront content. The draft is rows (page + blocks) the owner edits freely; publishing copies the
 * visible blocks into a snapshot, and the public API serves only that snapshot, so drafts can never leak (FR-S3).
 */
class StorefrontService
{
    private const PREVIEW_TTL = 3600;

    private const CACHE_SECONDS = 300;

    /** @return array<string, mixed> */
    public function draft(Provider $provider): array
    {
        $page = $this->page($provider);
        $document = $this->draftDocument($page);

        return [
            'seo' => $document['seo'],
            'blocks' => $page->blocks->map(fn (PageBlock $b) => $this->blockArray($b))->all(),
            'published_at' => $page->published_at?->toIso8601String(),
            'has_unpublished_changes' => $page->published_snapshot !== null && ($page->published_snapshot['hash'] ?? null) !== $document['hash'],
        ];
    }

    /**
     * @param  array<string, mixed>  $seo
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public function save(Provider $provider, array $seo, array $blocks): array
    {
        DB::transaction(function () use ($provider, $seo, $blocks) {
            $page = $this->page($provider);
            $page->update(['seo' => array_intersect_key($seo, array_flip(['title', 'description', 'og_image_url']))]);

            // Tenant-scoped lookup: an id from another provider is simply not found, so it becomes a new block.
            $existing = $page->blocks()->get()->keyBy('id');
            $kept = [];

            foreach (array_values($blocks) as $position => $input) {
                $attributes = [
                    'type' => $input['type'],
                    'data' => BlockSchema::sanitize($input['type'], $input['data'] ?? []),
                    'position' => $position,
                    'visible' => (bool) ($input['visible'] ?? true),
                ];

                $block = isset($input['id'], $existing[$input['id']]) ? tap($existing[$input['id']])->update($attributes)
                    : PageBlock::create($attributes + ['provider_id' => $provider->id, 'storefront_page_id' => $page->id]);

                $kept[] = $block->id;
            }

            $page->blocks()->whereNotIn('id', $kept)->get()->each->delete();
        });

        return $this->draft($provider);
    }

    /** @return array<string, mixed> */
    public function publish(Provider $provider, User $actor): array
    {
        if (! $provider->status->isOperational()) {
            throw $this->conflict('provider_not_approved');
        }

        $page = $this->page($provider);
        $document = $this->draftDocument($page);

        if ($document['blocks'] === []) {
            throw $this->conflict('storefront_empty');
        }

        $page->update(['published_snapshot' => $document, 'published_at' => now()]);
        activity()->causedBy($actor)->performedOn($page)->log('storefront.published');

        return $this->draft($provider);
    }

    /** @return array<string, mixed> */
    public function unpublish(Provider $provider, User $actor, string $event = 'storefront.unpublished'): array
    {
        $page = $this->page($provider);
        $page->update(['published_snapshot' => null, 'published_at' => null]);
        activity()->causedBy($actor)->performedOn($page)->log($event);

        return $this->draft($provider);
    }

    /** A link-able token that lets the holder see the draft on the provider's host for an hour. Signed, not stored. */
    public function previewToken(Provider $provider, ?int $expires = null): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['p' => $provider->id, 'exp' => $expires ?? time() + self::PREVIEW_TTL])), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public function previewExpiry(string $token): ?int
    {
        return $this->decodePreview($token)['exp'] ?? null;
    }

    public function previewIsValid(?string $token, Provider $provider): bool
    {
        $payload = $token ? $this->decodePreview($token) : null;

        return $payload !== null && $payload['p'] === $provider->id && $payload['exp'] >= time();
    }

    /**
     * The public storefront, cached per provider (FR-L2). A draft preview is never cached and never reads the cache. Anything
     * that changes what the public sees clears the entry (see StorefrontCache); the TTL only bounds what a missed event could cost.
     *
     * @return array<string, mixed>
     */
    public function publicView(Provider $provider, ?string $previewToken): array
    {
        if ($this->previewIsValid($previewToken, $provider)) {
            return $this->buildPublicView($provider, true);
        }

        return Cache::remember(StorefrontCache::key($provider->id), self::CACHE_SECONDS, fn () => $this->buildPublicView($provider, false));
    }

    /** @return array<string, mixed> */
    private function buildPublicView(Provider $provider, bool $preview): array
    {
        $available = $provider->status->isOperational();
        $page = $available ? $this->page($provider) : null;

        $content = match (true) {
            ! $page => null,
            $preview => $this->draftDocument($page),
            default => $page->published_snapshot,
        };

        return [
            'provider' => [
                'name' => $provider->name,
                'slug' => $provider->slug,
                'support_email' => $provider->support_email,
                'currency' => $provider->currency,
                'branding' => ['primary_color' => $provider->settings['branding']['primary_color'] ?? null],
                'legal' => [
                    'terms_url' => $provider->settings['legal']['terms_url'] ?? null,
                    'privacy_url' => $provider->settings['legal']['privacy_url'] ?? null,
                    'refund_url' => $provider->settings['legal']['refund_url'] ?? null,
                ],
            ],
            'available' => $available,
            'published' => $page?->published_snapshot !== null,
            'preview' => $preview && $available,
            'page' => $content ? ['seo' => $content['seo'], 'blocks' => $content['blocks']] : null,
            'plans' => $content ? $this->publicPlans() : [],
        ];
    }

    private function page(Provider $provider): StorefrontPage
    {
        return StorefrontPage::firstOrCreate(['provider_id' => $provider->id], ['seo' => []])->load('blocks');
    }

    /** @return array{seo: array<string, mixed>, blocks: array<int, array<string, mixed>>, hash: string} visible blocks only */
    private function draftDocument(StorefrontPage $page): array
    {
        $seo = array_filter($page->seo ?? [], fn ($v) => $v !== null && $v !== '');
        $blocks = $page->blocks->filter->visible->map(fn (PageBlock $b) => ['id' => $b->id, 'type' => $b->type, 'data' => $b->data])->values()->all();
        // The hash ignores ids so reordering identical content is still a change, but re-saving the same content is not.
        $hash = md5(json_encode([$seo, array_map(fn ($b) => [$b['type'], $b['data']], $blocks)]));

        return ['seo' => (object) $seo ? $seo : [], 'blocks' => $blocks, 'hash' => $hash];
    }

    /** @return array<string, mixed> */
    private function blockArray(PageBlock $block): array
    {
        return ['id' => $block->id, 'type' => $block->type, 'visible' => $block->visible, 'data' => $block->data];
    }

    /** Live plans are read at request time (not snapshotted) so price changes show without republishing. */
    private function publicPlans(): array
    {
        return Plan::with('activePrices')->where('status', 'active')->where('visibility', 'public')
            ->orderBy('sort_order')->orderBy('created_at')->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'features' => $plan->features ?? [],
                'trial_days' => $plan->trial_days,
                'prices' => $plan->activePrices->map(fn ($p) => ['id' => $p->id, 'amount' => $p->amount, 'currency' => $p->currency, 'interval' => $p->interval, 'interval_count' => $p->interval_count])->values()->all(),
            ])->all();
    }

    /** @return array{p: string, exp: int}|null */
    private function decodePreview(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && isset($data['p'], $data['exp']) ? $data : null;
    }

    private function conflict(string $code): ApiException
    {
        return new ApiException(__("api.storefront.{$code}"), 409, $code);
    }
}
