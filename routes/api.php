<?php

use App\Http\Controllers\CustomPageController;
use App\Http\Controllers\GhlWebhookController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

// GHL Custom Page AJAX Endpoints
Route::post('/custom-page/get', [CustomPageController::class, 'getCredentials']);
Route::post('/custom-page/save', [CustomPageController::class, 'saveCredentials']);
Route::post('/custom-page/test', [CustomPageController::class, 'testConnection']);
Route::post('/custom-page/toggle-sync', [CustomPageController::class, 'toggleSync']);
Route::post('/custom-page/calendars', [CustomPageController::class, 'getCalendars']);
Route::get('/custom-page/calendars', [CustomPageController::class, 'getCalendars']);
Route::post('/custom-page/logs', [CustomPageController::class, 'getLogs']);
Route::get('/custom-page/logs', [CustomPageController::class, 'getLogs']);

// GHL Marketplace Webhooks (Uninstall, Disconnect)
Route::post('/webhook', [GhlWebhookController::class, 'ghlWebhook']);
Route::post('/webhooks/ghl', [GhlWebhookController::class, 'ghlWebhook']);

// Diagnostic Logs
Route::get('/logs', [LogViewerController::class, 'getLogs']);
