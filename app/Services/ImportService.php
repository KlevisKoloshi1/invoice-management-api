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
        // Map user-friendly and snake_case headers to internal field names
        $headerAliases = [
            'invoice_number' => ['invoice_number', 'Invoice Number'],
            'invoice_date' => ['invoice_date', 'Invoice Date'],
            'business_unit' => ['business_unit', 'Business Unit'],
            'issuer_tin' => ['issuer_tin', 'Issuer TIN'],
            'invoice_type' => ['invoice_type', 'Invoice Type'],
            'is_e_invoice' => ['is_e_invoice', 'Is E-Invoice', 'Is EInvoice', 'Is E Invoice'],
            'operator_code' => ['operator_code', 'Operator Code'],
            'software_code' => ['software_code', 'Software Code'],
            'payment_method' => ['payment_method', 'Payment Method'],
            'total_amount' => ['total_amount', 'Total Amount'],
            'total_before_vat' => ['total_before_vat', 'Total Before VAT'],
            'vat_amount' => ['vat_amount', 'VAT Amount'],
            'vat_rate' => ['vat_rate', 'VAT Rate'],
            'buyer_name' => ['buyer_name', 'Buyer Name'],
            'buyer_address' => ['buyer_address', 'Buyer Address'],
            'buyer_tax_number' => ['buyer_tax_number', 'Buyer Tax Number'],
            'item_name' => ['item_name', 'Item Name'],
            'item_quantity' => ['item_quantity', 'Item Quantity'],
            'item_price' => ['item_price', 'Item Price'],
            'item_vat_rate' => ['item_vat_rate', 'Item VAT Rate'],
            'item_total_before_vat' => ['item_total_before_vat', 'Item Total Before VAT'],
            'item_vat_amount' => ['item_vat_amount', 'Item VAT Amount'],
        ];
        $requiredFields = array_keys($headerAliases);
        $headerRow = $sheet[0] ?? [];
        // Normalize all header cells (lowercase, remove all Unicode whitespace, remove accents)
        $normalize = function($str) {
            $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
            $str = strtolower($str);
            // Replace all dash-like Unicode characters with ASCII hyphen
            $str = str_replace(['–', '—', '−', '‐', '‑', '‒', '–', '—', '―', '﹘', '﹣', '－'], '-', $str);
            $str = preg_replace('/[\s\p{Zs}]+/u', '_', $str); 
            $str = preg_replace('/[^a-z0-9_]+/', '', $str); 
            return trim($str, '_');
        };
        $normalizedHeader = array_map($normalize, $headerRow);
        $foundFields = [];
        foreach ($headerAliases as $field => $aliases) {
            foreach ($aliases as $alias) { 
                $normAlias = $normalize($alias);
                if (in_array($normAlias, $normalizedHeader)) {
                    $foundFields[] = $field;
                    break;
                }
            }
        }
        $missing = array_diff($requiredFields, $foundFields);
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
            // Build header map from first row using normalized snake_case header names
            $headerRow = $sheet[0];
            $headerMap = [];
            foreach ($headerRow as $idx => $cell) {
                $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $cell))));
                $normalized = trim($normalized, '_');
                $headerMap[$normalized] = $idx;
            }
            $expected = ['client_name','client_email','client_address','client_phone','invoice_total','invoice_status','item_description','item_quantity','item_price','item_total'];
            // Add support for new fiscalization header set
            $expectedFiscalization = [
                'invoice_number','invoice_date','business_unit','issuer_tin','invoice_type','is_e_invoice','operator_code','software_code','payment_method','total_amount','total_before_vat','vat_amount','vat_rate','buyer_name','buyer_address','buyer_tax_number','customer_id','city_id','automatic_payment_method_id','currency_id','cash_register_id','fiscal_invoice_type_id','fiscal_profile_id','item_name','item_quantity','item_price','item_vat_rate','item_total_before_vat','item_vat_amount','unit','item_unit_id','tax_rate_id','item_id','item_type_id','item_code','warehouse_id'
            ];
            $normalize = function($str) {
                $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
                $str = strtolower($str);
                // Replace all dash-like Unicode characters with ASCII hyphen
                $str = str_replace(['–', '—', '−', '‐', '‑', '‒', '–', '—', '―', '﹘', '﹣', '－'], '-', $str);
                $str = preg_replace('/[\s\p{Zs}]+/u', '_', $str); // replace all whitespace with underscore
                $str = preg_replace('/[^a-z0-9_]+/', '', $str); // remove all non-alphanumeric except underscore
                return trim($str, '_');
            };
            $normalizedHeaderRow = array_map($normalize, $headerRow);
            \Log::info('DEBUG: Raw header row', ['headerRow' => $headerRow]);
            \Log::info('DEBUG: Normalized header row', ['normalizedHeaderRow' => $normalizedHeaderRow]);
            \Log::info('DEBUG: Expected fiscalization headers', ['expectedFiscalization' => $expectedFiscalization]);
            // Allow 'unit' to be optional
            $expectedFiscalizationOptionalUnit = $expectedFiscalization;
            array_pop($expectedFiscalizationOptionalUnit); // remove 'unit'
            // Check if all required columns are present (order-insensitive, allow extra columns)
            $missing = array_diff($expectedFiscalizationOptionalUnit, $normalizedHeaderRow);
            \Log::info('DEBUG: Missing columns', ['missing' => $missing]);
            $isFlatTable = empty($missing);
            if (!$isFlatTable) {
                throw new \Exception('Could not find a valid header row with required columns. Missing: ' . implode(', ', $missing));
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
                    // Skip empty or malformed rows
                    if (!is_array($row) || count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    // Clean up non-breaking spaces in all string fields
                    $replace_nbsp = function($v) {
                        return is_string($v) ? str_replace("\xC2\xA0", ' ', $v) : $v;
                    };
                    foreach ([
                        'invoice_number','business_unit','issuer_tin','invoice_type','is_e_invoice','operator_code','software_code','payment_method','buyer_name','buyer_address','buyer_tax_number','item_name','unit'
                    ] as $field) {
                        if (isset($data[$field])) $data[$field] = $replace_nbsp($data[$field]);
                    }
                    // Build associative array for the row using headerMap
                    $data = [];
                    foreach ($headerMap as $key => $idx) {
                        $data[$key] = isset($row[$idx]) ? $row[$idx] : null;
                    }
                    // Log the data row before validation
                    \Log::info('DEBUG: Processing data row', ['rowNum' => $rowNum, 'row' => $row, 'data' => $data]);
                    // Extract all required fields from $data
                    $invoice_number = $data['invoice_number'] ?? null;
                    $invoice_date = $data['invoice_date'] ?? null;
                    $business_unit = $data['business_unit'] ?? null;
                    $issuer_tin = $data['issuer_tin'] ?? null;
                    $invoice_type = $data['invoice_type'] ?? null;
                    $is_e_invoice = $data['is_e_invoice'] ?? null;
                    $operator_code = $data['operator_code'] ?? null;
                    $software_code = $data['software_code'] ?? null;
                    $payment_method = $data['payment_method'] ?? null;
                    $total_amount = $data['total_amount'] ?? null;
                    $total_before_vat = $data['total_before_vat'] ?? null;
                    $vat_amount = $data['vat_amount'] ?? null;
                    $vat_rate = $data['vat_rate'] ?? null;
                    $buyer_name = $data['buyer_name'] ?? null;
                    $buyer_address = $data['buyer_address'] ?? null;
                    $buyer_tax_number = $data['buyer_tax_number'] ?? null;
                    $item_name = $data['item_name'] ?? null;
                    $item_quantity = $data['item_quantity'] ?? null;
                    $item_price = $data['item_price'] ?? null;
                    $item_vat_rate = $data['item_vat_rate'] ?? null;
                    $item_total_before_vat = $data['item_total_before_vat'] ?? null;
                    $item_vat_amount = $data['item_vat_amount'] ?? null;
                    $customer_id = $data['customer_id'] ?? 1;
                    $city_id = $data['city_id'] ?? 1;
                    $automatic_payment_method_id = $data['automatic_payment_method_id'] ?? 0;
                    $currency_id = $data['currency_id'] ?? 1;
                    $cash_register_id = $data['cash_register_id'] ?? 9;
                    $fiscal_invoice_type_id = $data['fiscal_invoice_type_id'] ?? 4;
                    $fiscal_profile_id = $data['fiscal_profile_id'] ?? 1;
                    $item_unit_id = $data['item_unit_id'] ?? 21;
                    $tax_rate_id = $data['tax_rate_id'] ?? 2;
                    $item_id = $data['item_id'] ?? null;
                    $item_type_id = $data['item_type_id'] ?? 1;
                    $item_code = $data['item_code'] ?? null;
                    $warehouse_id = $data['warehouse_id'] ?? null;
                    // Normalize boolean-like and enum fields before validation
                    if (is_string($is_e_invoice)) {
                        $val = strtolower(trim($is_e_invoice));
                        if (in_array($val, ['yes', 'true', '1'])) $is_e_invoice = true;
                        elseif (in_array($val, ['no', 'false', '0'])) $is_e_invoice = false;
                    }
                    if (is_string($invoice_type)) {
                        $invoice_type = trim($invoice_type);
                        if (strtolower($invoice_type) === 'cash invoice') $invoice_type = 'Cash Invoice';
                        elseif (strtolower($invoice_type) === 'electronic') $invoice_type = 'Electronic';
                    }
                    if (is_string($payment_method)) {
                        $pm = strtolower(trim($payment_method));
                        if ($pm === 'banknotes and coins') $payment_method = 'Banknotes and coins';
                        elseif ($pm === 'card payment') $payment_method = 'Card Payment';
                        elseif ($pm === 'bank transfer') $payment_method = 'Bank Transfer';
                    }
                    // Improved invoice_date validation and parsing
                    $parsedDate = null;
                    if (!empty($invoice_date)) {
                        $invoice_date = str_replace("\xC2\xA0", " ", $invoice_date); // Replace non-breaking space with normal space
                        $dt_db = \DateTime::createFromFormat('d/m/Y H:i', $invoice_date);
                        if ($dt_db) {
                            $invoice_date_db = $dt_db->format('Y-m-d H:i:s');
                        } else {
                            $dt_db_alt = \DateTime::createFromFormat('Y-m-d H:i:s', $invoice_date);
                            if ($dt_db_alt) {
                                $invoice_date_db = $dt_db_alt->format('Y-m-d H:i:s');
                            }
                        }
                    }
                    if (empty($invoice_date_db)) {
                        $errorsForRow[] = "invoice_date required or invalid format (expected: YYYY-MM-DD HH:MM:SS or DD/MM/YYYY HH:MM)";
                    }
                    // Validation rules (example, should be moved to a validator class)
                    $errorsForRow = [];
                    $allowed_payment_methods = ['Banknotes and coins', 'Card Payment', 'Bank Transfer'];
                    $allowed_invoice_types = ['Cash Invoice', 'Electronic'];
                    if (empty($invoice_number)) $errorsForRow[] = 'invoice_number required';
                    if (empty($invoice_date) || !\DateTime::createFromFormat('d/m/Y H:i', $invoice_date)) $errorsForRow[] = "invoice_date required or invalid format (expected: YYYY-MM-DD HH:MM:SS or DD/MM/YYYY HH:MM)";
                    if (empty($business_unit)) $errorsForRow[] = 'business_unit required';
                    if (empty($issuer_tin) || strlen($issuer_tin) !== 10) $errorsForRow[] = 'issuer_tin required or invalid';
                    if (empty($invoice_type) || !in_array($invoice_type, $allowed_invoice_types)) $errorsForRow[] = "invoice_type required or invalid (allowed: " . implode(', ', $allowed_invoice_types) . ")";
                    if (!in_array($is_e_invoice, ['0', '1', 'true', 'false', 0, 1, true, false], true)) $errorsForRow[] = 'is_e_invoice required or invalid';
                    if (empty($operator_code)) $errorsForRow[] = 'operator_code required';
                    if (empty($software_code)) $errorsForRow[] = 'software_code required';
                    if (empty($payment_method) || !in_array($payment_method, $allowed_payment_methods)) $errorsForRow[] = "payment_method required or invalid (allowed: " . implode(', ', $allowed_payment_methods) . ")";
                    if (!is_numeric($total_amount) || $total_amount < 0) $errorsForRow[] = 'total_amount required or invalid';
                    if (!is_numeric($total_before_vat) || $total_before_vat < 0) $errorsForRow[] = 'total_before_vat required or invalid';
                    if (!is_numeric($vat_amount) || $vat_amount < 0) $errorsForRow[] = 'vat_amount required or invalid';
                    if (!in_array($vat_rate, ['0', '5.5', '20', 0, 5.5, 20], true)) $errorsForRow[] = 'vat_rate required or invalid';
                    if (!empty($buyer_tax_number) && $buyer_tax_number !== 'SKA' && strlen($buyer_tax_number) > 20) $errorsForRow[] = 'buyer_tax_number invalid';
                    if (empty($item_name)) $errorsForRow[] = 'item_name required';
                    if (!is_numeric($item_quantity) || $item_quantity < 0.01) $errorsForRow[] = 'item_quantity required or invalid';
                    if (!is_numeric($item_price) || $item_price < 0) $errorsForRow[] = 'item_price required or invalid';
                    if (!in_array($item_vat_rate, ['0', '5.5', '20', 0, 5.5, 20], true)) $errorsForRow[] = 'item_vat_rate required or invalid';
                    if (!is_numeric($item_total_before_vat) || $item_total_before_vat < 0) $errorsForRow[] = 'item_total_before_vat required or invalid';
                    if (!is_numeric($item_vat_amount) || $item_vat_amount < 0) $errorsForRow[] = 'item_vat_amount required or invalid';
                    // VAT calculation check
                    if (abs(round($total_before_vat + $vat_amount, 2) - round($total_amount, 2)) > 0.01) $errorsForRow[] = 'VAT calculation mismatch (invoice)';
                    if (abs(round($item_total_before_vat, 2) - round($item_price * $item_quantity, 2)) > 0.01) {
                        $errorsForRow[] = 'Item total before VAT does not match quantity x price';
                    }
                    if (abs(round($item_vat_amount, 2) - round($item_total_before_vat * $item_vat_rate / 100, 2)) > 0.01) {
                        $errorsForRow[] = 'Item VAT amount does not match base x VAT rate';
                    }
                    if (!empty($errorsForRow)) {
                        $errors[] = "Row $rowNum: " . implode('; ', $errorsForRow);
                        continue;
                    }
                    // Create or get client
                    if (isset($clientCache[$issuer_tin])) {
                        $client = $clientCache[$issuer_tin];
                    } else {
                        $client = Client::where('email', $issuer_tin . '@example.com')->first();
                        if (!$client) {
                            $client = Client::create([
                                'email' => $issuer_tin . '@example.com',
                                'name' => $buyer_name,
                                'tin' => $issuer_tin,
                            ]);
                        }
                        $clientCache[$issuer_tin] = $client;
                    }
                    // Group by invoice (client_code + invoice_number + invoice_date)
                    $invoiceKey = $client->id . '|' . $invoice_number . '|' . $invoice_date;
                    if (!isset($invoiceMap[$invoiceKey])) {
                        $invoice = Invoice::create([
                            'client_id' => $client->id,
                            'total' => $total_amount,
                            'status' => 'pending',
                            'created_by' => $userId,
                            'number' => $invoice_number,
                            'invoice_date' => $invoice_date_db,
                            'created_at' => now(),
                        ]);
                        $invoiceMap[$invoiceKey] = $invoice;
                        $createdInvoiceIds[] = $invoice->id;
                        $invoiceItemsMap[$invoiceKey] = [];
                        $invoiceMeta[$invoiceKey] = [
                            'number' => $invoice_number,
                            'date' => $invoice_date,
                            'client_tin' => $issuer_tin,
                        ];
                    } else {
                        $invoice = $invoiceMap[$invoiceKey];
                    }
                    // Explicitly assign from Excel data for correct VAT/price calculations
                    $item_total_before_vat = isset($data['item_total_before_vat']) ? (float)$data['item_total_before_vat'] : null;
                    $item_vat_amount = isset($data['item_vat_amount']) ? (float)$data['item_vat_amount'] : null;
                    \Log::info('DEBUG: Item VAT/price values', [
                        'item_total_before_vat' => $item_total_before_vat,
                        'item_vat_amount' => $item_vat_amount,
                        'item_price' => $item_price,
                        'item_quantity' => $item_quantity,
                        'item_vat_rate' => $item_vat_rate,
                        'row_data' => $data
                    ]);
                    // Use Excel values for VAT/price fields if present
                    $item_total_with_tax = $item_price * $item_quantity;
                    $item_total_without_tax = ($item_total_before_vat !== null) ? $item_total_before_vat : round($item_total_with_tax / (1 + ($item_vat_rate / 100)), 2);
                    $item_total_tax = ($item_vat_amount !== null) ? $item_vat_amount : ($item_total_with_tax - $item_total_without_tax);
                    $item_price_with_tax = $item_price;
                    $item_price_without_tax = ($item_quantity > 0) ? round($item_total_without_tax / $item_quantity, 2) : 0;
                    $item_amount = $item_price * $item_quantity;
                    // Collect item data for this invoice
                    $invoiceItemsMap[$invoiceKey][] = [
                        'item_name' => $item_name,
                        'quantity' => $item_quantity,
                        'price' => $item_price,
                        'total' => $item_amount,
                        'unit' => $business_unit,
                        'vat_rate' => $item_vat_rate,
                        'vat_amount' => $item_vat_amount,
                        'currency' => $payment_method,
                        'item_unit_id' => $item_unit_id,
                        'tax_rate_id' => $tax_rate_id,
                        'item_id' => $item_id,
                        'item_type_id' => $item_type_id,
                        'item_code' => $item_code,
                        'warehouse_id' => $warehouse_id,
                        // Fiscalization-specific fields
                        'item_price_with_tax' => $item_price_with_tax,
                        'item_price_without_tax' => $item_price_without_tax,
                        'item_total_with_tax' => $item_total_with_tax,
                        'item_total_without_tax' => $item_total_without_tax,
                        'item_total_tax' => $item_total_tax,
                        'item_sales_tax_percentage' => $item_vat_rate,
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
                        $meta = array_merge($invoiceMeta[$invoiceKey] ?? [], [
                            'customer_id' => $customer_id,
                            'city_id' => $city_id,
                            'automatic_payment_method_id' => $automatic_payment_method_id,
                            'currency_id' => $currency_id,
                            'cash_register_id' => $cash_register_id,
                            'fiscal_invoice_type_id' => $fiscal_invoice_type_id,
                            'fiscal_profile_id' => $fiscal_profile_id,
                        ]);
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