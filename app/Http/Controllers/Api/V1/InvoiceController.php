<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\InvoiceModel;
use App\Models\User;
use App\Models\Tenant;
use App\Models\SpacePaymentModel;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    // CREATE
    public function create(array $data, $spot_id)
    {
        $validator = Validator::make($data, [
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric',
            'book_spot_id' => 'required|numeric',
            'booked_by_user_id' => 'required|numeric',
            'tenant_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()];
        }

        $validated = $validator->validated();
        $validated['invoice_ref'] = InvoiceModel::generateInvoiceRef();
        $validated['status'] = 'pending';

        $invoice = InvoiceModel::create($validated);

        if (!$invoice) {
            return ['error' => 'Invoice creation failed'];
        }

        return [
            'message' => 'Invoice created successfully',
            'invoice_ref' => $invoice->invoice_ref,
            'invoice' => $invoice,
            'success' => true,
        ];
    }

    // READ ALL
    public function index($slug)
    {
        $tenant = Tenant::where('slug', $slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $invoices = InvoiceModel::with([
                'bookSpot:id,id,user_id,start_time,invoice_ref,fee',
                'user:id,id,first_name,last_name',
                'spacePaymentModel:invoice_ref,amount,payment_status,created_at'
            ])
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('invoice_ref')
            ->select('id', 'book_spot_id', 'user_id', 'invoice_ref', 'tenant_id')
            ->get();

        if ($invoices->isEmpty()) {
            return response()->json(['message' => 'No invoices found'], 404);
        }

        return response()->json([
            'success' => true,
            'invoices' => $invoices,
        ]);
    }

    // READ SINGLE
    public function show(Request $request, $slug, $id)
    {
        $tenant = Tenant::where('slug', $slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $invoice = InvoiceModel::with([
                'bookSpot:id,id,user_id,start_time,invoice_ref,fee,chosen_days,expiry_day',
                'user:id,id,first_name,last_name'
            ])
            ->find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $chosenDays = json_decode(optional($invoice->bookSpot)->chosen_days, true);
        $expiryDay = optional($invoice->bookSpot)->expiry_day;

        $invoice['schedule'] = is_array($chosenDays) && $expiryDay
            ? $this->generateSchedule($chosenDays, Carbon::parse($expiryDay))
            : [];

        return response()->json(['invoice' => $invoice]);
    }

    // UPDATE
    public function update($id, array $data)
    {
        $invoice = InvoiceModel::find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validator = Validator::make($data, [
            'user_id' => 'sometimes|required|exists:users,id',
            'invoice_ref' => 'sometimes|required|exists:book_spots,invoice_ref',
            'amount' => 'sometimes|required|numeric',
            'book_spot_id' => 'sometimes|required|numeric|exists:book_spots,id',
            'booked_user_id' => 'sometimes|required|numeric|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $invoice->update($validator->validated());

        return response()->json([
            'message' => 'Invoice updated successfully',
            'invoice' => $invoice,
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

        return response()->json(['message' => 'Invoice deleted successfully']);
    }

    // CLOSE INVOICE
    public function closeInvoice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_ref' => 'required|exists:space_payment_models,invoice_ref',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $ref = $request->invoice_ref;

        $payment = SpacePaymentModel::where('invoice_ref', $ref)->first();

        if (!$payment) {
            return response()->json(['error' => 'Payment not found for given invoice_ref'], 404);
        }

        $payment->update(['payment_status' => 'completed']);

        return response()->json([
            'message' => 'Invoice closed successfully',
        ], 200);
    }

    // Schedule generator
    private function generateSchedule(array $chosenDays, Carbon $expiryDate): array
    {
        $schedule = [];

        foreach ($chosenDays as $day) {
            $weekday = strtolower($day['day']);
            $startTime = Carbon::parse($day['start_time'])->format('H:i:s');
            $endTime = Carbon::parse($day['end_time'])->format('H:i:s');
            $current = Carbon::parse($day['start_time'])->copy();

            while ($current->lte($expiryDate)) {
                $schedule[] = [
                    'day' => $weekday,
                    'date' => $current->toDateString(),
                    'start_time' => $current->format('Y-m-d H:i:s'),
                    'end_time' => $current->copy()->setTimeFromTimeString($endTime)->format('Y-m-d H:i:s'),
                ];

                $current->addWeek();
            }
        }

        usort($schedule, fn ($a, $b) => strtotime($a['start_time']) <=> strtotime($b['start_time']));
        return $schedule;
    }
}
