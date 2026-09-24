<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrintingTestController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TerminalController;
use App\Http\Controllers\Admin\TerminalDeviceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\PinLoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UnregisteredTerminalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/terminal/unregistered', UnregisteredTerminalController::class)->name('terminal.unregistered');

Route::middleware(['guest', 'terminal'])->group(function () {
    Route::get('/pin-login', [PinLoginController::class, 'create'])->name('pin-login');
    Route::post('/pin-login', [PinLoginController::class, 'store'])->name('pin-login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/account', [AccountController::class, 'show'])->name('account');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);

        Route::resource('terminals', TerminalController::class)->except(['show', 'destroy']);
        Route::get('terminals/{terminal}/register', [TerminalDeviceController::class, 'show'])->name('terminals.register');
        Route::post('terminals/{terminal}/register', [TerminalDeviceController::class, 'store'])->name('terminals.register.store');
        Route::delete('terminals/{terminal}/register', [TerminalDeviceController::class, 'destroy'])->name('terminals.register.destroy');

        Route::resource('printers', PrinterController::class)->except(['show', 'destroy']);

        Route::get('printing-test', [PrintingTestController::class, 'index'])->name('printing-test');
        Route::get('printing-test/thermal', [PrintingTestController::class, 'thermal'])->name('printing-test.thermal');
        Route::get('printing-test/pdf', [PrintingTestController::class, 'pdf'])->name('printing-test.pdf');

        Route::middleware('can:admin.settings.manage')->group(function () {
            Route::get('settings/{group}', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
        });

        Route::get('audit', [AuditLogController::class, 'index'])->middleware('can:admin.audit.view')->name('audit.index');
    });
});
