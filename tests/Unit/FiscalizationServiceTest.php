<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\InvoiceItem;
use App\Services\FiscalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;

class FiscalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscalization_sets_success_status_and_url()
    {
        // Create client and invoice
        $client = Client::factory()->create(['name' => 'Test Client']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'fiscalized' => false]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Test Item',
            'quantity' => 2,
            'price' => 100,
            'total' => 200,
        ]);

        // Mock Guzzle client and response
        $mockResponse = Mockery::mock('Psr\Http\Message\ResponseInterface');
        $mockResponse->shouldReceive('getBody->getContents')->andReturn(json_encode([
            'qrcode_url' => 'https://eFiskalizimi-app-test.tatime.gov.al/invoicecheck/#/verify?iic=123',
        ]));
        $mockClient = Mockery::mock('GuzzleHttp\Client');
        $mockClient->shouldReceive('post')->andReturn($mockResponse);

        // Inject mock client into service
        $service = new FiscalizationService();
        $service->client = $mockClient;

        $service->fiscalize($invoice->fresh());
        $invoice->refresh();

        $this->assertTrue($invoice->fiscalized);
        $this->assertEquals('success', $invoice->fiscalization_status);
        $this->assertStringContainsString('qrcode_url', $invoice->fiscalization_response);
        $this->assertNotNull($invoice->fiscalization_url);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 