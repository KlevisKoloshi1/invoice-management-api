<?php

namespace App\Http\Controllers;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ImportWebController extends Controller
{
    public function index()
    {
        $imports = Import::with('user')->orderByDesc('created_at')->paginate(10);
        return view('imports.index', compact('imports'));
    }

    public function create()
    {
        if (!Auth::user()) {
            abort(403);
        }
        return view('imports.create');
    }

    public function store(Request $request)
    {
        if (!Auth::user()) {
            abort(403);
        }
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $userId = Auth::id();
        if (!$userId) {
            return redirect()->back()->withErrors(['auth' => 'You must be logged in to upload an import.'])->withInput();
        }
        $service = app(\App\Services\ImportService::class);
        $result = $service->importFromExcel($request->file('file'), $userId);
        if (!empty($result['errors'])) {
            return redirect()->back()->withErrors($result['errors'])->withInput();
        }
        if (isset($result['invoice_ids']) && count($result['invoice_ids']) > 0) {
            return redirect()->route('imports.invoices', ['ids' => implode(',', $result['invoice_ids'])]);
        }
        return redirect()->route('imports.index')->with('status', 'Import completed. Success: ' . $result['success_count']);
    }

    public function edit(Import $import)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            abort(403);
        }
        return view('imports.edit', compact('import'));
    }

    public function update(Request $request, Import $import)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            abort(403);
        }
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|string',
            'file' => 'sometimes|file|mimes:xlsx,xls',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $data = $request->all();
        if ($request->hasFile('file')) {
            $data['file'] = $request->file('file');
        }
        $service = app(\App\Services\ImportService::class);
        $service->updateImport($import->id, $data);
        return redirect()->route('imports.index')->with('status', 'Import updated.');
    }

    public function destroy(Import $import)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            abort(403);
        }
        $service = app(\App\Services\ImportService::class);
        $service->deleteImport($import->id);
        return redirect()->route('imports.index')->with('status', 'Import deleted.');
    }

    public function publicIndex()
    {
        $imports = Import::orderByDesc('created_at')->paginate(10);
        return view('imports.public', compact('imports'));
    }

    public function importedInvoices(Request $request)
    {
        $ids = explode(',', $request->query('ids', ''));
        $invoices = \App\Models\Invoice::whereIn('id', $ids)->get();
        return view('imports.imported_invoices', compact('invoices'));
    }
}
