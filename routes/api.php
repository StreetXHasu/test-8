<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/api/doc');

Route::get('/health', [HealthController::class, 'live'])->name('health');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::get('/api/doc', [OpenApiController::class, 'ui'])->name('api.doc');
Route::get('/api/doc.json', [OpenApiController::class, 'spec'])->name('api.doc.json');

Route::post('/api/register', [AuthController::class, 'register'])->name('api.register');
Route::post('/api/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('jwt')->prefix('api/images')->name('api.images.')->group(static function (): void {
    Route::post('', [ImageController::class, 'store'])->name('upload');
    Route::get('', [ImageController::class, 'index'])->name('list');
    Route::get('/{image}', [ImageController::class, 'show'])->whereUuid('image')->name('show');
    Route::delete('/{image}', [ImageController::class, 'destroy'])->whereUuid('image')->name('delete');
});
