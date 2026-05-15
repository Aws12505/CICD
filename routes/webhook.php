<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/github', [WebhookController::class, 'github'])->name('webhook.github');
