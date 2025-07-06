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
            // Prepare payload according to the real API requirements
            $payload = [
                'json' => [
                    'invoice_id' => $invoice->id,
                    'total' => $invoice->total,
                    // Add other required fields here
                ]
            ];
            $response = $this->client->post('https://efiskalizimi-app-test.tatime.gov.al/api/invoice', $payload);
            $body = json_decode($response->getBody(), true);

            // Example: extract and save fiscalization codes
            $invoice->iic = $body['iic'] ?? null;
            $invoice->fic = $body['fic'] ?? null;
            $invoice->tin = $body['tin'] ?? null;
            $invoice->crtd = $body['crtd'] ?? null;
            $invoice->ord = $body['ord'] ?? null;
            $invoice->bu = $body['bu'] ?? null;
            $invoice->cr = $body['cr'] ?? null;
            $invoice->sw = $body['sw'] ?? null;
            $invoice->prc = $body['prc'] ?? null;
            $invoice->fiscalization_status = $body['status'] ?? 'success';
            $invoice->fiscalization_url = $this->generateVerificationUrl($invoice);
            $invoice->fiscalized = true;
            $invoice->fiscalization_response = json_encode($body);
            $invoice->fiscalized_at = now();
            $invoice->save();
            return true;
        } catch (\Exception $e) {
            Log::error('Fiscalization failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            $invoice->fiscalization_status = 'failed';
            $invoice->save();
            return false;
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