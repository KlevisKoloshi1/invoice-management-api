<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\ImportService;
use App\Models\User;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImportFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic unit test example.
     */
    public function test_example(): void
    {
        $this->assertTrue(true);
    }

    public function test_import_from_excel_with_invalid_file()
    {
        $service = app(ImportService::class);
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');
        $this->expectException(\Exception::class);
        $service->importFromExcel($file, $user->id);
    }

    public function test_update_and_delete_import()
    {
        $service = app(ImportService::class);
        $user = User::factory()->create();
        $import = Import::factory()->create(['created_by' => $user->id]);
        $service->updateImport($import->id, ['status' => 'completed']);
        $this->assertEquals('completed', $import->fresh()->status);
        $service->deleteImport($import->id);
        $this->assertDatabaseMissing('imports', ['id' => $import->id]);
    }

    public function test_import_from_excel_with_valid_file()
    {
        Storage::fake('local');
        $service = app(ImportService::class);
        $user = User::factory()->create();

        // Create a fake Excel file with required headers and one row
        $headers = [
            'client_name', 'client_email', 'client_address', 'client_phone',
            'invoice_total', 'invoice_status', 'item_description', 'item_quantity',
            'item_price', 'item_total', 'tax_rate', 'unit'
        ];
        $row = [
            'Test Client', 'client@example.com', '123 Main St', '1234567890',
            '1000', 'pending', 'Test Item', '2', '500', '1000', '20', 'pcs'
        ];
        $data = [$headers, $row];

        // Write to a temp CSV and convert to XLSX using PhpSpreadsheet
        $tempCsv = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
        $fp = fopen($tempCsv, 'w');
        foreach ($data as $line) {
            fputcsv($fp, $line);
        }
        fclose($fp);

        // Convert CSV to XLSX
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        $spreadsheet = $spreadsheet->load($tempCsv);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempXlsx = tempnam(sys_get_temp_dir(), 'import_test_') . '.xlsx';
        $writer->save($tempXlsx);

        $file = new UploadedFile($tempXlsx, 'import_test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $result = $service->importFromExcel($file, $user->id);

        $this->assertArrayHasKey('import_id', $result);
        $import = Import::find($result['import_id']);
        $this->assertNotNull($import);
        $this->assertEquals('completed', $import->status);
        $this->assertEquals($user->id, $import->created_by);
        $this->assertGreaterThan(0, $result['success_count']);
        $this->assertEmpty($result['errors']);
    }
}
