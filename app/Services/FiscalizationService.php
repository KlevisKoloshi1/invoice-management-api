<?php

namespace App\Services;

use App\Models\Invoice;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class FiscalizationService
{
    protected $client;
    protected $config;

    public function __construct()
    {
        $this->client = new Client();
        $this->config = config('services.fiscalization');
    }

    public function fiscalize(Invoice $invoice, $meta = [])
    {
        try {
            // Fetch invoice items
            $items = $invoice->items()->get();
            $details = [];
            foreach ($items as $item) {
                $details[] = [
                    'item_code' => (string)($item->id),
                    'item_name' => (string)($item->item_name ?? $item->description ?? 'Unknown'),
                    'item_quantity' => (float)$item->quantity,
                    'item_price' => (float)$item->price,
                    'item_total_before_vat' => (float)($item->item_total_before_vat ?? 0),
                    'item_vat_amount' => (float)($item->item_vat_amount ?? 0),
                    'item_vat_rate' => (float)($item->item_vat_rate ?? $item->vat_rate ?? 0),
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

            // Build ServerConfig as per API docs
            $serverConfig = [
                'Url_API' => $this->config['url'],
                'DB_Config' => $this->config['db_config'],
                'Company_DB_Name' => $this->config['company_db_name'],
            ];

            // Use meta fields if provided, else fallback to invoice fields
            $invoiceNumber = $meta['number'] ?? $invoice->number ?? null;
            $invoiceDate = $meta['date'] ?? $invoice->invoice_date ?? ($invoice->created_at ? $invoice->created_at->format('Y-m-d') : now()->format('Y-m-d'));
            $clientTIN = $meta['client_tin'] ?? $invoice->client->tin ?? null;

            // Build payload as per Elif API docs
            $payload = [
                'body' => [
                    [
                        'cmd' => 'insert',
                        'sales_date' => $invoice->invoice_date ? $invoice->invoice_date . (strlen($invoice->invoice_date) <= 10 ? ' 00:00' : '') : null,
                        'invoice_number' => $invoice->number,
                        'business_unit' => $invoice->business_unit,
                        'issuer_tin' => $invoice->issuer_tin,
                        'invoice_type' => $invoice->invoice_type,
                        'is_e_invoice' => (bool)$invoice->is_e_invoice,
                        'operator_code' => $invoice->operator_code,
                        'software_code' => $invoice->software_code,
                        'payment_method' => $invoice->payment_method,
                        'total_amount' => (float)$invoice->total,
                        'total_before_vat' => (float)$invoice->total_before_vat,
                        'vat_amount' => (float)$invoice->vat_amount,
                        'vat_rate' => (float)$invoice->vat_rate,
                        'buyer_name' => $invoice->buyer_name,
                        'buyer_address' => $invoice->buyer_address,
                        'buyer_tax_number' => $invoice->buyer_tax_number,
                        'details' => $details,
                    ]
                ],
                'IsEncrypted' => false,
                'ServerConfig' => json_encode($serverConfig),
                'App' => 'web',
                'Language' => 'sq-AL',
            ];

            // Log the full payload including details
            \Log::info('Fiscalization request for invoice ' . $invoice->id, ['payload' => $payload]);

            $response = $this->client->post($this->config['url'] . '/sales.php', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'App' => 'web',
                ],
                'json' => $payload
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);
            \Log::info('Fiscalization response for invoice ' . $invoice->id, ['response' => $responseBody]);

            $invoice->fiscalization_response = json_encode($responseBody);
            // Handle status codes and qrcode_url as per API docs
            if (isset($responseBody['status']['code']) && $responseBody['status']['code'] == 600 && isset($responseBody['qrcode_url']) && $responseBody['qrcode_url']) {
                $invoice->fiscalized = true;
                $invoice->fiscalization_status = 'success';
                $invoice->fiscalization_url = $responseBody['qrcode_url'];
                $invoice->fiscalized_at = now();
                // Save all required fields for verification URL
                $invoice->iic = $responseBody['iic'] ?? null;
                $invoice->tin = $responseBody['tin'] ?? $clientTIN ?? null;
                $invoice->crtd = $responseBody['crtd'] ?? ($invoiceDate ? $invoiceDate . 'T00:00:00+01:00' : null);
                $invoice->ord = $responseBody['ord'] ?? null;
                $invoice->bu = $responseBody['bu'] ?? null;
                $invoice->cr = $responseBody['cr'] ?? null;
                $invoice->sw = $responseBody['sw'] ?? null;
                $invoice->prc = $responseBody['prc'] ?? $invoice->total ?? null;
            } else {
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $errorMsg = isset($responseBody['status']['message']) ? $responseBody['status']['message'] : (isset($responseBody['error']) ? $responseBody['error'] : 'Unknown error');
                $invoice->fiscalization_response = $errorMsg;
            }
            $invoice->save();
            // Return the verification URL if fiscalized
            if ($invoice->fiscalized) {
                return $this->generateVerificationUrl($invoice);
            }
            return null;
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