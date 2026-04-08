<?php

use Aviagram\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::post('aviagram/callback/{callbackKey}', [CallbackController::class, 'handle'])->name('aviagram.callback');
});
