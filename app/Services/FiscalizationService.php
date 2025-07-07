<?php

namespace App\Services;

use App\Models\Invoice;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class FiscalizationService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function fiscalize(Invoice $invoice)
    {
        try {
            // Fetch invoice items
            $items = $invoice->items()->get();
            $details = [];
            foreach ($items as $item) {
                $details[] = [
                    'item_code' => (string)($item->id),
                    'item_name' => (string)($item->description ?? 'Unknown'),
                    'item_quantity' => (float)$item->quantity,
                    'item_price' => (float)$item->price,
                    'tax_rate' => isset($item->tax_rate) ? (float)$item->tax_rate : 20.0,
                    'unit' => isset($item->unit) ? (string)$item->unit : 'pcs',
                ];
            }

            // Check for required fields
            if (empty($details)) {
                \Log::warning('Fiscalization skipped for invoice ' . $invoice->id . ': No items found.');
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $invoice->fiscalization_response = 'No items found for fiscalization.';
                $invoice->save();
                return;
            }
            if (!$invoice->client || empty($invoice->client->name)) {
                \Log::warning('Fiscalization skipped for invoice ' . $invoice->id . ': Missing client info.');
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $invoice->fiscalization_response = 'Missing client info.';
                $invoice->save();
                return;
            }

            $payload = [
                'body' => [
                    [
                        'cmd' => 'insert',
                        'sales_date' => $invoice->created_at ? $invoice->created_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
                        'customer_name' => (string)$invoice->client->name,
                        'exchange_rate' => 1,
                        'city_id' => 1,
                        'warehouse_id' => 1,
                        'automatic_payment_method_id' => 1,
                        'currency_id' => 1,
                        'sales_document_serial' => null,
                        'cash_register_id' => 1,
                        'fiscal_delay_reason_type' => null,
                        'fiscal_invoice_type_id' => 1,
                        'fiscal_profile_id' => 1,
                        'details' => $details,
                    ]
                ]
            ];

            // Log the full payload including details
            \Log::info('Fiscalization request for invoice ' . $invoice->id, ['payload' => $payload]);

            $response = $this->client->post('https://efiskalizimi-app-test.tatime.gov.al/sales.php', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'App' => 'web',
                ],
                'json' => $payload
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);
            \Log::info('Fiscalization response for invoice ' . $invoice->id, ['response' => $responseBody]);

            $invoice->fiscalization_response = json_encode($responseBody);
            if (isset($responseBody['qrcode_url']) && $responseBody['qrcode_url']) {
                $invoice->fiscalized = true;
                $invoice->fiscalization_status = 'success';
                $invoice->fiscalization_url = $responseBody['qrcode_url'];
                $invoice->fiscalized_at = now();
            } else if (isset($responseBody['error'])) {
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $invoice->fiscalization_response = $responseBody['error'];
            } else {
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                \Log::warning('Fiscalization API returned null for invoice ' . $invoice->id, ['payload' => $payload]);
            }
            $invoice->save();
        } catch (\Exception $e) {
            \Log::error('Fiscalization failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            $invoice->fiscalized = false;
            $invoice->fiscalization_status = 'failed';
            $invoice->fiscalization_response = $e->getMessage();
            $invoice->save();
        }
    }

    public function generateVerificationUrl(Invoice $invoice)
    {
        $params = http_build_query([
            'iic' => $invoice->iic,
            'tin' => $invoice->tin,
            'crtd' => $invoice->crtd,
            'ord' => $invoice->ord,
            'bu' => $invoice->bu,
            'cr' => $invoice->cr,
            'sw' => $invoice->sw,
            'prc' => $invoice->prc,
        ]);
        return 'https://eFiskalizimi-app-test.tatime.gov.al/invoicecheck/#/verify?' . $params;
    }
} 