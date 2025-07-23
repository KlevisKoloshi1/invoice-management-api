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
                    'sales_invoice_header_id' => null,
                    'sales_invoice_detail_id' => null,
                    'item_code' => $item->item_code ?? null,
                    'item_name' => $item->item_name ?? null,
                    'item_barcode' => null,
                    'item_total_with_tax_reporting_currency' => $item->item_total_with_tax ?? null,
                    'item_type_id' => $item->item_type_id ?? 1,
                    'item_price_without_tax' => $item->item_price_without_tax ?? null,
                    'item_price_with_tax' => $item->item_price_with_tax ?? null,
                    'item_sales_tax_percentage' => $item->item_sales_tax_percentage ?? null,
                    'item_total_without_tax' => $item->item_total_without_tax ?? null,
                    'item_quantity' => $item->quantity ?? null,
                    'item_total_with_tax' => $item->item_total_with_tax ?? null,
                    'item_total_tax' => $item->item_total_tax ?? null,
                    'item_unit_id' => $item->item_unit_id ?? null,
                    'tax_rate_id' => $item->tax_rate_id ?? null,
                    'item_id' => $item->item_id ?? null,
                    'warehouse_id' => $item->warehouse_id ?? null,
                    'cmd' => 'insert',
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
                'HardwareId' => $this->config['hardware_id'] ?? '',
                'UserInfo' => [
                    'user_id' => $loginUser['user_id'] ?? null,
                    'username' => $loginUser['username'] ?? null,
                    'token' => $token,
                ],
            ];

            // Use meta fields if provided, else fallback to invoice fields
            $invoiceNumber = $meta['number'] ?? $invoice->number ?? null;
            $invoiceDate = $invoice->invoice_date ?? ($invoice->created_at ? $invoice->created_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'));
            $clientTIN = $meta['client_tin'] ?? $invoice->client->tin ?? null;
            $customer_id = $meta['customer_id'] ?? 1;
            $city_id = $meta['city_id'] ?? 1;
            $automatic_payment_method_id = $meta['automatic_payment_method_id'] ?? 0;
            $currency_id = $meta['currency_id'] ?? 1;
            $cash_register_id = $meta['cash_register_id'] ?? 1;
            $fiscal_invoice_type_id = $meta['fiscal_invoice_type_id'] ?? 4;
            $fiscal_profile_id = $meta['fiscal_profile_id'] ?? 1;
            $warehouse_id = $meta['warehouse_id'] ?? $invoice->warehouse_id ?? ($items->first()->warehouse_id ?? null);
            // Build payload as per API docs
            $payload = [
                'body' => [
                    [
                        'cmd' => 'insert',
                        'sales_date' => $invoiceDate,
                        'customer_name' => $invoice->client->name,
                        'customer_id' => $customer_id,
                        'exchange_rate' => 1,
                        'city_id' => $city_id,
                        'warehouse_id' => $warehouse_id, // <-- Always include in header
                        'automatic_payment_method_id' => $automatic_payment_method_id,
                        'currency_id' => $currency_id,
                        'sales_document_serial' => '',
                        'cash_register_id' => $cash_register_id,
                        'fiscal_delay_reason_type' => null,
                        'fiscal_invoice_type_id' => $fiscal_invoice_type_id,
                        'fiscal_profile_id' => $fiscal_profile_id,
                        'paid_amount' => number_format($invoice->total, 2, '.', ''),
                        'customer_tax_id' => $clientTIN,
                        'details' => array_map(function($item) use ($warehouse_id) {
                            return [
                                'sales_invoice_header_id' => null,
                                'sales_invoice_detail_id' => null,
                                'item_code' => $item->item_code ?? null,
                                'item_name' => $item->item_name ?? $item->description ?? 'Unknown',
                                'item_barcode' => $item->item_barcode ?? null,
                                'item_total_with_tax_reporting_currency' => $item->item_total_with_tax_reporting_currency ?? number_format($item->total, 2, '.', ''),
                                'item_type_id' => $item->item_type_id ?? 1,
                                'item_price_without_tax' => $item->item_price_without_tax ?? number_format($item->item_total_before_vat ?? 0, 2, '.', ''),
                                'item_price_with_tax' => $item->item_price_with_tax ?? number_format($item->price ?? 0, 2, '.', ''),
                                'item_sales_tax_percentage' => $item->item_sales_tax_percentage ?? ($item->item_vat_rate ?? $item->vat_rate ?? 0),
                                'item_total_without_tax' => $item->item_total_without_tax ?? number_format($item->item_total_before_vat ?? 0, 2, '.', ''),
                                'item_quantity' => $item->item_quantity ?? $item->quantity ?? 1,
                                'item_total_with_tax' => $item->item_total_with_tax ?? number_format($item->total ?? 0, 2, '.', ''),
                                'item_total_tax' => $item->item_total_tax ?? number_format($item->item_vat_amount ?? 0, 2, '.', ''),
                                'item_unit_id' => $item->item_unit_id ?? 21,
                                'tax_rate_id' => $item->tax_rate_id ?? 2,
                                'item_id' => $item->item_id ?? null,
                                'warehouse_id' => $item->warehouse_id ?? $warehouse_id, // <-- fallback to header warehouse_id
                                'cmd' => 'insert',
                            ];
                        }, $invoice->items()->get()->all()),
                    ]
                ],
                'IsEncrypted' => false,
                'ServerConfig' => json_encode($serverConfig),
                'App' => 'web',
                'Language' => 'sq-AL',
            ];
            \Log::info('DEBUG: Final fiscalization payload', ['payload' => $payload]);
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
            // Check for cash register not opened error (code 638 or message contains 'celje')
            $shouldRetry = false;
            if (isset($responseBody['status']['code']) && $responseBody['status']['code'] == 638) {
                $msg = strtolower($responseBody['status']['message'] ?? '');
                if (strpos($msg, 'celje') !== false || strpos($msg, 'arken ne fillim te dites') !== false) {
                    // Open cash register for the invoice date using cashdeskactualbalance.php
                    $cash_desk_id = $cash_register_id ?? 9; // fallback to 9 if not set
                    $now = now()->format('Y-m-d H:i');
                    $openPayload = [
                        'body' => [
                            [
                                'cmd' => 'insert',
                                'cash_desK_actual_balance_header_id' => null,
                                'counting_date_time' => $invoiceDate,
                                'note' => null,
                                'balance_type' => 2, // fiscal initial
                                'fcbc_code' => null,
                                'fiscal_delay_reason_type' => null,
                                'UUID' => null,
                                'details' => [
                                    [
                                        'cash_desk_actual_balance_detail_id' => null,
                                        'cash_desk_actual_balance_header_id' => null,
                                        'cash_desk_id' => $cash_desk_id,
                                        'currency_id' => 1,
                                        'amount' => '0',
                                        'note' => null,
                                        'exchange_rate' => 1,
                                        'reporting_currency_total' => 0,
                                        'current_amount' => '0',
                                        'cash_transaction_header_id' => null
                                    ]
                                ]
                            ]
                        ],
                        'IsEncrypted' => false,
                        'ServerConfig' => json_encode($serverConfig),
                        'App' => 'web',
                        'Language' => 'sq-AL',
                    ];
                    \Log::info('Attempting to open cash register (fiscal initial)', ['payload' => $openPayload]);
                    $openResponse = $this->client->post($this->config['url'] . '/cashdeskactualbalance.php', [
                        'headers' => $headers,
                        'json' => $openPayload,
                        'cookies' => $jar,
                    ]);
                    $openBody = json_decode($openResponse->getBody()->getContents(), true);
                    \Log::info('Cash register open response', ['response' => $openBody]);
                    // Only retry if open succeeded (code 600)
                    if (isset($openBody['status']['code']) && $openBody['status']['code'] == 600) {
                        $shouldRetry = true;
                    }
                }
            }
            if ($shouldRetry) {
                \Log::info('Retrying fiscalization after opening cash register');
                $response = $this->client->post($this->config['url'] . '/sales.php', [
                    'headers' => $headers,
                    'json' => $payload,
                    'cookies' => $jar,
                ]);
                $responseBody = json_decode($response->getBody()->getContents(), true);
                \Log::info('Fiscalization response after retry for invoice ' . $invoice->id, ['response' => $responseBody]);
            }
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