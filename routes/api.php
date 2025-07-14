use App\Http\Controllers\InvoiceWebController;
use App\Http\Controllers\Auth\ApiAuthController;
use App\Http\Controllers\ImportController;

Route::get('/invoices', [InvoiceWebController::class, 'apiIndex']);
Route::get('/invoices/{invoice}', [InvoiceWebController::class, 'apiShow']);
Route::post('/login', [ApiAuthController::class, 'login']);

// Admin routes (protected by auth:sanctum middleware)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/imports', [ImportController::class, 'store']);
    Route::put('/imports/{id}', [ImportController::class, 'update']);
    Route::delete('/imports/{id}', [ImportController::class, 'destroy']);
    Route::get('/imports', [ImportController::class, 'index']);
});

// Public route
Route::get('/public/imports', [ImportController::class, 'publicIndex']); 