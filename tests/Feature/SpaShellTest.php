<?php

it('serves the SPA shell for non-api routes', function () {
    $this->withoutVite()->get('/admin/providers')->assertOk()->assertSee('id="root"', false);
});

it('tells the SPA which base domain provider storefronts live on', function () {
    config(['app.base_domain' => 'shops.example.test']);

    $this->withoutVite()->get('/')->assertOk()->assertSee('"baseDomain":"shops.example.test"', false);
});
