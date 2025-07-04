<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\InvoiceModel;
use App\Models\User;
use App\Models\Spot;
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

    if (!isset($validated['status'])) {
        $validated['status'] = 'pending';
    }

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
                'spacePayment:invoice_ref,amount,payment_status,created_at'
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
    $tenant = Tenant::with('bankAccounts')->where('slug', $slug)->first();
   

    if (!$tenant) {
        return response()->json(['message' => 'Tenant not found'], 404);
    }

    $invoice = InvoiceModel::with([
        'bookSpot:id,spot_id,user_id,start_time,invoice_ref,fee,chosen_days,expiry_day',
        'user:id,first_name,last_name'
    ])->find($id);

    if (!$invoice) {
        return response()->json(['error' => 'Invoice not found'], 404);
    }

    $spot = optional($invoice->bookSpot);
     $space_info = $this->getTenantFromSpot( $spot->id);
    $locationId = $spot ? Spot::where('id', $spot->spot_id)->value('location_id') : null;

    $bank = $tenant->bankAccounts
        ->where('tenant_id', $tenant->id)
        ->where('location_id', $locationId)
        ->first();

    $chosenDays = json_decode($spot->chosen_days, true);
    $expiryDay = $spot->expiry_day;

    $invoice['schedule'] = is_array($chosenDays) && $expiryDay
        ? $this->generateSchedule($chosenDays, Carbon::parse($expiryDay))
        : [];

    return response()->json([
        'invoice' => $invoice,
        'bank'    => $bank,
        'space_info'=>$space_info
    ]);
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
        $invoice_model = InvoiceModel::where('invoice_ref', $ref)->first();


        if (!$payment) {
            return response()->json(['error' => 'Payment not found for given invoice_ref'], 404);
        }

        $payment->update(['payment_status' => 'completed']);
        $invoice_model->update(['status'=>'paid']);

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
    public function cancelInvoice($book_spot_id)
    {
        $data = InvoiceModel::where('book_spot_id', $book_spot_id)->first();

        if (!$data) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }
        $data->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Invoice cancelled successfully']);
    }
private function getTenantFromSpot($spotId)
{
    return Spot::with([
        'space:id,space_name,space_category_id',
        'space.category:id,category',
        'floor:id,name',
        'location:id,name,address',
    ])->find($spotId); 
}

}
