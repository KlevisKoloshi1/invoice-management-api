<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImportWebController;
use App\Http\Controllers\InvoiceWebController;

Route::get('/', function () {
    return view('welcome');
});

// Admin routes (should be protected by auth middleware in real use)
// Route::post('/api/imports', [ImportController::class, 'store']);
// Route::put('/api/imports/{id}', [ImportController::class, 'update']);
// Route::delete('/api/imports/{id}', [ImportController::class, 'destroy']);
// Route::get('/api/imports', [ImportController::class, 'index']);

// Public route
// Route::get('/public/imports', [ImportController::class, 'publicIndex']);

// Allow all logged-in users to view uploads
Route::get('/imports', [ImportWebController::class, 'index'])->name('imports.index');

// Only admins can create, edit, update, delete
Route::middleware(['auth'])->group(function () {
    Route::get('/imports/create', [ImportWebController::class, 'create'])->name('imports.create');
    Route::post('/imports', [ImportWebController::class, 'store'])->name('imports.store');

    // Invoice edit/delete for logged-in users (creator check is in controller)
    Route::get('/invoices/{invoice}/edit', [InvoiceWebController::class, 'edit'])->name('invoices.edit');
    Route::put('/invoices/{invoice}', [InvoiceWebController::class, 'update'])->name('invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceWebController::class, 'destroy'])->name('invoices.destroy');
});
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/imports/{import}/edit', [ImportWebController::class, 'edit'])->name('imports.edit');
    Route::put('/imports/{import}', [ImportWebController::class, 'update'])->name('imports.update');
    Route::delete('/imports/{import}', [ImportWebController::class, 'destroy'])->name('imports.destroy');
});

// Public web interface
Route::get('/public/imports', [ImportWebController::class, 'publicIndex'])->name('imports.public');

// Invoice details
Route::get('/invoices', [InvoiceWebController::class, 'index'])->name('invoices.index');
Route::get('/invoices/{invoice}', [InvoiceWebController::class, 'show'])->name('invoices.show');
Route::get('/invoices/create', [InvoiceWebController::class, 'create'])->name('invoices.create');
Route::post('/invoices', [InvoiceWebController::class, 'store'])->name('invoices.store');

// New route to show a summary page with links to all generated invoices after import (for multiple invoices)
Route::get('/imports/invoices', [ImportWebController::class, 'importedInvoices'])->name('imports.invoices');

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');