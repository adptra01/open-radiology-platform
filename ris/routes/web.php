<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('reports', 'reports/index')->name('reports.index');

    // Halaman ORP (data diambil via /api/* — lihat routes/api.php).
    // Gating menu di frontend pakai permission; penegakan ada di API.
    Route::inertia('worklist', 'worklist/index')->name('worklist.index');
    Route::inertia('orders', 'orders/index')->name('orders.index');
    Route::inertia('orders/{order}', 'orders/show')->name('orders.show');
    Route::inertia('appointments', 'appointments/index')->name('appointments.index');
    Route::inertia('patients', 'patients/index')->name('patients.index');
    Route::inertia('studies', 'studies/index')->name('studies.index');
    Route::inertia('viewer', 'viewer/index')->name('viewer.index');
    Route::inertia('modalities', 'modalities/index')->name('modalities.index');
    Route::inertia('pacs', 'pacs/index')->name('pacs.index');
    Route::inertia('transmissions', 'transmissions/index')->name('transmissions.index');
    Route::inertia('audit', 'audit/index')->name('audit.index');
    Route::inertia('users', 'users/index')->name('users.index');
});

require __DIR__.'/settings.php';
