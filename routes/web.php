<?php

use App\Http\Controllers\KannelDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [KannelDashboardController::class, 'index'])->name('home');
    Route::get('dashboard', [KannelDashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/kannel.php';
