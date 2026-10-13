<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Rep Tracker API',
    'docs' => 'See README.md',
    'example' => url('/api/v1/reps'),
]));
