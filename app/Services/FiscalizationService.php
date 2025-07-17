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
            // LOGIN STEP: Get token from /login.php
            $loginPayload = [
                'body' => [
                    'username' => $this->config['username'],
                    'password' => $this->config['password'],
                ]
            ];
            // Use a Guzzle cookie jar to persist cookies between login and sales
            $jar = new \GuzzleHttp\Cookie\CookieJar();
            $loginResponse = $this->client->post($this->config['url'] . '/login.php', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'App' => 'web',
                ],
                'json' => $loginPayload,
                'cookies' => $jar,
            ]);
            $loginBody = json_decode($loginResponse->getBody()->getContents(), true);
            \Log::info('Fiscalization login response', ['loginBody' => $loginBody]);
            $loginUser = $loginBody['body'][0] ?? [];
            $token = $loginUser['token'] ?? null;
            if (!$token) {
                \Log::error('Fiscalization login failed: No token received', ['loginBody' => $loginBody]);
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $invoice->fiscalization_response = 'Login failed: No token received.';
                $invoice->save();
                return null;
            }

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

            // Build payload as per Elif API docs, with token, user_id, and username inside the invoice body
            $payload = [
                'body' => [
                    array_merge([
                        'cmd' => 'insert',
                        'sales_date' => $invoiceDate ? (strlen($invoiceDate) <= 10 ? $invoiceDate . ' 00:00' : $invoiceDate) : null,
                        'invoice_number' => $invoiceNumber,
                        'business_unit' => $meta['business_unit'] ?? $invoice->business_unit ?? null,
                        'issuer_tin' => $meta['issuer_tin'] ?? $invoice->issuer_tin ?? null,
                        'invoice_type' => $meta['invoice_type'] ?? $invoice->invoice_type ?? null,
                        'is_e_invoice' => isset($meta['is_e_invoice']) ? (bool)$meta['is_e_invoice'] : (bool)$invoice->is_e_invoice,
                        'operator_code' => $meta['operator_code'] ?? $invoice->operator_code ?? null,
                        'software_code' => $meta['software_code'] ?? $invoice->software_code ?? null,
                        'payment_method' => $meta['payment_method'] ?? $invoice->payment_method ?? null,
                        'total_amount' => isset($meta['total_amount']) ? (float)$meta['total_amount'] : (float)$invoice->total,
                        'total_before_vat' => isset($meta['total_before_vat']) ? (float)$meta['total_before_vat'] : (float)$invoice->total_before_vat,
                        'vat_amount' => isset($meta['vat_amount']) ? (float)$meta['vat_amount'] : (float)$invoice->vat_amount,
                        'vat_rate' => isset($meta['vat_rate']) ? (float)$meta['vat_rate'] : (float)$invoice->vat_rate,
                        'buyer_name' => $meta['buyer_name'] ?? $invoice->buyer_name ?? null,
                        'buyer_address' => $meta['buyer_address'] ?? $invoice->buyer_address ?? null,
                        'buyer_tax_number' => $meta['buyer_tax_number'] ?? $invoice->buyer_tax_number ?? null,
                        'details' => $details,
                    ], [
                        'token' => $token,
                        'user_id' => $loginUser['user_id'] ?? null,
                        'username' => $loginUser['username'] ?? null,
                    ])
                ],
                'IsEncrypted' => false,
                'ServerConfig' => json_encode($serverConfig),
                'App' => 'web',
                'Language' => 'sq-AL',
            ];
            // Only use standard headers, no token headers
            $headers = [
                'Content-Type' => 'application/json',
                'App' => 'web',
            ];
            \Log::info('Fiscalization sales.php payload', ['headers' => $headers, 'payload' => $payload]);
            $response = $this->client->post($this->config['url'] . '/sales.php', [
                'headers' => $headers,
                'json' => $payload,
                'cookies' => $jar,
            ]);
            $responseBody = json_decode($response->getBody()->getContents(), true);
            \Log::info('Fiscalization response for invoice ' . $invoice->id, ['response' => $responseBody]);
            if (isset($responseBody['status']['code']) && $responseBody['status']['code'] == 600) {
                $invoice->fiscalization_response = json_encode($responseBody);
                $invoice->fiscalized = true;
                $invoice->fiscalization_status = 'success';
                $invoice->fiscalization_url = $responseBody['qrcode_url'] ?? null;
                $invoice->fiscalized_at = now();
                $invoice->iic = $responseBody['iic'] ?? null;
                $invoice->tin = $responseBody['tin'] ?? $clientTIN ?? null;
                $invoice->crtd = $responseBody['crtd'] ?? ($invoiceDate ? $invoiceDate . 'T00:00:00+01:00' : null);
                $invoice->ord = $responseBody['ord'] ?? null;
                $invoice->bu = $responseBody['bu'] ?? null;
                $invoice->cr = $responseBody['cr'] ?? null;
                $invoice->sw = $responseBody['sw'] ?? null;
                $invoice->prc = $responseBody['prc'] ?? $invoice->total ?? null;
                $invoice->save();
                return $this->generateVerificationUrl($invoice);
            } else {
                $invoice->fiscalized = false;
                $invoice->fiscalization_status = 'failed';
                $errorMsg = isset($responseBody['status']['message']) ? $responseBody['status']['message'] : (isset($responseBody['error']) ? $responseBody['error'] : 'Unknown error');
                $invoice->fiscalization_response = $errorMsg;
                $invoice->save();
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