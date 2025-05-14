<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\InvoiceModel;
use App\Models\User;
use App\Models\Tenant;

class InvoiceController extends Controller
{
    // CREATE
    public function create($data,$spot_id)
    {
        
        $validator = Validator::make((array)$data, [
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric',
            'book_spot_id' => 'required|numeric',
            'booked_by_user_id' => 'required|numeric',
            'tenant_id' => 'required|numeric|',
        ]);

        if ($validator->fails()) {
            return $data = [
                'error' => $validator->errors()
            ];
        }

    
        $validated = $validator->validated();
        $validated['invoice_ref'] = invoiceModel::generateInvoiceRef();

        $validated['status'] = 'pending';
        $invoice = InvoiceModel::create($validated);


        if (!$invoice) {
            return [
                'error' => 'Invoice creation failed'
            ];
        }
        // Assuming you have a method to send the invoice to the user
        // $this->sendInvoice($invoice);
      return [
    'message' => 'Invoice created successfully',
    'invoice_ref' => $invoice->invoice_ref,
    'invoice' => $invoice,
    'success' => true
];

    }

    // READ (all invoices)
    public function index()
    {
        $invoices = InvoiceModel::all();

        return response()->json([
            'invoices' => $invoices
        ]);
    }

    // READ (single invoice by ID)
    public function show($id)
    {
        $invoice = InvoiceModel::find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    // UPDATE
    public function update($id, $data)
    {
        $invoice = InvoiceModel::find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validator = Validator::make((array)$data, [
            'user_id' => 'sometimes|required|exists:users,id',
            'invoice_ref' => 'sometimes|required|exists:book_spots,invoice_ref',
            'amount' => 'sometimes|required|numeric',
            'book_spot_id' => 'sometimes|required|numeric|exists:book_spots,id',
            'booked_user_id' => 'sometimes|required|numeric|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $invoice->update($validated);

        return response()->json([
            'message' => 'Invoice updated successfully',
            'invoice' => $invoice
        ]);
    }

    // DELETE
    public function destroy($id)
    {
        $invoice = InvoiceModel::find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted successfully'
        ]);
    }
   
     private function getTenantId($user_id)
    {
        $user = User::find($user_id);
        if (!$user) {
            return null;
        }

        return $user->tenant_id;
    }
}
