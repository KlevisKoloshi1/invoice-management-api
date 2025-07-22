<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Services\FiscalizationService;

class FiscalizeInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:fiscalize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send created invoices to the Tax Authorities for fiscalization';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = new FiscalizationService();
        $invoices = Invoice::where('fiscalized', false)->get();
        foreach ($invoices as $invoice) {
            $warehouseId = $invoice->warehouse_id ?? $invoice->items()->first()->warehouse_id ?? null;
            if (!$warehouseId) {
                \Log::error('Skipping fiscalization for invoice ' . $invoice->id . ': warehouse_id is missing.');
                continue;
            }
            $service->fiscalize($invoice, ['warehouse_id' => $warehouseId]);
        }
        $this->info('Fiscalization process completed.');
    }
}
