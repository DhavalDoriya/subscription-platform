<?php

it('serves the SPA shell for non-api routes', function () {
    $this->withoutVite()->get('/admin/providers')->assertOk()->assertSee('id="root"', false);
});
