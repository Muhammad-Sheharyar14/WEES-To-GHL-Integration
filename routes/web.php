<?php

use App\Http\Controllers\CustomPageController;
use App\Http\Controllers\GhlOAuthController;
use App\Http\Controllers\GhlWebhookController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Application Root (Redirects to Custom Settings Page)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect()->route('custom.page');
})->name('home');

/*
|--------------------------------------------------------------------------
| GoHighLevel OAuth Routes
|--------------------------------------------------------------------------
*/
Route::get('/connect', [GhlOAuthController::class, 'connect'])->name('ghl.connect');
Route::get('/oauth/ghl', [GhlOAuthController::class, 'connect']);
Route::get('/callback', [GhlOAuthController::class, 'callback'])->name('ghl.callback');
Route::get('/oauth/callback', [GhlOAuthController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| GoHighLevel Marketplace Inbound Webhooks
|--------------------------------------------------------------------------
*/
Route::post('/webhook', [GhlWebhookController::class, 'ghlWebhook'])->name('ghl.webhook');
Route::post('/webhooks/ghl', [GhlWebhookController::class, 'ghlWebhook']);
Route::post('/api/webhook', [GhlWebhookController::class, 'ghlWebhook']);

/*
|--------------------------------------------------------------------------
| GHL Custom Page (Embedded inside GoHighLevel Sub-Account Settings)
|--------------------------------------------------------------------------
*/
Route::get('/custom-page', [CustomPageController::class, 'index'])
    ->middleware('ghl.origin')
    ->name('custom.page');
Route::get('/custom_page.php', [CustomPageController::class, 'index'])->middleware('ghl.origin');

// Custom Page AJAX Endpoints
Route::post('/api/custom-page/get', [CustomPageController::class, 'getCredentials'])->name('api.custom-page.get');
Route::post('/api/custom-page/save', [CustomPageController::class, 'saveCredentials'])->name('api.custom-page.save');
Route::post('/api/custom-page/test', [CustomPageController::class, 'testConnection'])->name('api.custom-page.test');
Route::post('/api/custom-page/toggle-sync', [CustomPageController::class, 'toggleSync'])->name('api.custom-page.toggle-sync');
Route::post('/api/custom-page/logs', [CustomPageController::class, 'getLogs'])->name('api.custom-page.logs');
Route::get('/api/custom-page/logs', [CustomPageController::class, 'getLogs']);

/*
|--------------------------------------------------------------------------
| Logs and Diagnostics
|--------------------------------------------------------------------------
*/
Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
Route::get('/api/logs', [LogViewerController::class, 'getLogs'])->name('logs.api');
