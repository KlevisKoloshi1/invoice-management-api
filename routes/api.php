use App\Http\Controllers\InvoiceWebController;
use App\Http\Controllers\Auth\ApiAuthController;

Route::get('/invoices', [InvoiceWebController::class, 'apiIndex']);
Route::get('/invoices/{invoice}', [InvoiceWebController::class, 'apiShow']);
Route::post('/login', [ApiAuthController::class, 'login']); 