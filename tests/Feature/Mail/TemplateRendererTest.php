<?php

use App\Models\EmailTemplate;
use App\Services\Mail\TemplateCatalog;
use App\Services\Mail\TemplateRenderer;

function renderTemplate(array $attributes, array $variables): array
{
    $template = EmailTemplate::factory()->make($attributes + ['key' => 'dunning.payment_failed']);

    return app(TemplateRenderer::class)->render($template, $variables);
}

it('FR-E2 replaces placeholders in subject, html and text', function () {
    $out = renderTemplate([
        'subject' => 'Hi {{customer.name}}, {{ plan.name }} failed',
        'html_body' => '<p>Pay {{amount}} to {{provider.name}}</p>',
        'text_body' => 'Pay {{amount}}',
    ], ['customer.name' => 'Ada', 'plan.name' => 'Gold', 'amount' => '20.00 USD', 'provider.name' => 'Acme']);

    expect($out['subject'])->toBe('Hi Ada, Gold failed')
        ->and($out['html'])->toBe('<p>Pay 20.00 USD to Acme</p>')
        ->and($out['text'])->toBe('Pay 20.00 USD');
});

it('FR-E2 escapes values in html but not in subject or text', function () {
    $out = renderTemplate([
        'subject' => '{{customer.name}}', 'html_body' => '<p>{{customer.name}}</p>', 'text_body' => '{{customer.name}}',
    ], ['customer.name' => 'A <b>&</b> Co']);

    expect($out['html'])->toBe('<p>A &lt;b&gt;&amp;&lt;/b&gt; Co</p>')
        ->and($out['subject'])->toBe('A <b>&</b> Co')
        ->and($out['text'])->toBe('A <b>&</b> Co');
});

it('FR-E2 renders a known variable without a value as empty and an unknown placeholder as nothing, never throwing', function () {
    $out = renderTemplate([
        'subject' => 'Retry {{retry_date}}{{nope}}', 'html_body' => '<p>{{ secret.token }}x</p>', 'text_body' => '{{nope}}',
    ], ['secret.token' => 'LEAK']);

    expect($out['subject'])->toBe('Retry ')
        ->and($out['html'])->toBe('<p>x</p>')  // not in the documented list for this key, so never filled in
        ->and($out['text'])->toBe('');
});

it('FR-E2 documents the variables of every template key', function () {
    $catalog = app(TemplateCatalog::class);

    expect($catalog->variables('dunning.payment_failed'))->toHaveKeys(['customer.name', 'amount', 'retry_date', 'update_payment_url', 'provider.name'])
        ->and($catalog->keys())->toContain('dunning.final_notice', 'auth.welcome', 'provider.approved', 'customer.receipt', 'admin.webhook_failures');
});

it('FR-E2 lists the placeholders a text uses that the template does not document', function () {
    $unknown = app(TemplateRenderer::class)->unknownVariables('dunning.payment_failed', 'Hi {{customer.name}} {{bogus}} {{ other.x }}');

    expect($unknown)->toBe(['bogus', 'other.x']);
});

it('FR-E3 seeds the template set from the PRD and keeps admin edits when seeded again', function () {
    $catalog = app(TemplateCatalog::class);
    $catalog->seedAll();

    $template = EmailTemplate::where('key', 'dunning.payment_failed')->firstOrFail();
    $template->update(['subject' => 'Edited']);
    $catalog->seedAll();

    expect(EmailTemplate::count())->toBe(count($catalog->keys()))
        ->and($template->fresh()->subject)->toBe('Edited')
        ->and($template->versions()->count())->toBe(1)
        ->and($template->enabled)->toBeTrue();
    foreach ($catalog->keys() as $key) {
        // every default only uses its documented variables
        $t = EmailTemplate::where('key', $key)->firstOrFail();
        expect(app(TemplateRenderer::class)->unknownVariables($key, $t->subject, $t->html_body, $t->text_body))->toBe([], $key);
    }
});
