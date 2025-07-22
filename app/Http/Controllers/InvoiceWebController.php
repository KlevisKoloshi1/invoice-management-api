<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Services\FiscalizationService;

class InvoiceWebController extends Controller
{
    public function index()
    {
        $invoices = Invoice::with('client')->orderByDesc('created_at')->paginate(10);
        return view('invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('client', 'items');
        return view('invoices.show', compact('invoice'));
    }

    public function create()
    {
        return view('invoices.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'total' => 'required|numeric|min:0.01',
            'warehouse_id' => 'required|integer|min:1', // Require warehouse_id
            // Add other fields as needed
        ]);
        $data['created_by'] = auth()->id();
        $invoice = Invoice::create($data);
        $fiscalizationService = new FiscalizationService();
        $fiscalizationService->fiscalize($invoice, ['warehouse_id' => $data['warehouse_id']]);
        return redirect()->route('invoices.show', $invoice);
    }

    public function edit(Invoice $invoice)
    {
        if (!auth()->user() || $invoice->created_by !== auth()->id()) {
            abort(403);
        }
        $invoice->load('client', 'items');
        return view('invoices.edit', compact('invoice'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if (!auth()->user() || $invoice->created_by !== auth()->id()) {
            abort(403);
        }
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'total' => 'required|numeric|min:0.01',
            'status' => 'sometimes|string',
            'warehouse_id' => 'required|integer|min:1', // Require warehouse_id
        ]);
        $invoice->update($data);
        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice updated.');
    }

    public function destroy(Invoice $invoice)
    {
        if (!auth()->user() || $invoice->created_by !== auth()->id()) {
            abort(403);
        }
        $invoice->delete();
        return redirect()->route('invoices.index')->with('status', 'Invoice deleted.');
    }

    public function apiIndex()
    {
        return response()->json(Invoice::with('client', 'items')->get());
    }

    public function apiShow(Invoice $invoice)
    {
        $invoice->load('client', 'items');
        return response()->json($invoice);
    }
}
