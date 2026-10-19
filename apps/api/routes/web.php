<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

// The site root opens the web shell: a static export LiteSpeed serves from public/app/ on this same origin
// (ADR 0006, step 0.7). A relative Location keeps the scheme and host the visitor used (Cloudflare in front), and
// without the `web` group no session row or cookie is written for a redirect.
Route::get('/', fn (): RedirectResponse => new RedirectResponse('/app/', 302))
    ->withoutMiddleware('web')
    ->name('web-shell');
