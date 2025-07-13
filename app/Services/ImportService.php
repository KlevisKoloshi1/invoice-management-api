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
                    for ($i = 0; $i < $sheetCount; $i++) {
                        $row = $sheet[$i];
                        $rowText = strtolower(implode(' ', array_map('strval', $row)));
                        // 1. Detect start of a new invoice block
                        if (preg_match('/nr:\s*([a-z0-9]+)/i', $rowText, $mNr) && preg_match('/date dokumenti:?\s*([0-9\.,\/\-]+)/i', $rowText, $mDate)) {
                            $foundAnyInvoiceBlock = true;
                            // Save previous invoice if exists
                            if ($currentInvoice && count($currentItems) > 0) {
                                $invoices[] = array_merge($currentInvoice, ['items' => $currentItems]);
                            }
                            // Start new invoice
                            $currentInvoice = [
                                'number' => $mNr[1],
                                'date' => isset($mDate[1]) ? date('Y-m-d', strtotime(str_replace(['.', '/', ','], '-', $mDate[1]))) : null,
                                'client_code' => null,
                                'client_name' => null,
                                'client_tin' => null,
                            ];
                            $currentItems = [];
                            $parsingItems = false;
                            $headerMap = [];
                            $foundInvoiceBlock = true;
                            \Log::info('Detected new invoice block', $currentInvoice);
                            continue;
                        }
                        // 2. Extract client info
                        if ($foundInvoiceBlock && $currentInvoice && preg_match('/klienti:?\s*([a-z0-9]+)/i', $rowText, $mCode) && preg_match('/emri:?\s*([a-zA-Z\s]+)/i', $rowText, $mName)) {
                            $currentInvoice['client_code'] = $mCode[1];
                            $currentInvoice['client_name'] = trim($mName[1]);
                            \Log::info('Detected client info', $currentInvoice);
                            continue;
                        }
                        // 3. Bulletproof scan for item header row (after invoice block)
                        if ($foundInvoiceBlock && $currentInvoice && !$parsingItems) {
                            $headerFound = false;
                            $scannedRows = [];
                            // Scan next 15 rows for a valid header row, try combining with next row if needed
                            for ($j = $i; $j < min($i + 15, $sheetCount); $j++) {
                                $scanRow = $sheet[$j];
                                // Skip blank/empty rows
                                $nonEmptyCells = array_filter($scanRow, function($cell) {
                                    return !is_null($cell) && trim((string)$cell) !== '';
                                });
                                if (count($nonEmptyCells) === 0) {
                                    continue; // skip blank row
                                }
                                $normRow = array_map($normalize_header_cell, $scanRow);
                                $scannedRows[] = $normRow;
                                $colMap = [];
                                // Try single row header, tolerant to any order
                                foreach ($normRow as $idx => $cell) {
                                    if (strpos($cell, 'pershkrim') !== false) $colMap['description'] = $idx;
                                    if (strpos($cell, 'sasia') !== false) $colMap['quantity'] = $idx;
                                    if (strpos($cell, 'cmimi') !== false) $colMap['price'] = $idx;
                                    if (strpos($cell, 'njesia') !== false) $colMap['unit'] = $idx;
                                }
                                if (isset($colMap['description']) && isset($colMap['quantity']) && isset($colMap['price'])) {
                                    $headerMap = $colMap;
                                    $parsingItems = true;
                                    $headerFound = true;
                                    $i = $j; // move main loop to header row
                                    \Log::info('Detected item header (single row, tolerant)', ['rowNum' => $j, 'headerMap' => $headerMap, 'row' => $scanRow, 'normRow' => $normRow]);
                                    break;
                                }
                                // Try combining with next row if not found
                                if ($j + 1 < $sheetCount) {
                                    $nextRow = $sheet[$j + 1];
                                    // Skip if next row is blank
                                    $nonEmptyNext = array_filter($nextRow, function($cell) {
                                        return !is_null($cell) && trim((string)$cell) !== '';
                                    });
                                    if (count($nonEmptyNext) === 0) {
                                        continue;
                                    }
                                    $combined = [];
                                    $len = max(count($scanRow), count($nextRow));
                                    for ($k = 0; $k < $len; $k++) {
                                        $cellA = isset($scanRow[$k]) ? trim($scanRow[$k]) : '';
                                        $cellB = isset($nextRow[$k]) ? trim($nextRow[$k]) : '';
                                        $combined[] = $cellB ?: $cellA;
                                    }
                                    $normCombined = array_map($normalize_header_cell, $combined);
                                    $scannedRows[] = $normCombined;
                                    $colMap2 = [];
                                    foreach ($normCombined as $idx => $cell) {
                                        if (strpos($cell, 'pershkrim') !== false) $colMap2['description'] = $idx;
                                        if (strpos($cell, 'sasia') !== false) $colMap2['quantity'] = $idx;
                                        if (strpos($cell, 'cmimi') !== false) $colMap2['price'] = $idx;
                                        if (strpos($cell, 'njesia') !== false) $colMap2['unit'] = $idx;
                                    }
                                    if (isset($colMap2['description']) && isset($colMap2['quantity']) && isset($colMap2['price'])) {
                                        $headerMap = $colMap2;
                                        $parsingItems = true;
                                        $headerFound = true;
                                        $i = $j + 1; // move main loop to combined header row
                                        \Log::info('Detected item header (combined rows, tolerant)', ['rowNum' => $j, 'headerMap' => $headerMap, 'row' => $combined, 'normRow' => $normCombined]);
                                        break;
                                    }
                                }
                            }
                            if (!$headerFound) {
                                // Log all scanned rows for debugging
                                \Log::error('Could not find item header after invoice block', ['startRow' => $i, 'scannedRows' => $scannedRows]);
                                $errMsg = 'Could not find item header after invoice block at row ' . $i;
                                $errors[] = $errMsg;
                                $foundInvoiceBlock = false;
                                $currentInvoice = null;
                                $currentItems = [];
                                continue; // skip to next invoice block
                            }
                        }
                        // 4. Parse item rows (after header found)
                        if ($parsingItems && $currentInvoice && isset($headerMap['description'])) {
                            $desc = isset($row[$headerMap['description']]) ? trim($row[$headerMap['description']]) : '';
                            $unit = isset($headerMap['unit']) && $headerMap['unit'] !== null && isset($row[$headerMap['unit']]) ? trim($row[$headerMap['unit']]) : '';
                            $qty = isset($headerMap['quantity']) && isset($row[$headerMap['quantity']]) ? $row[$headerMap['quantity']] : null;
                            $price = isset($headerMap['price']) && isset($row[$headerMap['price']]) ? $row[$headerMap['price']] : null;
                            // Skip summary/empty rows
                            $isSummary = false;
                            if (is_string($desc) && (stripos($desc, 'shuma') !== false || stripos($desc, 'tvsh') !== false)) {
                                $isSummary = true;
                            }
                            if ($desc !== '' && is_numeric($qty) && is_numeric($price) && !$isSummary) {
                                $currentItems[] = [
                                    'description' => $desc,
                                    'unit' => $unit,
                                    'quantity' => floatval($qty),
                                    'price' => floatval($price),
                                    'total' => floatval($qty) * floatval($price),
                                ];
                                \Log::info('Parsed item row', end($currentItems));
                            } else if ($desc === '' && $qty === null && $price === null) {
                                // End of items for this invoice
                                $parsingItems = false;
                                $headerMap = [];
                                $foundInvoiceBlock = false;
                                \Log::info('End of item rows for invoice', $currentInvoice);
                            }
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