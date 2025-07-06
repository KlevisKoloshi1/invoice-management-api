<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Exception;
use Illuminate\Support\Facades\Auth;
use App\Services\FiscalizationService;

class ImportService implements ImportServiceInterface
{
    public function importFromExcel($file, $userId)
    {
        // Helper for header normalization (now also used for flexible header detection)
        if (!function_exists('normalize_albanian')) {
            function normalize_albanian($str) {
                $str = mb_strtolower(trim($str));
                $str = str_replace(['ë','ç'], ['e','c'], $str); // remove accents
                $str = preg_replace('/\s+/', '', $str); // remove all spaces
                return $str;
            }
        }
        // Use a closure for header normalization to avoid PHP function scope issues
        $normalize_header = function($str) {
            $str = mb_strtolower(trim($str));
            $str = str_replace(['ë','ç'], ['e','c'], $str);
            $str = preg_replace('/\s+/', '', $str);
            return $str;
        };
        $path = $file->store('imports');
        $errors = [];
        $successCount = 0;
        $rowNum = 1;
        $import = Import::create([
            'file_path' => $path,
            'status' => 'processing',
            'created_by' => $userId,
        ]);
        $createdInvoiceIds = [];
        try {
            $rows = \Maatwebsite\Excel\Facades\Excel::toArray([], $file);
            $sheet = $rows[0] ?? [];
            $header = array_map('strtolower', $sheet[0]);
            $expected = ['client_name','client_email','client_address','client_phone','invoice_total','invoice_status','item_description','item_quantity','item_price','item_total'];
            $isFlatTable = ($header === $expected);
            // Even more robust detection: scan all cells for 'persh' (partial match, case-insensitive, trimmed)
            $isCustomReport = false;
            foreach ($rows as $sheetData) {
                foreach ($sheetData as $row) {
                    foreach ($row as $cell) {
                        if (is_string($cell) && str_contains(strtolower(trim($cell)), 'persh')) {
                            $isCustomReport = true;
                            break 3;
                        }
                    }
                }
            }
            if ($isFlatTable) {
                if (empty($sheet) || count($sheet) < 2) {
                    throw new \Exception('Excel file must have a header and at least one data row.');
                }
                $clientCache = [];
                $invoiceMap = [];
                for ($i = 1; $i < count($sheet); $i++) {
                    $row = $sheet[$i];
                    $rowNum = $i + 1;
                    // Validate required fields
                    if (
                        empty($row[0]) || empty($row[1]) || empty($row[4]) || empty($row[6]) || empty($row[7]) || empty($row[8])
                    ) {
                        $errors[] = "Row $rowNum: Missing required fields.";
                        continue;
                    }
                    $clientEmail = $row[1];
                    if (isset($clientCache[$clientEmail])) {
                        $client = $clientCache[$clientEmail];
                    } else {
                        $client = Client::where('email', $clientEmail)->first();
                        if (!$client) {
                            $client = Client::create([
                                'email' => $clientEmail,
                                'name' => $row[0],
                                'address' => $row[2] ?? null,
                                'phone' => $row[3] ?? null,
                            ]);
                        }
                        $clientCache[$clientEmail] = $client;
                    }
                    // Group by invoice (client_email + total + status)
                    $invoiceKey = $client->id . '|' . $row[4] . '|' . ($row[5] ?? 'pending');
                    if (!isset($invoiceMap[$invoiceKey])) {
                        $invoice = Invoice::create([
                            'client_id' => $client->id,
                            'total' => $row[4],
                            'status' => $row[5] ?? 'pending',
                            'created_by' => $userId,
                        ]);
                        // Fiscalize the invoice after creation
                        try {
                            $fiscalizationService = new FiscalizationService();
                            $fiscalizationService->fiscalize($invoice);
                        } catch (\Exception $e) {
                            $errors[] = "Row $rowNum: Fiscalization failed: " . $e->getMessage();
                        }
                        $invoiceMap[$invoiceKey] = $invoice;
                        $createdInvoiceIds[] = $invoice->id;
                    } else {
                        $invoice = $invoiceMap[$invoiceKey];
                    }
                    // Item
                    try {
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => $row[6],
                            'quantity' => $row[7],
                            'price' => $row[8],
                            'total' => $row[9] ?? ($row[7] * $row[8]),
                        ]);
                        $successCount++;
                    } catch (\Exception $e) {
                        $errors[] = "Row $rowNum: " . $e->getMessage();
                    }
                }
                $import->status = 'completed';
                $import->save();
            } else if ($isCustomReport) {
                try {
                    // Even more robust header detection: scan first 30 rows for required columns (ignore accents, case, spaces, allow non-adjacent columns)
                    $headerRowIdx = null;
                    $colMap = [];
                    $required = [
                        'pershkrimi' => null,
                        'njesia' => null,
                        'sasia' => null,
                        'cmimi' => null
                    ];
                    $maxHeaderRows = 30;
                    for ($row = 0; $row < min($maxHeaderRows, count($sheet)); $row++) {
                        $normRow = array_map($normalize_header, $sheet[$row]);
                        $tmpMap = [];
                        foreach (array_keys($required) as $req) {
                            foreach ($normRow as $idx => $cell) {
                                if (strpos($cell, $req) !== false) {
                                    $tmpMap[$req] = $idx;
                                    break;
                                }
                            }
                        }
                        if (count($tmpMap) === count($required)) {
                            $headerRowIdx = $row;
                            $colMap = $tmpMap;
                            break;
                        }
                    }
                    if ($headerRowIdx === null || count($colMap) < 4) {
                        throw new \Exception('Could not find a valid header row with required columns.');
                    }
                    // Parse data rows after header
                    $items = [];
                    for ($i = $headerRowIdx + 1; $i < count($sheet); $i++) {
                        $row = $sheet[$i];
                        $desc = isset($row[$colMap['pershkrimi']]) ? trim($row[$colMap['pershkrimi']]) : '';
                        $unit = isset($row[$colMap['njesia']]) ? trim($row[$colMap['njesia']]) : '';
                        $qty = isset($row[$colMap['sasia']]) ? $row[$colMap['sasia']] : null;
                        $price = isset($row[$colMap['cmimi']]) ? $row[$colMap['cmimi']] : null;
                        if ($desc === '' || !is_numeric($qty) || !is_numeric($price)) {
                            continue;
                        }
                        $total = floatval($qty) * floatval($price);
                        $items[] = [
                            'description' => $desc,
                            'unit' => $unit,
                            'quantity' => floatval($qty),
                            'price' => floatval($price),
                            'total' => $total,
                        ];
                    }
                    if (count($items) === 0) {
                        throw new \Exception('No valid invoice items found in the file after header.');
                    }
                    // Create or get client
                    $client = \App\Models\Client::firstOrCreate(
                        ['email' => 'unknown@email.com'],
                        ['name' => 'Klient Rastesor', 'address' => '', 'phone' => '']
                    );
                    // Create invoice
                    $invoice = \App\Models\Invoice::create([
                        'client_id' => $client->id,
                        'total' => array_sum(array_column($items, 'total')),
                        'status' => 'paid',
                        'created_by' => $userId,
                    ]);
                    // Fiscalize
                    try {
                        $fiscalizationService = new \App\Services\FiscalizationService();
                        $fiscalizationService->fiscalize($invoice);
                    } catch (\Exception $e) {
                        $errors[] = 'Fiscalization failed: ' . $e->getMessage();
                    }
                    // Add items
                    foreach ($items as $item) {
                        \App\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => $item['description'],
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'total' => $item['total'],
                        ]);
                        $successCount++;
                    }
                    $createdInvoiceIds[] = $invoice->id;
                    $import->status = 'completed';
                    $import->save();
                } catch (\Exception $e) {
                    $import->status = 'failed';
                    $import->save();
                    $errors[] = $e->getMessage();
                }
            } else {
                throw new \Exception('Excel header does not match expected format and no recognizable report structure found.');
            }
        } catch (\Exception $e) {
            $import->status = 'failed';
            $import->save();
            $errors[] = $e->getMessage();
        }
        return [
            'import_id' => $import->id,
            'success_count' => $successCount,
            'errors' => $errors,
            'invoice_ids' => $createdInvoiceIds,
        ];
    }

    public function updateImport($importId, $data)
    {
        $import = Import::findOrFail($importId);
        if (isset($data['status'])) {
            $import->status = $data['status'];
        }
        if (isset($data['file']) && $data['file']->isValid()) {
            // Optionally replace the file
            $path = $data['file']->store('imports');
            $import->file_path = $path;
        }
        $import->save();
        return $import;
    }

    public function deleteImport($importId)
    {
        $import = Import::findOrFail($importId);
        // Optionally delete the file from storage
        if ($import->file_path) {
            \Illuminate\Support\Facades\Storage::delete($import->file_path);
        }
        $import->delete();
        return true;
    }

    public function getAllImports($perPage = 15)
    {
        return Import::with('user')->paginate($perPage);
    }

    public function getImport($importId)
    {
        return Import::with('user')->findOrFail($importId);
    }
} 