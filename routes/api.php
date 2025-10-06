<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LinkController;

// -------------------- AUTH --------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// -------------------- SHORTLINK (Public / Hybrid) --------------------
Route::middleware('throttle:10,1')->post('/links', [LinkController::class, 'store']); // guest & user
Route::get('/links/{code}', [LinkController::class, 'show']);
Route::post('/links/{code}/continue', [LinkController::class, 'continue']);

// -------------------- AUTH PROTECTED (Only Logged In) --------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Statistik & kontrol link (hanya user)
    Route::get('/links/{code}/stats', [LinkController::class, 'stats']);
});
