<?php

use App\Http\Controllers\Admin\AgentController as AdminAgentController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\RealtyController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\AgentJoinController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicOfferController;
use App\Http\Controllers\PublicRealtyController;
use App\Http\Controllers\Realty\AgentController;
use App\Http\Controllers\Realty\OfferController;
use App\Http\Controllers\Realty\OverviewController;
use App\Http\Controllers\Realty\PaymentPlanController;
use App\Http\Controllers\Realty\ProjectController;
use App\Http\Controllers\Realty\UnitController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

// Called by the Next.js server, never straight from the browser.

// Public
Route::get('/realties', [PublicRealtyController::class, 'index']);
Route::get('/realties/{slug}', [PublicRealtyController::class, 'show']);
Route::get('/offers/{code}', [PublicOfferController::class, 'show'])->middleware('throttle:60,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Invite links (the token is the credential)
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/register/{token}', [RegistrationController::class, 'show']);
    Route::post('/register/{token}', [RegistrationController::class, 'store']);
    Route::get('/join/{token}', [AgentJoinController::class, 'show']);
    Route::post('/join/{token}', [AgentJoinController::class, 'store']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // jvconline admin
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats', StatsController::class);
        Route::get('/realties', [RealtyController::class, 'index']);
        Route::post('/realties', [RealtyController::class, 'store']);
        Route::get('/realties/{realty}', [RealtyController::class, 'show']);
        Route::post('/realties/{realty}/invite', [RealtyController::class, 'invite']);
        Route::get('/offers', [AdminOfferController::class, 'index']);
        Route::get('/people', [AdminAgentController::class, 'index']);
    });

    // A realty's own dashboard — every query is scoped to the signed-in user's realty
    Route::prefix('realty')->group(function () {
        Route::middleware('realty.member')->group(function () {
            Route::get('/overview', OverviewController::class);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/offers', [OfferController::class, 'index']);
            Route::post('/offers', [OfferController::class, 'store']);
            Route::post('/offers/{offer}/void', [OfferController::class, 'void']);
        });
        Route::middleware('realty.member:staff')->group(function () {
            Route::get('/agents', [AgentController::class, 'index']);
            Route::post('/agents', [AgentController::class, 'store']);
            Route::post('/agents/invitations/{invitation}/resend', [AgentController::class, 'resend']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::post('/projects/{project}', [ProjectController::class, 'update']); // POST, not PATCH: multipart cover upload
            Route::post('/projects/{project}/units', [UnitController::class, 'store']);
            Route::post('/units/{unit}', [UnitController::class, 'update']);
            Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
            Route::post('/projects/{project}/plans', [PaymentPlanController::class, 'store']);
            Route::post('/plans/{plan}', [PaymentPlanController::class, 'update']);
            Route::delete('/plans/{plan}', [PaymentPlanController::class, 'destroy']);
        });
    });
});
