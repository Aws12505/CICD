<?php

use App\Http\Controllers\BuildWebhookController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/github', [WebhookController::class, 'github'])->name('webhook.github');

// Service repositories: a push to the default branch or an edition builds an
// image. Nothing deploys.
Route::post('/build', BuildWebhookController::class)->middleware('throttle:120,1')->name('webhook.build');
