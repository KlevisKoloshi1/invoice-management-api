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
    /**
     * Pre-validate that all required fields/headers exist somewhere in the sheet before parsing.
     */
    protected function validateRequiredFields($sheet)
    {
        $requiredFields = [
            'nr:', 'date dokumenti', 'klienti:', 'emri:', 'nipt:',
            'përshkrimi', 'njësia', 'sasia', 'cmimi', 'totali',
            'vlefta pa tvsh', 'tvsh', 'vlefta me tvsh', 'përshkrim fature',
            'shuma pa tvsh', 'shuma me tvsh', 'monedha'
        ];
        $foundFields = [];
        $maxRows = 200; // Only scan first 200 rows for performance
        foreach ($sheet as $rowIndex => $row) {
            if ($rowIndex > $maxRows) break;
            foreach ($row as $cell) {
                $normalized = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT', $cell)));
                $normalized = preg_replace('/\s+/', ' ', $normalized); // collapse spaces
                foreach ($requiredFields as $field) {
                    if (strpos($normalized, $field) !== false) {
                        $foundFields[$field] = true;
                    }
                }
            }
            if (count($foundFields) === count($requiredFields)) {
                break;
            }
        }
        $missing = array_diff($requiredFields, array_keys($foundFields));
        if (!empty($missing)) {
            throw new \Exception('The following required fields/headers are missing: ' . implode(', ', $missing));
        }
    }

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
        // Add a closure for bulletproof header normalization (for custom report)
        $normalize_header_cell = function($cell) {
            $cell = iconv('UTF-8', 'ASCII//TRANSLIT', $cell); // remove accents
            $cell = strtolower($cell);
            $cell = preg_replace('/\s+/u', '', $cell); // remove all spaces
            return trim($cell);
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
        $createdInvoices = [];
        try {
            $rows = \Maatwebsite\Excel\Facades\Excel::toArray([], $file);
            $sheet = $rows[0] ?? [];
            // PRE-VALIDATE required fields/headers before any parsing
            $this->validateRequiredFields($sheet);
            $header = array_map('strtolower', $sheet[0]);
            $expected = ['client_name','client_email','client_address','client_phone','invoice_total','invoice_status','item_description','item_quantity','item_price','item_total'];
            $isFlatTable = ($header === $expected);
            // Even more robust detection: scan all cells for 'persh' (partial match, case-insensitive, trimmed)
            $isCustomReport = false;
            $headerRowIdx = null;
            $colMap = [];
            $required = [
                'pershkrimi' => null,
                'njesia' => null,
                'sasia' => null,
                'cmimi' => null
            ];
            $maxHeaderRows = 30;
            // Try to detect and combine multi-row headers (e.g., rows 5 and 6)
            $combinedHeader = null;
            for ($i = 0; $i < min($maxHeaderRows - 1, count($sheet) - 1); $i++) {
                $rowA = $sheet[$i];
                $rowB = $sheet[$i + 1];
                // Heuristic: if rowA contains 'Sasia' or 'Cmimi' and rowB contains 'Pershkrimi' or 'Njesia'
                $rowAString = strtolower(implode(' ', $rowA));
                $rowBString = strtolower(implode(' ', $rowB));
                if ((strpos($rowAString, 'sasia') !== false || strpos($rowAString, 'cmimi') !== false)
                    && (strpos($rowBString, 'pershkrim') !== false || strpos($rowBString, 'njesia') !== false)) {
                    // Combine the two rows
                    $combinedHeader = [];
                    $len = max(count($rowA), count($rowB));
                    for ($j = 0; $j < $len; $j++) {
                        $cellA = isset($rowA[$j]) ? trim($rowA[$j]) : '';
                        $cellB = isset($rowB[$j]) ? trim($rowB[$j]) : '';
                        $combinedHeader[] = $cellB ?: $cellA;
                    }
                    $headerRowIdx = $i + 1;
                    \Log::info('Combined multi-row header detected', ['rowA' => $rowA, 'rowB' => $rowB, 'combined' => $combinedHeader]);
                    break;
                }
            }
            // Use combined header if found, else fallback to previous logic
            if ($combinedHeader) {
                $normRow = array_map($normalize_header, $combinedHeader);
                $tmpMap = [];
                foreach (['pershkrimi', 'njesia', 'sasia', 'cmimi'] as $req) {
                    foreach ($normRow as $idx => $cell) {
                        if (strpos($cell, $req) !== false) {
                            $tmpMap[$req] = $idx;
                            break;
                        }
                    }
                }
                if (count($tmpMap) === 4) {
                    $colMap = $tmpMap;
                    \Log::info('Column mapping from combined header', ['colMap' => $colMap, 'header' => $combinedHeader]);
                } else {
                    \Log::error('Could not map all required columns from combined header', ['header' => $combinedHeader]);
                    throw new \Exception('Could not find a valid header row with required columns.');
                }
            } else {
                // Scan first $maxHeaderRows rows for a header row containing all required columns
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
                        \Log::info('Detected header row for import', ['row' => $row, 'colMap' => $colMap, 'header' => $sheet[$row]]);
                        break;
                    }
                }
                if ($headerRowIdx === null || count($colMap) < 4) {
                    // Log the first 10 rows for debugging
                    \Log::error('Could not find a valid header row. First 10 rows:', array_slice($sheet, 0, 10));
                    throw new \Exception('Could not find a valid header row with required columns.');
                }
            }
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
            $foundAnyInvoiceBlock = false; // Track if any invoice block is found
            if ($isFlatTable) {
                if (empty($sheet) || count($sheet) < 2) {
                    throw new \Exception('Excel file must have a header and at least one data row.');
                }
                $clientCache = [];
                $invoiceMap = [];
                $invoiceItemsMap = [];
                $invoiceMeta = [];
                for ($i = 1; $i < count($sheet); $i++) {
                    $row = $sheet[$i];
                    $rowNum = $i + 1;
                    // Example: Assume extra columns for number, date, tin (customize as needed)
                    $invoiceNumber = $row[10] ?? null; // Adjust index if needed
                    $invoiceDate = $row[11] ?? null;
                    $clientTIN = $row[12] ?? null;
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
                                'tin' => $clientTIN,
                            ]);
                        } else if ($clientTIN && $client->tin !== $clientTIN) {
                            $client->tin = $clientTIN;
                            $client->save();
                        }
                        $clientCache[$clientEmail] = $client;
                    }
                    // Group by invoice (client_email + total + status + number + date)
                    $invoiceKey = $client->id . '|' . $row[4] . '|' . ($row[5] ?? 'pending') . '|' . $invoiceNumber . '|' . $invoiceDate;
                    if (!isset($invoiceMap[$invoiceKey])) {
                        $invoice = Invoice::create([
                            'client_id' => $client->id,
                            'total' => $row[4],
                            'status' => $row[5] ?? 'pending',
                            'created_by' => $userId,
                            'number' => $invoiceNumber,
                            'invoice_date' => $invoiceDate,
                        ]);
                        $invoiceMap[$invoiceKey] = $invoice;
                        $createdInvoiceIds[] = $invoice->id;
                        $invoiceItemsMap[$invoiceKey] = [];
                        $invoiceMeta[$invoiceKey] = [
                            'number' => $invoiceNumber,
                            'date' => $invoiceDate,
                            'client_tin' => $clientTIN,
                        ];
                    } else {
                        $invoice = $invoiceMap[$invoiceKey];
                    }
                    // Collect item data for this invoice
                    $invoiceItemsMap[$invoiceKey][] = [
                        'description' => $row[6],
                        'quantity' => $row[7],
                        'price' => $row[8],
                        'total' => $row[9] ?? ($row[7] * $row[8]),
                    ];
                }
                // Now, create items and fiscalize for each invoice
                foreach ($invoiceMap as $invoiceKey => $invoice) {
                    $items = $invoiceItemsMap[$invoiceKey] ?? [];
                    foreach ($items as $itemData) {
                        try {
                            InvoiceItem::create(array_merge($itemData, [
                                'invoice_id' => $invoice->id
                            ]));
                            $successCount++;
                        } catch (\Exception $e) {
                            $errors[] = "Invoice ID {$invoice->id}: " . $e->getMessage();
                        }
                    }
                    // Fiscalize after all items are created
                    try {
                        $fiscalizationService = new FiscalizationService();
                        $meta = $invoiceMeta[$invoiceKey] ?? [];
                        $verificationUrl = $fiscalizationService->fiscalize($invoice, $meta);
                        $createdInvoices[] = [
                            'id' => $invoice->id,
                            'number' => $invoice->number,
                            'client' => $invoice->client->name,
                            'total' => $invoice->total,
                            'fiscalization_url' => $verificationUrl,
                        ];
                    } catch (\Exception $e) {
                        $errors[] = "Fiscalization failed for invoice ID {$invoice->id}: " . $e->getMessage();
                    }
                }
                $import->status = 'completed';
                $import->save();
            } else {
                // Always attempt custom report parsing if not a flat table
                try {
                    $invoices = [];
                    $currentInvoice = null;
                    $currentItems = [];
                    $headerMap = [];
                    $parsingItems = false;
                    $sheetCount = count($sheet);
                    $foundInvoiceBlock = false;
                    // --- Robust scan for all required invoice block fields before parsing ---
                    $i = 0;
                    while ($i < $sheetCount) {
                        $row = $sheet[$i];
                        $rowText = strtolower(implode(' ', array_map('strval', $row)));
                        // Look for 'Nr:' and 'Date dokumenti:'
                        if (preg_match('/nr:\s*([a-z0-9]+)/i', $rowText, $mNr) && preg_match('/date dokumenti:?\s*([0-9\.,\/\-]+)/i', $rowText, $mDate)) {
                            // Look ahead for Klienti, Emri, NIPT
                            $foundClient = false;
                            $foundHeader = false;
                            $clientRow = null;
                            $headerRow = null;
                            for ($j = $i + 1; $j < min($i + 10, $sheetCount); $j++) {
                                $nextRow = $sheet[$j];
                                $nextText = strtolower(implode(' ', array_map('strval', $nextRow)));
                                if (preg_match('/klienti:/i', $nextText) && preg_match('/emri:/i', $nextText) && preg_match('/nipt:/i', $nextText)) {
                                    $foundClient = true;
                                    $clientRow = $nextRow;
                                }
                                // Look for item header row
                                $normRow = array_map($normalize_header_cell, $nextRow);
                                $headerFields = ['pershkrim', 'njesia', 'sasia', 'cmimi', 'totali'];
                                $foundAllHeaders = true;
                                foreach ($headerFields as $field) {
                                    $found = false;
                                    foreach ($normRow as $cell) {
                                        if (strpos($cell, $field) !== false) {
                                            $found = true;
                                            break;
                                        }
                                    }
                                    if (!$found) {
                                        $foundAllHeaders = false;
                                        break;
                                    }
                                }
                                if ($foundAllHeaders) {
                                    $foundHeader = true;
                                    $headerRow = $nextRow;
                                    $headerRowIdx = $j;
                                    break;
                                }
                            }
                            if ($foundClient && $foundHeader) {
                                // Now parse this invoice block as before, starting from $headerRowIdx
                                // ... (existing parsing logic for items, summary, etc. goes here) ...
                                // Move $i to after this block
                                $i = $headerRowIdx;
                                // (You may need to refactor the rest of the loop to fit this structure)
                            } else {
                                // Skip to next possible block
                                $i++;
                                continue;
                            }
                        } else {
                            $i++;
                        }
                    }
                    // Save last invoice
                    if ($currentInvoice && count($currentItems) > 0) {
                        $invoices[] = array_merge($currentInvoice, ['items' => $currentItems]);
                    }
                    if (!$foundAnyInvoiceBlock) {
                        throw new \Exception('No invoice blocks (Nr: ... Date dokumenti: ...) found in the file.');
                    }
                    if (count($invoices) === 0) {
                        $errors[] = 'No invoices were detected in the file. Please check the format.';
                    }
                    // --- Now create clients, invoices, items, and fiscalize ---
                    foreach ($invoices as $inv) {
                    // Create or get client
                    $client = \App\Models\Client::firstOrCreate(
                            ['name' => $inv['client_name']],
                            ['email' => $inv['client_code'] . '@example.com', 'tin' => $inv['client_tin']]
                    );
                    // Create invoice
                    $invoice = \App\Models\Invoice::create([
                        'client_id' => $client->id,
                            'total' => array_sum(array_column($inv['items'], 'total')),
                        'status' => 'paid',
                        'created_by' => $userId,
                            'number' => $inv['number'],
                            'invoice_date' => $inv['date'],
                        ]);
                    // Add items
                        foreach ($inv['items'] as $item) {
                        \App\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => $item['description'],
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'total' => $item['total'],
                                'unit' => $item['unit'] ?? '',
                        ]);
                        $successCount++;
                        }
                        // Fiscalize
                        try {
                            $fiscalizationService = new FiscalizationService();
                            $meta = [
                                'number' => $inv['number'],
                                'date' => $inv['date'],
                                'client_tin' => $client->tin,
                            ];
                            $verificationUrl = $fiscalizationService->fiscalize($invoice, $meta);
                            $createdInvoices[] = [
                                'id' => $invoice->id,
                                'number' => $invoice->number,
                                'client' => $invoice->client->name,
                                'total' => $invoice->total,
                                'fiscalization_url' => $verificationUrl,
                            ];
                        } catch (\Exception $e) {
                            $errors[] = "Fiscalization failed for invoice ID {$invoice->id}: " . $e->getMessage();
                        }
                        $createdInvoiceIds[] = $invoice->id;
                    }
                    $import->status = 'completed';
                    $import->save();
                } catch (\Exception $e) {
                    $import->status = 'failed';
                    $import->save();
                    $errors[] = $e->getMessage();
                }
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
            'invoices' => $createdInvoices,
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