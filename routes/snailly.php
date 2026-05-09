<?php

use App\Http\Controllers\SnaillyApiController;
use App\Http\Controllers\SnaillyPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Snailly Laravel Routes
|--------------------------------------------------------------------------
| Tambahkan baris berikut di routes/web.php:
| require __DIR__ . '/snailly.php';
*/

$snaillyCsrfMiddleware = [];
if (class_exists(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)) {
    $snaillyCsrfMiddleware[] = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;
}
if (class_exists(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)) {
    $snaillyCsrfMiddleware[] = \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class;
}

Route::get('/snailly', SnaillyPageController::class)->name('snailly.home');
Route::get('/snailly/blocked', SnaillyPageController::class)->name('snailly.blocked');

$snaillyProxyRoute = Route::match(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], '/api/snailly/proxy', [SnaillyApiController::class, 'proxy']);
$snaillyTrackRoute = Route::match(['POST', 'OPTIONS'], '/api/snailly/track', [SnaillyApiController::class, 'track']);
$snaillyBlocklistRoute = Route::match(['GET', 'POST', 'OPTIONS'], '/api/snailly/blocklist', [SnaillyApiController::class, 'blocklist']);

if ($snaillyCsrfMiddleware !== []) {
    $snaillyProxyRoute->withoutMiddleware($snaillyCsrfMiddleware);
    $snaillyTrackRoute->withoutMiddleware($snaillyCsrfMiddleware);
    $snaillyBlocklistRoute->withoutMiddleware($snaillyCsrfMiddleware);
}
