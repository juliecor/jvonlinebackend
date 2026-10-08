<?php

use Illuminate\Support\Facades\Route;

// This host serves the API only. A person who lands on its root (or follows a
// bare link to it) belongs on the public site, so send them there for good.
// FRONTEND_URL is the same setting the invite and offer emails already use.
Route::get('/', fn () => redirect()->away(rtrim(config('app.frontend_url'), '/'), 301));
