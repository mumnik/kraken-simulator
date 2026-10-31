<?php

use Illuminate\Support\Facades\Route;

// No UI — this service is a pure API emulator. A GET / exists only so a
// human who wanders here (or a container healthcheck pointed at / instead
// of the real /up) gets something readable, not Laravel's default welcome
// page or a blank 404.
Route::get('/', fn () => response()->json([
    'service' => 'kraken-simulator',
    'docs' => 'See this repo\'s README for /0/public, /0/private and /internal endpoints.',
]));
