<?php

use App\Http\Controllers\KannelAlertRulesController;
use App\Http\Controllers\KannelEndpointsController;
use App\Http\Controllers\KannelStoreStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:manage_kannel'])->group(function () {
    Route::get('store-status', [KannelStoreStatusController::class, 'index'])->name('kannel.store-status.index');

    Route::post('endpoints/{endpoint}/test', [KannelEndpointsController::class, 'test'])->name('kannel.endpoints.test');
    Route::get('endpoints', [KannelEndpointsController::class, 'index'])->name('kannel.endpoints.index');
    Route::resource('endpoints', KannelEndpointsController::class)->names('kannel.endpoints')->only(['store', 'update', 'destroy']);

    Route::get('alert-rules', [KannelAlertRulesController::class, 'index'])->name('kannel.alert-rules.index');
    Route::resource('alert-rules', KannelAlertRulesController::class)->names('kannel.alert-rules')->only(['store', 'update', 'destroy']);
});
