<?php

declare(strict_types=1);

use App\Core\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

// Same answer the Node API gave at GET /health (Render's health check).
Route::get('/health', static fn () => response()->json([
    'status' => 'OK',
    'timestamp' => ApiResponse::timestamp(),
]));
