use App\Http\Controllers\InvoiceWebController;

Route::get('/invoices', [InvoiceWebController::class, 'apiIndex']);
Route::get('/invoices/{invoice}', [InvoiceWebController::class, 'apiShow']); 