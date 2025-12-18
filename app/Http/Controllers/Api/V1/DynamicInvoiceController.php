<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\TimeZoneController as TimeZone;
use App\Models\TimeZoneModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant,Location, User, Space, BookedRef, SpacePaymentModel,TimeSetUpModel,ReservedSpots};
use App\Http\Controllers\Api\V1\InvoiceController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\InvoiceModel;
use App\Models\TaxModel;
use App\Models\PaymentListing;
use App\Models\Charge;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\BookingInvoiceMail;
use Illuminate\Support\Facades\Mail;

class DynamicInvoiceController extends Controller
{
    //
    public function Create(Request $request, $slug)
    {
        
         DB::beginTransaction();

    try {
        $validated = $this->validateBookingRequest($request);
        $numberWeeks = (int) ($validated['number_weeks'] ?? 0);
        $numberMonths = (int) ($validated['number_months'] ?? 0);
        $numberDays = count($validated['chosen_days'] ?? []);

if (isset($validated['error'])) {
    return response()->json([
        'message' => $validated['error'],
        'success' => false
    ], 422);
}
        $loggedUser = Auth::user();
        // Early validation
        if ($validated['type'] !== 'one-off' || ($validated['number_weeks'] || $validated['number_months'])) {
            return response()->json(['message' => 'This is available for one-off bookings only'], 422);
        }

      $tenant = $this->confirmSpot($validated['spot_id']);


if (!$tenant) {
    return response()->json(['message' => 'Spot not available for this workspace'], 422);
}

// proceed with $tenant if passed validation

        $tenant = $this->getTenantFromSpot($validated['spot_id']);
    
        if (!$tenant||!$tenant->space) {
            return response()->json(['message' => 'Spot not found for this space'], 404);
        }
        

        $tenantData = Tenant::with('bankAccounts','locations:id,name,state,address,tenant_id','locations.timezone:tenant_id,location_id,utc_time_zone')->where('slug', $slug)->first();
       $bank = $tenantData->bankAccounts->where('location_id', $tenant->location_id)->first();
       
            if (!$bank) {
                return response()->json(['message' => 'Kindly set up bank details for this location'], 404);
            }

        
        $invoiceUser = User::select('first_name', 'last_name', 'email')->find($validated['user_id']);

        $chosenDays = $this->normalizeChosenDays($validated['chosen_days']);
        
        
        $expiryDay = $this->calculateExpiryDate($validated['type'], $chosenDays, $validated);

        $tenantAvailability = $this->getTenantAvailability($slug, $chosenDays);
        
        if ($tenantAvailability->isEmpty()) {
            return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
        }

        if (!$this->areAllDaysAvailable($chosenDays, $tenantAvailability)) {
            return response()->json(['message' => 'One or more days are not available for booking'], 422);
        }

        if (!$this->areChosenTimesValid($chosenDays, $tenantAvailability)) {
            return response()->json(['message' => 'Chosen time is outside available hours'], 422);
        }

        if ($this->hasConflicts($validated['spot_id'], $chosenDays)) {
        
            return response()->json(['message' => 'Spot already reserved for selected time'], 409);
        }
        
          $timezone_status = new TimeZone();
            $timezoneCheck = $timezone_status->time_zone_status([
                'location_id' => $bank->location_id,
                'tenant_id' => $tenantData->id,
            ]);
            

            if (!$timezoneCheck) {
                return response()->json(['message' => 'Kindly set timezone for this location'], 422);
            }
        
        // Prevent duplicate reservations for the same user/time/spot
        foreach ($chosenDays as $day) {
            $exists = ReservedSpots::where([
                'user_id' => $validated['user_id'],
                'spot_id' => $validated['spot_id'],
                'day' => $day['day'],
                'start_time' => $day['start_time'],
                'end_time' => $day['end_time'],
            ])->exists();

            if ($exists) {
                return response()->json([
                    'message' => "This spot is already reserved for user on {$day['day']} between {$day['start_time']} and {$day['end_time']}",
                ], 409);
            }
        }

        

        // Calculate fees
        $duration_data = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
        $totalDuration = $duration_data['total_duration'];

        
        $spot_data = Spot::where('spots.id', $validated['spot_id'])
    ->join('spaces', 'spaces.id', '=', 'spots.space_id')->join('categories', 'spaces.space_category_id', 'categories.id')
    ->select('spots.*', 'spaces.space_name as space_name', 'spaces.space_category_id', 'spaces.space_fee', 'spaces.min_space_discount_time', 
    'spaces.space_discount', 'categories.id as space_category_id', 'categories.booking_type as booking_type', 'categories.min_duration as category_min_duration') // adjust as needed
    ->first(); 

        
        $amount_booked = $this->calculateBookingAmount($validated, $spot_data, $totalDuration);
        $amount = $amount_booked;

        // Apply Taxes
        $tax_data = [];
        $payment_listing = [];
         $charge_data = []; 
        $extra_name = $validated['item_name'] ?? [];
        $extra_charge = $validated['item_charge'] ?? [];
        $extra_number = $validated['item_number'] ?? [];
        for ($i = 0; $i < count($extra_name); $i++) {
            $itemTotal = $extra_charge[$i] * $extra_number[$i];
            $amount += $itemTotal;
            $payment_listing[] = [
                'name' => $extra_name[$i],
                'fee'  => $itemTotal,
            ];
            $charge_data[] = ['charge_name' => $extra_name[$i], 'unit_amount' => $extra_charge[$i], 'total_amount' => $itemTotal, 'quantity' => $extra_number[$i]];
        }
        
foreach (TaxModel::where('tenant_id', $tenant->tenant_id)->get() as $tax) {
    $taxAmount = $amount_booked * ($tax->percentage / 100);
    $amount += $taxAmount;
    $tax_data[] = ['tax_name' => $tax->name, 'amount' => $taxAmount];
     $payment_listing[] = [
        'name' => $tax->name,
        'fee'  => $taxAmount,
    ];
}

        
       // initialize before loop
foreach (Charge::where('tenant_id', $tenant->tenant_id)->where('space_id', $spot_data->space_id)->get() as $charge) {
    if ($charge->is_fixed) {
        $charge_amount = $charge->value;
    } else {
        $charge_amount = $amount_booked * ($charge->value / 100);
    }
    $amount += $charge_amount;
    $charge_data[] = ['charge_name' => $charge->name, 'unit_amount' => $charge->value, 'total_amount' => $charge_amount, 'quantity' => 1];
     $payment_listing[] = [
        'name' => $charge->name,
        'fee'  => $charge_amount,
    ];
}

        $reference = (new BookedRef)->generateRef($slug);
        

        $bookedRef = BookedRef::firstOrCreate([
            'user_id' => $validated['user_id'],
            'spot_id' => $validated['spot_id'],
            'booked_by_user' => $loggedUser->id,
            'booked_ref' => $reference,
        ], [
            'fee' => $amount,
            'payment_ref' => 'N/A',
        ]);

        $bookSpot = BookSpot::firstOrCreate([
            'spot_id' => $validated['spot_id'],
            'user_id' => $validated['user_id'],
            'booked_by_user' => $loggedUser->id,
            'booked_ref_id' => $bookedRef->id,
            'type' => $validated['type'],
        ], [
            'chosen_days' => json_encode($chosenDays->toArray()),
            'expiry_day' => $expiryDay,
            'start_time' => $chosenDays->first()['start_time'],
            'fee' => $amount,
            'tenant_id' => $tenant->tenant_id,
        ]);

        
        $reservedSpotsData = collect($chosenDays)->map(function ($day) use ($validated, $bookSpot, $expiryDay) {
    return [
        'user_id' => $validated['user_id'],
        'spot_id' => $validated['spot_id'],
        'day' => $day['day'],
        'start_time' => $day['start_time'],
        'end_time' => $day['end_time'],
        'expiry_day' => $expiryDay,
        'booked_spot_id' => $bookSpot->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];
})->toArray();
	



ReservedSpots::insert($reservedSpotsData);

        SpacePaymentModel::updateOrCreate([
            'user_id' => $validated['user_id'],
            'spot_id' => $validated['spot_id'],
            'tenant_id' => $tenant->tenant_id,
            'payment_ref' => $reference,
        ], [
            'amount' => $amount,
            'payment_status' => 'pending',
            'payment_method' => 'postpaid',
        ]);

        $invoiceController = new InvoiceController();
        $invoiceResponse = $invoiceController->create([
            'user_id' => $validated['user_id'],
            'amount' => $amount,
            'book_spot_id' => $bookSpot->id,
            'booked_by_user_id' => $loggedUser->id,
            'tenant_id' => $tenant->tenant_id,
        ], $validated['spot_id']);

        if (is_array($invoiceResponse) && isset($invoiceResponse['error'])) {
            DB::rollBack();
            return response()->json(['message' => 'Invoice not generated', 'error' => $invoiceResponse['error']], 422);
        }

        $invoiceRef = $invoiceResponse['invoice']['invoice_ref'];
        $bookSpot->update(['invoice_ref' => $invoiceRef]);
        SpacePaymentModel::where('payment_ref', $reference)->update(['invoice_ref' => $invoiceRef]);
        $chosenDays = json_decode($bookSpot->chosen_days, true);
        // Generate Schedule
        $schedule = $this->generateSchedule($chosenDays, Carbon::parse($expiryDay));
        $payment_rows =collect($payment_listing)->map(fn($item) => [
            'payment_name'       => $item['name'],
            'fee'                => $item['fee'],
            'book_spot_id'       => $bookSpot->id,
            'tenant_id'          => $tenant->tenant_id,
            'payment_by_user_id' => $validated['user_id'],
            'payment_completed'  => false,
            'created_at'         => now(),
            'updated_at'         => now(),
            ])->toArray();

PaymentListing::insert($payment_rows);

        $invoiceData = [
            'user_id' => $validated['user_id'],
            'space_price' => $tenant->space->space_fee,
            'total_price' => $amount,
            'space_category' => $tenant->space->category->category,
            'space' => $tenant->space->space_name,
            'space_booking_type' => $tenant->space->category->booking_type,
            'booked_by_user' => "{$loggedUser->first_name} {$loggedUser->last_name}",
            'user_invoice' => "{$invoiceUser->first_name} {$invoiceUser->last_name}",
            'tenant_name' => $tenantData->company_name,
            'book_data' => $bookSpot,
            'taxes' => $tax_data,
            'charges'=>$charge_data,
            'invoice_info'=>[],
            'invoice_ref' => $invoiceRef,
            'schedule' => $schedule,
            'bank_details' =>$tenantData->bankAccounts->where('location_id', $tenant->location_id)->first(),
            'time_zone' => optional($tenantData->locations->firstWhere('id', $tenant->location_id)?->timeZone)->utc_time_zone,

        ];

        DB::commit();

       try {
    Mail::to($invoiceUser->email)
        ->send(new BookingInvoiceMail($invoiceData));
} catch (\Throwable $e) {
    Log::error('Booking invoice email failed', [
        'email'       => $invoiceUser->email,
        'booked_ref'  => $reference,
        'invoice_ref' => $invoiceRef ?? null,
        'error'       => $e->getMessage(),
    ]);
}

        return response()->json([
            'message' => 'Booking successfully created',
            'booked_ref' => $reference,
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error("Booking error: " . $e->getMessage());
        return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
    }
}

private function validateBookingRequest(Request $request)
{
    $validator = Validator::make($request->all(), [
        'spot_id'   => 'required|integer|exists:spots,id',
        'user_id'   => 'required|integer|exists:users,id',
        'type'      => 'required|in:one-off,recurrent',

        'spot_fee'  => 'required|numeric|min:0',

        'item_name'       => 'nullable|array',
        'item_name.*'     => 'string|max:255',

        'item_charge'     => 'nullable|array',
        'item_charge.*'   => 'numeric|min:0',

        'item_number'     => 'nullable|array',
        'item_number.*'   => 'integer|min:1',

        'number_weeks'    => 'nullable|integer|min:0|max:3',
        'number_months'   => 'nullable|integer|min:0|max:12',

        'chosen_days'              => 'required|array|min:1',
        'chosen_days.*.day'        => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
        'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
        'chosen_days.*.end_time'   => 'required|date_format:Y-m-d H:i:s|after:start_time',
    ]);

    /**
     * Ensure item arrays have equal counts
     */
    $validator->after(function ($validator) use ($request) {
        $names   = $request->item_name ?? [];
        $charges = $request->item_charge ?? [];
        $numbers = $request->item_number ?? [];

        if (
            count($names) !== count($charges) ||
            count($names) !== count($numbers)
        ) {
            $validator->errors()->add(
                'items',
                'All extras must have the same number of entries.'
            );
        }
    });

    if ($validator->fails()) {
        return [
            'error' => $validator->errors()->first(),
        ];
    }

    return $validator->validated();
}



private function getTenantFromSpot($spotId)
{

        return Spot::with(['space.category'])->find($spotId);
    
}
private function normalizeChosenDays(array $days)
{
    return collect($days)->map(function ($day) {
        return [
            'day' => strtolower($day['day']),
            'start_time' => Carbon::parse($day['start_time']),
            'end_time' => Carbon::parse($day['end_time']),
        ];
    });
}



private function calculateExpiryDate($type, $chosenDays, $validated)
{
    $lastDay = $type === 'recurrent' ? $chosenDays->first() : $chosenDays->last();

    $weeks = (int) ($validated['number_weeks'] ?? 0);
    $months = (int) ($validated['number_months'] ?? 0);

    return $lastDay['end_time']
        ->copy()
        ->addWeeks($weeks)
        ->addMonths($months);
}


private function getTenantAvailability($slug, $chosenDays)
{
    
    $cacheKey = "tenant_availability_{$slug}_" . md5(implode(',', $chosenDays->pluck('day')->sort()->toArray()));
    return Cache::remember($cacheKey, now()->addHour(), function () use ($slug, $chosenDays) {
        return TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
            ->where('tenants.slug', $slug)
            ->whereIn('time_set_ups.day', $chosenDays->pluck('day'))
            ->select('time_set_ups.day', 'time_set_ups.open_time', 'time_set_ups.close_time')
            ->get();
    });
}

private function areAllDaysAvailable($chosenDays, $availability)
{
    $availableDays = $availability->pluck('day')->map(fn($d) => strtolower($d))->toArray();
    $requestedDays = $chosenDays->pluck('day')->toArray();
    return empty(array_diff($requestedDays, $availableDays));
}

private function areChosenTimesValid($chosenDays, $availability)
{
    $availableDays = $availability->keyBy(fn($item) => strtolower($item->day));
    

    foreach ($chosenDays as $day) {
        $dayKey = strtolower($day['day']);

        if (!isset($availableDays[$dayKey])) return false;

        // Parse availability times in UTC and extract time only
        $open = Carbon::parse($availableDays[$dayKey]->open_time, 'UTC')->format('H:i');
        $close = Carbon::parse($availableDays[$dayKey]->close_time, 'UTC')->format('H:i');

    
        $start = Carbon::parse($day['start_time'],)->setTimezone('UTC')->format('H:i');
        $end = Carbon::parse($day['end_time'],)->setTimezone('UTC')->format('H:i');
        

        if ($start < $open || $end > $close) {
            return false;
        }
    }

    return true;
}




private function hasConflicts($spotId, $chosenDays)
{
    $bookings = BookSpot::where('spot_id', $spotId)
        ->where('expiry_day', '>=', now())
        ->whereNull('deleted_at')
        ->pluck('chosen_days'); // fetch only the chosen_days column

    foreach ($bookings as $daysJson) {
        $bookedSlots = json_decode($daysJson, true) ?? [];

        foreach ($chosenDays as $inputDay) {
            $inputDayName = strtolower($inputDay['day']);
            $inputStart   = Carbon::parse($inputDay['start_time']);
            $inputEnd     = Carbon::parse($inputDay['end_time']);

            foreach ($bookedSlots as $slot) {
                $bookedDay   = strtolower($slot['day']);
                $bookedStart = Carbon::parse($slot['start_time']);
                $bookedEnd   = Carbon::parse($slot['end_time']);

                if (
                    $inputDayName === $bookedDay &&
                    $bookedStart < $inputEnd &&
                    $bookedEnd > $inputStart
                ) {
                    return true; //stop at first conflict 
                }
            }
        }
    }

    return false;
}






private function handleConflictResponse($spotId, $chosenDays)
{
    $chosenDays = collect($chosenDays)->map(function ($day) {
        return [
            'day' => Carbon::parse($day['day'])->format('l'),
            'start_time' => Carbon::parse($day['start_time']),
            'end_time' => Carbon::parse($day['end_time']),
        ];
    });

    $conflictDetails = collect();
    $conflicts = BookSpot::where('spot_id', $spotId)
        ->where('expiry_day', '>=', now())
        ->get();

    foreach ($conflicts as $conflict) {
        $bookedSlots = collect(json_decode($conflict->chosen_days, true));

        foreach ($chosenDays as $chosenSlot) {
            $chosenDay = strtolower($chosenSlot['day']);
            $chosenStart = $chosenSlot['start_time'];
            $chosenEnd = $chosenSlot['end_time'];

            foreach ($bookedSlots as $bookedSlot) {
                $bookedDay = strtolower($bookedSlot['day']);
                $bookedStart = Carbon::parse($bookedSlot['start_time']);
                $bookedEnd = Carbon::parse($bookedSlot['end_time']);

                if (
                    $chosenDay === $bookedDay &&
                    $bookedStart < $chosenEnd &&
                    $bookedEnd > $chosenStart
                ) {
                    $conflictDetails->push([
                        'day' => ucfirst($chosenDay),
                        'chosen_time' => "{$chosenStart->format('H:i')} - {$chosenEnd->format('H:i')}",
                        'booked_time' => "{$bookedStart->format('H:i')} - {$bookedEnd->format('H:i')}",
                    ]);
                }
            }
        }
    }

    if ($conflictDetails->isNotEmpty()) {
        $grouped = $conflictDetails->groupBy('day')->map(function ($conflicts, $day) {
            return [
                'day' => $day,
                'conflicts' => $conflicts->map(function ($item) {
                    return "Your slot: {$item['chosen_time']}, Booked slot: {$item['booked_time']}";
                })->unique()->values(),
            ];
        })->values();

        return response()->json([
            'message' => 'This spot is already reserved during the selected time(s).',
            'conflicts' => $grouped,
        ], 422);
    }

    return response()->json(['message' => 'No conflicts found.'], 200);
}
private function calculateTotalDuration($chosenDays, $availability)
{
    $availableDays = $availability->keyBy(fn($item) => strtolower($item->day));

    $totalDuration = 0;
    $daysWithDurations = [];

    foreach ($chosenDays as $day) {
        $dayName = strtolower($day['day']);
        $start = Carbon::parse($day['start_time']);
        $end = Carbon::parse($day['end_time']);

        // Calculate duration in hours (you can use diffInMinutes if needed)
        $duration = $start->diffInHours($end);

        $totalDuration += $duration;

        $daysWithDurations[] = [
            'day' => $day['day'],
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'duration' => $duration
        ];
    }

    return [
        'days' => $daysWithDurations,
        'total_duration' => $totalDuration
    ];
}



   private function calculateBookingAmount($validated, $tenant, $totalDuration)
{
    $numberWeeks = max((int) ($validated['number_weeks'] ?? 1), 1); // ensure at least 1
    $numberMonths = max((int) ($validated['number_months'] ?? 0), 1);
    $numberDays = count($validated['chosen_days'] ?? []);

    $discount = $tenant->space_discount > 0 ? $tenant->space_discount : 0;
    $spaceFee = $tenant->space->space_fee;
    $bookingType = $tenant->space->category->booking_type;
    $minDiscountTime = $tenant->min_space_discount_time;

    $total = 0;
    $units = 0;

    switch ($bookingType) {
        case 'monthly':
            $units = $numberMonths;
            $total = $spaceFee * $units;
            break;

        case 'weekly':
            $units = $numberWeeks;
            $total = $spaceFee * $units;
            break;

        case 'hourly':
            $units = $totalDuration;
            $total = $spaceFee * $units * $numberWeeks;
            break;

        case 'daily':
            $units = $numberDays;
            $total = $spaceFee * $units;
            break;

        default:
            return 0;
    }

    if ($discount && $units >= $minDiscountTime) {
        $total -= $total * ($discount / 100);
    }
    return $total;
}
private function confirmSpot($spotId)
{
    $spot = Spot::with('tenant')->find($spotId);

    return $spot ? $spot->tenant : null;
}
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

    usort($schedule, fn($a, $b) => strtotime($a['start_time']) <=> strtotime($b['start_time']));
    return $schedule;
}
}
