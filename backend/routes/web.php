<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This app is an API-only backend (no web login form). Sanctum's auth
// middleware falls back to redirect(route('login')) for requests that
// don't ask for JSON; without this named route that redirect 500s
// instead of cleanly reporting "unauthenticated".
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
