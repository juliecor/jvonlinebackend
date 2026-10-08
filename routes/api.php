<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccreditationController;
use App\Http\Controllers\Admin\AgentController as AdminAgentController;
use App\Http\Controllers\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Admin\RealtyController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\AgentJoinController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicOfferController;
use App\Http\Controllers\PublicRealtyController;
use App\Http\Controllers\Realty\AgentController;
use App\Http\Controllers\Realty\AssistantController;
use App\Http\Controllers\Realty\OfferController;
use App\Http\Controllers\Realty\OverviewController;
use App\Http\Controllers\Realty\PaymentPlanController;
use App\Http\Controllers\Realty\ProjectController;
use App\Http\Controllers\Realty\ProjectPageController;
use App\Http\Controllers\Realty\ProjectUpdateController;
use App\Http\Controllers\Realty\RealtyAccreditationController;
use App\Http\Controllers\Realty\RequirementTypeController;
use App\Http\Controllers\Realty\UnitController;
use App\Http\Controllers\Realty\UnitTypeController;
use Illuminate\Support\Facades\Route;

// Called by the Next.js server, never straight from the browser.

// Public
Route::get('/realties', [PublicRealtyController::class, 'index']);
Route::get('/realties/{slug}', [PublicRealtyController::class, 'show']);
Route::get('/realties/{slug}/projects', [PublicRealtyController::class, 'projects']);
Route::get('/realties/{slug}/projects/{projectSlug}', [PublicRealtyController::class, 'project']);
Route::get('/offers/{code}', [PublicOfferController::class, 'show'])->middleware('throttle:offer-view');
Route::post('/offers/{code}/respond', [PublicOfferController::class, 'respond'])->middleware('throttle:offer-respond');
Route::post('/offers/{code}/unlock', [PublicOfferController::class, 'unlock'])->middleware('throttle:login');
Route::middleware('throttle:offer-upload')->group(function () {
    Route::post('/offers/{code}/details', [PublicOfferController::class, 'details']);
    Route::post('/offers/{code}/documents', [PublicOfferController::class, 'upload']);
    Route::post('/offers/{code}/documents/{document}/remove', [PublicOfferController::class, 'removeDocument'])->whereNumber('document');
});
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Invite links (the token is the credential)
Route::middleware('throttle:invite-link')->group(function () {
    Route::get('/accreditation/{token}', [AccreditationController::class, 'show']);
    Route::post('/accreditation/{token}', [AccreditationController::class, 'store']);
    Route::get('/join/{token}', [AgentJoinController::class, 'show']);
    Route::post('/join/{token}', [AgentJoinController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'view-as'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/view-as', [AuthController::class, 'viewAsOptions']);
    Route::post('/auth/view-as', [AuthController::class, 'viewAs']);

    // Everyone's own account: name, email, phone and password.
    Route::get('/account', [AccountController::class, 'show']);
    Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:login');
    Route::post('/account/password', [AccountController::class, 'password'])->middleware('throttle:login');

    // jvconline admin
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats', StatsController::class);
        Route::get('/realties', [RealtyController::class, 'index']);
        Route::get('/realties/{realty}', [RealtyController::class, 'show']);
        Route::get('/offers', [AdminOfferController::class, 'index']);
        Route::get('/people', [AdminAgentController::class, 'index']);
    });

    // A realty's own dashboard — every query is scoped to the signed-in user's realty.
    // A broker (an accredited realty) works on its developer's inventory, but only the
    // developer's own team edits it: those routes say `developer`.
    Route::prefix('realty')->group(function () {
        Route::middleware('realty.member')->group(function () {
            Route::get('/overview', OverviewController::class);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/offers', [OfferController::class, 'index']);
            Route::get('/offers/responses', [OfferController::class, 'responses']);
            Route::get('/offers/{id}', [OfferController::class, 'show'])->whereNumber('id');
            Route::post('/offers', [OfferController::class, 'store']);
            Route::post('/offers/{offer}/void', [OfferController::class, 'void'])->whereNumber('offer');
            Route::get('/offers/{id}/documents/{document}', [OfferController::class, 'document'])->whereNumber(['id', 'document']);
            Route::post('/offers/{id}/remind', [OfferController::class, 'remind'])->whereNumber('id');
            Route::post('/offers/{id}/send', [OfferController::class, 'send'])->whereNumber('id');
            Route::post('/offers/{id}/terms', [OfferController::class, 'terms'])->whereNumber('id');
            Route::post('/offers/{id}/login', [OfferController::class, 'login'])->whereNumber('id');
        });
        // The developer's team: staff and agents of Johndorf, not of a broker.
        Route::middleware('realty.member:any,developer')->group(function () {
            // The AI assistant: each with their own chats.
            Route::get('/assistant/chats', [AssistantController::class, 'index']);
            Route::get('/assistant/chats/{chat}', [AssistantController::class, 'show'])->whereNumber('chat');
            Route::delete('/assistant/chats/{chat}', [AssistantController::class, 'destroy'])->whereNumber('chat');
            Route::post('/assistant/messages', [AssistantController::class, 'send'])->middleware('throttle:assistant');
            Route::post('/assistant/stream', [AssistantController::class, 'stream'])->middleware('throttle:assistant');
            Route::post('/assistant/transcribe', [AssistantController::class, 'transcribe'])->middleware('throttle:assistant-voice');
            // Buyers' files are checked by the developer, not by the firm that sold.
            Route::post('/offers/{id}/documents/{document}/review', [OfferController::class, 'review'])->whereNumber(['id', 'document']);
        });
        // Any realty's admins manage their own agents.
        Route::middleware('realty.member:staff')->group(function () {
            Route::get('/agents', [AgentController::class, 'index']);
            Route::post('/agents', [AgentController::class, 'store']);
            Route::post('/agents/invitations/{invitation}/resend', [AgentController::class, 'resend']);
            Route::post('/agents/{agent}/approve', [AgentController::class, 'approve'])->whereNumber('agent');
            Route::post('/agents/{agent}/reject', [AgentController::class, 'reject'])->whereNumber('agent');
            Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->whereNumber('agent');
        });
        // The developer's admins: approve terms, set unit status, edit the inventory.
        Route::middleware('realty.member:staff,developer')->group(function () {
            // Team > Realties: invite a realty, review its accreditation form, accept or turn it down.
            Route::get('/realties', [RealtyAccreditationController::class, 'index']);
            Route::post('/realties/invite', [RealtyAccreditationController::class, 'invite']);
            Route::post('/realties/invitations/{accreditation}/resend', [RealtyAccreditationController::class, 'resend'])->whereNumber('accreditation');
            Route::get('/realties/accreditations/{accreditation}', [RealtyAccreditationController::class, 'show'])->whereNumber('accreditation');
            Route::get('/realties/accreditations/{accreditation}/documents/{document}', [RealtyAccreditationController::class, 'document'])->whereNumber(['accreditation', 'document']);
            Route::post('/realties/accreditations/{accreditation}/approve', [RealtyAccreditationController::class, 'approve'])->whereNumber('accreditation');
            Route::post('/realties/accreditations/{accreditation}/reject', [RealtyAccreditationController::class, 'reject'])->whereNumber('accreditation');
            Route::post('/realties/accreditations/{accreditation}/resend-login', [RealtyAccreditationController::class, 'resendLogin'])->whereNumber('accreditation');
            Route::post('/offers/{id}/approval', [OfferController::class, 'approval'])->whereNumber('id');
            Route::post('/offers/{id}/unit-status', [OfferController::class, 'unitStatus'])->whereNumber('id');
            Route::get('/requirements', [RequirementTypeController::class, 'index']);
            Route::post('/requirements', [RequirementTypeController::class, 'store']);
            Route::post('/requirements/{type}', [RequirementTypeController::class, 'update']);
            Route::post('/requirements/{type}/move', [RequirementTypeController::class, 'move']);
            Route::delete('/requirements/{type}', [RequirementTypeController::class, 'destroy']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::post('/projects/{project}', [ProjectController::class, 'update']); // POST, not PATCH: multipart cover upload
            Route::post('/projects/{project}/status', [ProjectController::class, 'status']);
            Route::post('/projects/{project}/units', [UnitController::class, 'store']);
            Route::post('/units/{unit}', [UnitController::class, 'update']);
            Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
            Route::post('/projects/{project}/plans', [PaymentPlanController::class, 'store']);
            Route::post('/plans/{plan}', [PaymentPlanController::class, 'update']);
            Route::delete('/plans/{plan}', [PaymentPlanController::class, 'destroy']);
            // Public project page
            Route::post('/projects/{project}/page', [ProjectPageController::class, 'settings']);
            Route::post('/projects/{project}/page/media', [ProjectPageController::class, 'addMedia']);
            Route::post('/projects/{project}/page/media/remove', [ProjectPageController::class, 'removeMedia']);
            Route::post('/projects/{project}/unit-types', [UnitTypeController::class, 'store']);
            Route::post('/unit-types/{unitType}', [UnitTypeController::class, 'update']);
            Route::delete('/unit-types/{unitType}', [UnitTypeController::class, 'destroy']);
            Route::post('/projects/{project}/updates', [ProjectUpdateController::class, 'store']);
            Route::post('/updates/{update}/remove-photo', [ProjectUpdateController::class, 'removePhoto']);
            Route::delete('/updates/{update}', [ProjectUpdateController::class, 'destroy']);
        });
    });
});
