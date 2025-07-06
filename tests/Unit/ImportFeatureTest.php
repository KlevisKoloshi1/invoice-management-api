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
}
