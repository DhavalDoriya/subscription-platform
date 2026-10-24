<?php

it('serves messages in the language requested via Accept-Language', function () {
    config(['app.supported_locales' => ['en', 'fr']]);
    app('translator')->addLines(['api.errors.not_found' => 'Ressource introuvable.'], 'fr');

    $this->getJson('/api/v1/nope', ['Accept-Language' => 'fr'])
        ->assertNotFound()
        ->assertExactJson(['message' => 'Ressource introuvable.'])
        ->assertHeader('Content-Language', 'fr');
});

it('falls back to the default language for unsupported locales', function () {
    $this->getJson('/api/v1/nope', ['Accept-Language' => 'de'])
        ->assertJsonPath('message', 'Resource not found.')
        ->assertHeader('Content-Language', 'en');
});

it('keeps every api message key defined in the english file', function () {
    $keys = collect(trans('api'))->flatMap(fn ($group) => array_keys($group))->all();

    expect($keys)->toContain('invalid_credentials', 'not_found', 'server_error');
});
