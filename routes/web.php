<?php

use Illuminate\Support\Facades\Route;

// SPA shell: everything that is not /api/* is handled by React Router.
Route::view('/{any?}', 'spa')->where('any', '^(?!api|horizon|up).*$');
