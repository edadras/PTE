<?php

declare(strict_types=1);

use App\Domain\Telegram\Middleware\ResolveTenantFromBot;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram webhook routes
|--------------------------------------------------------------------------
|
| Registered without the `web` group on purpose: Telegram sends no cookies and
| no CSRF token, and session middleware on the hottest endpoint in the product
| would be pure overhead.
|
| The throttle mirrors docs/12 §7 — 600/minute per bot is roughly ten updates a
| second, comfortably above real traffic and well below what a flood would need.
|
| @see docs/04-telegram-layer.md §3
*/

Route::post('/webhook/{botPublicId}', TelegramWebhookController::class)
    ->middleware([ResolveTenantFromBot::class, 'throttle:telegram-webhook'])
    ->name('telegram.webhook');
