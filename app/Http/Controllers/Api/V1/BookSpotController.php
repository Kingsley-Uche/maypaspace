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

class BookSpotController extends Controller
{

        
public function create(Request $request, $slug)
{
    DB::beginTransaction();

    try {
        $validated = $this->validateBookingRequest($request);

if (isset($validated['error'])) {
    return response()->json([
        'message' => $validated['error'],
        'success' => false
    ], 422);
}
        $loggedUser = Auth::user();
        

        // Early validation
        if ($validated['type'] === 'one-off' && ($validated['number_weeks'] || $validated['number_months'])) {
            return response()->json(['message' => 'One-off booking cannot have weeks or months specified'], 422);
        }

        if ($validated['type'] === 'recurrent' && (!$validated['number_weeks'] && !$validated['number_months'])) {
            return response()->json(['message' => 'Recurrent booking must have weeks or months specified'], 422);
        }

        $tenant = $this->confirmSpot($validated['spot_id']);


if (!$tenant) {
    return response()->json(['message' => 'Spot not available for this workspace'], 422);
}

// proceed with $tenant if needed...

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

        if ($this->isInvalidRecurrentBooking($validated, $tenant)) {
            return response()->json(['message' => 'This space is only available for monthly booking'], 422);
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
        
foreach (TaxModel::where('tenant_id', $tenant->tenant_id)->get() as $tax) {
    $taxAmount = $amount_booked * ($tax->percentage / 100);
    $amount += $taxAmount;
    $tax_data[] = ['tax_name' => $tax->name, 'amount' => $taxAmount];
     $payment_listing[] = [
        'name' => $tax->name,
        'fee'  => $taxAmount,
    ];
}
        
        $charge_data = []; // initialize before loop
foreach (Charge::where('tenant_id', $tenant->tenant_id)->where('space_id', $spot_data->space_id)->get() as $charge) {
    if ($charge->is_fixed) {
        $charge_amount = $charge->value;
    } else {
        $charge_amount = $amount_booked * ($charge->value / 100);
    }
    $amount += $charge_amount;
    $charge_data[] = ['charge_name' => $charge->name, 'amount' => $charge_amount];
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
            'invoice_ref' => $invoiceRef,
            'schedule' => $schedule,
            'bank_details' =>$tenantData->bankAccounts->where('location_id', $tenant->location_id)->first(),
            'time_zone' => optional($tenantData->locations->firstWhere('id', $tenant->location_id)?->timeZone)->utc_time_zone,

        ];

        DB::commit();

        // Send invoice via email
        Mail::to($invoiceUser->email)->send(new BookingInvoiceMail($invoiceData));

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

/**
 * Generate weekly recurring schedule until expiry
 */
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




private function validateBookingRequest(Request $request)
{
    $validator = Validator::make($request->all(), [
        'spot_id' => 'required|numeric|exists:spots,id',
        'user_id' => 'required|numeric|exists:users,id',
        'type' => 'required|in:one-off,recurrent',
        'number_weeks' => 'nullable|numeric|min:0|max:3',
        'number_months' => 'nullable|numeric|min:0|max:12',
        'book_spot_id'=>'nullable|numeric|min:0',
        'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
        'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
        'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
    ]);

    if ($validator->fails()) {
        return [
            'error' => $validator->errors()->first() // return first error message
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






// private function calculateTotalDuration($chosenDays, $availability)
// {
//     $availableDays= $availability->keyBy(fn($item) => strtolower($item->day));
//     return $chosenDays->sum(function ($day) use ($availableDays) {
//         $start = $day['start_time'];
//         $end = $day['end_time'];
//         return $start->diffInHours($end);
//     });
// }

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

private function isInvalidRecurrentBooking($validated, $tenant)
{
    return $validated['type'] === 'recurrent' &&
           ($validated['number_weeks'] > 1 && $validated['number_months'] === 0) &&
           $tenant->booking_type === 'monthly';
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


public function update(Request $request, $slug)
{
    
    try {
        // Validate request
        $validated = $this->validateBookingRequest($request, true);
        $loggedUser = Auth::user();
        // Fetch the booking
        $booking = BookSpot::where('id', $request->book_spot_id)
            ->where('booked_by_user', $loggedUser->id)
            ->firstOrFail();
        // Get tenant from spot
        $tenant = $this->getTenantFromSpot($validated['spot_id']);
        if (!$tenant) {
            return response()->json(['message' => 'Spot not found'], 404);
        }
        // Normalize chosen days
        $chosenDays = $this->normalizeChosenDays($validated['chosen_days']);
        $expiryDay = $this->calculateExpiryDate($validated['type'], $chosenDays, $validated);

        // Check tenant availability
        $tenantAvailability = $this->getTenantAvailability($slug, $chosenDays);
    
        if ($tenantAvailability->isEmpty()) {
            return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
        }

        // Validate days availability
        if (!$this->areAllDaysAvailable($chosenDays, $tenantAvailability)) {
            return response()->json(['message' => 'One or more days are not available for booking'], 422);
        }

        // Validate chosen times
        if (!$this->areChosenTimesValid($chosenDays, $tenantAvailability)) {
            return response()->json(['message' => 'Chosen time is outside available hours'], 422);
        }

        // Check for conflicts, excluding the current booking
        if ($this->hasConflicts($validated['spot_id'], $chosenDays, $booking->id)) {
        
            return $this->handleConflictResponse($validated['spot_id'], $chosenDays);
        }

        // Validate recurrent booking
        if ($this->isInvalidRecurrentBooking($validated, $tenant)) {
            return response()->json(['message' => 'This space is only available for monthly booking'], 422);
        }
        if($this->isExpired($validated['book_spot_id'])){

            return response()->json(['message' => 'Expired booking can not modified'], 422);
        }
        

        // Calculate total duration and amount
        $totalDuration = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
        $amount = $this->calculateBookingAmount($validated, $tenant, $totalDuration);


        // Update within a transaction
        DB::transaction(function () use ($validated, $booking, $chosenDays, $expiryDay, $amount, $tenant, $loggedUser) {
            // Update BookedRef
            $bookedRef = BookedRef::where('id', $booking->booked_ref_id)->firstOrFail();
            $bookedRef->update([
                'user_id' => $validated['user_id'],
                'booked_by_user' => $loggedUser->id,
                'spot_id' => $validated['spot_id'],
                'fee' => $amount,
            ]);

            // Update BookSpot
            $booking->update([
                'spot_id' => $validated['spot_id'],
                'user_id' => $validated['user_id'],
                'booked_by_user' => $loggedUser->id,
                'type' => $validated['type'],
                'chosen_days' => json_encode($chosenDays->toArray()),
                'expiry_day' => $expiryDay,
            ]);

            // Delete old ReservedSpots and insert new ones
            ReservedSpots::where('booked_spot_id', $booking->id)->delete();
            $reservedSpotsData = $chosenDays->map(function ($day) use ($validated, $expiryDay, $booking) {
                return [
                    'user_id' => $validated['user_id'],
                    'spot_id' => $validated['spot_id'],
                    'day' => $day['day'],
                    'start_time' => $day['start_time'],
                    'end_time' => $day['end_time'],
                    'expiry_day' => $expiryDay,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'booked_spot_id' => $booking->id,
                ];
            })->toArray();
            ReservedSpots::insert($reservedSpotsData);

            // Update SpacePaymentModel
            SpacePaymentModel::where('payment_ref', $bookedRef->booked_ref)->update([
                'user_id' => $validated['user_id'],
                'spot_id' => $validated['spot_id'],
                'tenant_id' => $tenant->tenant_id,
                'amount' => $amount,
            ]);
        });

        return response()->json(['message' => 'Booking successfully updated'], 200);

    } catch (\Exception $e) {
        return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
    }
}

    // Cancel an existing booking
    public function cancelBooking(Request $request)
    {
    
        $validator = Validator::make($request->all(), [
            'book_spot_id' => 'required|numeric|exists:book_spots,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $userId = Auth::id();

        $booking = BookSpot::where('id', $request->book_spot_id)
            ->where(function ($query) use ($userId) {
                $query->where('booked_by_user', $userId)
                      ->orWhere('user_id', $userId);
            })
            ->with('spot')
            ->firstOrFail();
            $reservedSpots = ReservedSpots::where('booked_spot_id', $booking->id)->get();

        DB::transaction(function () use ($booking,$reservedSpots) {
            $booking->delete();
            $reservedSpots->each(function ($reservedSpot) {
                $reservedSpot->delete();
            });
        });

        $invoiceCont =new InvoiceController();
        $invoiceCont-> cancelInvoice($booking->id);
        PaymentListing::where('book_spot_id',$booking->id)->update(['payment_completed'=>false]);
        return response()->json(['message' => 'Booking successfully canceled'], 200);
    }

  

public function getBookings(Request $request)
{
    $validator = Validator::make($request->all(), [
        'booking_type' => 'required|string|in:valid,all,expired,past,today',
        'start_time'   => 'sometimes|date_format:Y-m-d H:i:s',
        'end_time'     => 'sometimes|date_format:Y-m-d H:i:s|after:start_time',
    ]);

    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    $validated = $validator->validated();
    $tenantId = Auth::user()->tenant_id;

    $query = BookSpot::withTrashed()->with([
        'spot.space.category',
        'bookedRef'
    ])->whereHas('spot.space', function ($q) use ($tenantId) {
        $q->where('tenant_id', $tenantId);
    });

    switch ($validated['booking_type']) {
        case 'valid':
            $query->where('expiry_day', '>=', Carbon::now())
                  ->whereNull('deleted_at');
            break;

        case 'expired':
            $query->where('expiry_day', '<', Carbon::now())
                  ->whereNull('deleted_at');
            break;

        case 'past':
            if (!$request->has('start_time') || !$request->has('end_time')) {
                return response()->json([
                    'error' => 'Both start_time and end_time are required for past bookings'
                ], 422);
            }
            $start = Carbon::parse($validated['start_time']);
            $end   = Carbon::parse($validated['end_time']);
            $query->whereBetween('start_time', [$start, $end])
                  ->where('start_time', '<=',$end )// Carbon::now())
                  ->whereNull('deleted_at');
            break;

        case 'today':
            if (!$request->has('start_time')) {
                return response()->json([
                    'error' => "start_time is required for the today's booking"
                ], 422);
            }
    // Always use the date provided by the request
    $date  = Carbon::parse($validated['start_time']);
    $start = $date->copy()->startOfDay();
    $end   = $date->copy()->endOfDay(); 

    $query->whereBetween('start_time', [$start, $end]);
          //->whereNull('deleted_at');
    break;


        case 'all':
            // includes canceled too (because of withTrashed)
            break;
    }

    $bookings = $query->orderByDesc('id')->get();


    $bookings->transform(function ($booking) {
        $now = Carbon::now();

        if ($booking->deleted_at !== null) {
            $booking->book_status = 'canceled';
            $booking->fee =0;
        } elseif ($now->gt(Carbon::parse($booking->expiry_day))) {
            $booking->book_status = 'completed';
        } elseif ($now->between(Carbon::parse($booking->start_time), Carbon::parse($booking->expiry_day))) {
            $booking->book_status = 'ongoing';
        } elseif ($now->lt(Carbon::parse($booking->start_time))) {
            $booking->book_status = 'awaiting';
        } else {
            $booking->book_status = 'unknown';
        }

        return $booking;
    });

    return response()->json(['data' => $bookings], 200);
}

 

   public function getFreeSpots(Request $request, $tenant_slug, $location_id = null)
{
    $spotsByCategory = [];

    // Validate location_id
    if ($request->has('location_id') && $request->location_id !== null) {
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|numeric|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $location_id = $request->location_id;
    }

    // Fetch tenant
    $tenant = Tenant::where('slug', $tenant_slug)->first();
    if (!$tenant) {
        return response()->json(['error' => 'Workspace doesn\'t exist'], 404);
    }

    // Query for free spots
    $query = Spot::where('spots.tenant_id', $tenant->id)
        ->where('spots.book_status', 'no');

    if ($location_id) {
        $query->where('spots.location_id', $location_id);
    }

    $query->select('spots.id', 'spots.space_id', 'spots.location_id', 'spots.floor_id')
        ->with([
            'location:id,name',
            'floor:id,name',
            'space:id,space_name,space_fee,space_category_id',
            'space.category:id,category,booking_type',
            'space.category.images:id,image_path,category_id', // include category images only
        ])
        ->join('spaces', 'spots.space_id', '=', 'spaces.id')
        ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
        ->orderBy('categories.category')
        ->chunk(1000, function ($freeSpots) use (&$spotsByCategory) {
            foreach ($freeSpots as $spot) {
                $category = $spot->space->category ?? null;
                $categoryName = $category?->category ?? 'Uncategorized';

                if (!isset($spotsByCategory[$categoryName])) {
                    $spotsByCategory[$categoryName] = [
                        'category_id' => $category->id ?? null,
                        'category_name' => $categoryName,
                        'booking_type' => $category->booking_type ?? 'Unknown',
                        'images' => $category?->images->pluck('image_path')->toArray() ?? [],
                        'spots' => [],
                    ];
                }

                $spotsByCategory[$categoryName]['spots'][] = [
                    'spot_id' => $spot->id,
                    'space_name' => $spot->space->space_name,
                    'space_fee' => $spot->space->space_fee,
                    'location_id' => $spot->location_id,
                    'location_name' => $spot->location->name ?? 'Unknown',
                    'floor_name' => $spot->floor->name ?? 'Unknown',
                    'floor_id' => $spot->floor_id,
                    'booking_type'=>$spot->space->category->booking_type,
                ];
            }
        });

    return response()->json(['data' => array_values($spotsByCategory)], 200);
}

    
    
    public function getAllSpots(Request $request)
{
    $user = Auth::user();
    $spotsByCategory = [];

    $dayMap = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    Spot::where('spots.tenant_id', $user->tenant_id)
        ->select('spots.id', 'spots.space_id', 'spots.location_id', 'spots.floor_id')
        ->with([
            'location:id,name',
            'space:id,space_name,space_fee,space_category_id',
            'space.category:id,category,booking_type',
            'floor:id,name',
            'bookedspots:id,spot_id,chosen_days,expiry_day', // Added this line
        ])
        ->join('spaces', 'spots.space_id', '=', 'spaces.id')
        ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
        ->orderBy('categories.category')
        ->chunk(1000, function ($spots) use (&$spotsByCategory, $dayMap) {
            foreach ($spots as $spot) {
                $bookedTimes = [];

                if ($spot->bookedspots->isNotEmpty()) {
                    foreach ($spot->bookedspots as $bookedSpot) {
                        $chosenDays = json_decode($bookedSpot->chosen_days, true);
                        $endDate = Carbon::parse($bookedSpot->expiry_day);
                        foreach ($chosenDays as $chosenDay) {
                            $dayName = strtolower($chosenDay['day']);
                            if (!isset($dayMap[$dayName])) continue;

                            $dayOfWeek = $dayMap[$dayName];
                            $startTime = Carbon::parse($chosenDay['start_time'])->format('H:i:s');
                            $endTime = Carbon::parse($chosenDay['end_time'])->format('H:i:s');

                            $startDate = Carbon::today();
                            $period = CarbonPeriod::create($startDate, $endDate);

                            foreach ($period as $date) {
                                if ($date->dayOfWeek === $dayOfWeek) {
                                    $bookedTimes[] = [
                                        'date' => $date->toDateString(),
                                        'day' => $date->dayName,
                                        'start_time' => $startTime,
                                        'end_time' => $endTime,
                                    ];
                                }
                            }
                        }
                    }
                }

                $categoryName = $spot->space->category->category ?? 'Uncategorized';
                if (!isset($spotsByCategory[$categoryName])) {
                    $spotsByCategory[$categoryName] = [];
                }
                $spotsByCategory[$categoryName][] = [
                    'space_id'=>$spot->space->id,
                    'spot_id' => $spot->id,
                    'space_name' => $spot->space->space_name,
                    'space_fee' => $spot->space->space_fee,
                    'location_id' => $spot->location_id,
                    'location_name' => $spot->location->name ?? null,
                    'floor_id' => $spot->floor_id,
                    'floor_name' => $spot->floor->name ?? null,
                    'booked_times' => $bookedTimes,
                    'book_spot_id' => $spot->bookedSpots->first()->id ?? null,
                    'booking_type'=>$spot->space->category->booking_type,
                ];
            }
        });

    if (empty($spotsByCategory)) {
        return response()->json(['message' => 'No unbooked spots available'], 404);
    }

    return response()->json(['data' => $spotsByCategory], 200);
}


    private function CreateUser($data){
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => bcrypt($data['password']),
            'user_type_id' => 3,
            'tenant_id' => $data['tenant_id'],
            'spot_id' => $data['spot_id'],
        ]);

        return $user;

    }
    public function getSingle(Request $request)
{
    try {
        // Validate request
        $validated = Validator::make($request->all(), [
            'book_spot_id' => 'required|numeric|exists:book_spots,id',
        ])->validate();

        // Fetch the booking with related data
        $booking = BookSpot::with([
            'bookedRef' => function ($query) {
                $query->select('id', 'user_id', 'spot_id', 'fee', 'payment_ref', 'booked_ref');
            },
            'reservedSpots' => function ($query) {
                $query->select('id', 'booked_spot_id', 'day', 'start_time', 'end_time', 'expiry_day');
            },
            'spot' => function ($query) {
                $query->select('id', 'space_id', 'tenant_id')
                      ->with(['space' => function ($q) {
                          $q->select('id', 'tenant_id', 'space_fee', 'category_id')
                            ->with(['category' => function ($qc) {
                                $qc->select('id', 'booking_type');
                            }]);
                      }]);
            }
        ])
        ->where('id', $validated['book_spot_id'])
        ->where('booked_by_user', Auth::user()->id)
        ->first();

        // Check if booking exists
        if (!$booking) {
            return response()->json(['message' => 'Booking not found or you do not have permission to view it'], 404);
        }

        // Fetch payment details
        $payment = SpacePaymentModel::where('payment_ref', $booking->bookedRef->booked_ref)
            ->select('amount', 'payment_status', 'payment_method', 'payment_ref')
            ->first();

        // Structure the response
        $response = [
            'booking_id' => $booking->id,
            'spot_id' => $booking->spot_id,
            'user_id' => $booking->user_id,
            'booked_by_user' => $booking->booked_by_user,
            'type' => $booking->type,
            'chosen_days' => json_decode($booking->chosen_days, true),
            'expiry_day' => $booking->expiry_day->toDateTimeString(),
            'fee' => $booking->bookedRef->fee,
            'payment_ref' => $booking->bookedRef->booked_ref,
            'payment_status' => $payment ? $payment->payment_status : 'N/A',
            'payment_method' => $payment ? $payment->payment_method : 'N/A',
            'reserved_spots' => $booking->reservedSpots->map(function ($spot) {
                return [
                    'day' => $spot->day,
                    'start_time' => Carbon::parse($spot->start_time)->toDateTimeString(),
                    'end_time' => Carbon::parse($spot->end_time)->toDateTimeString(),
                    'expiry_day' => Carbon::parse($spot->expiry_day)->toDateTimeString(),
                ];
            })->toArray(),
            'space' => [
                'space_id' => $booking->spot->space->id,
                'tenant_id' => $booking->spot->space->tenant_id,
                'space_fee' => $booking->spot->space->space_fee,
                'booking_type' => $booking->spot->space->category->booking_type,
            ],
        ];

        return response()->json([
            'message' => 'Booking retrieved successfully',
            'data' => $response
        ], 200);

    } catch (\Exception $e) {
        return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
    }
}
private function isExpired($book_spot_id)
{
    return BookSpot::where('id', $book_spot_id)
        ->where('expiry_day', '<', now())
        ->exists();
}
private function confirmSpot($spotId)
{
    $spot = Spot::with('tenant')->find($spotId);

    return $spot ? $spot->tenant : null;
}

 
public function getFreeSpotsCateg(Request $request, $tenant_slug, $location_id = null)
{

    $spotsByCategory = []; // Initialize result array

    // Validate category_id if present
    $validator = Validator::make($request->all(), [
        'category_id' => 'required|numeric|exists:categories,id',
    ]);
    
    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    $category_id = $request->category_id;

    // Fetch tenant by slug
    $tenant = Tenant::where('slug', $tenant_slug)->first();
    if (!$tenant) {
        return response()->json(['error' => 'Workspace doesn\'t exist'], 404);
    }

    // Build base query
    $query = Spot::where('spots.tenant_id', $tenant->id)
        ->where('spots.book_status', 'no')
        ->select('spots.id', 'spots.space_id', 'spots.location_id', 'spots.floor_id')
        ->with([
            'location:id,name',
            'floor:id,name',
            'space:id,space_name,space_fee,space_category_id',
             'space.category:id,category,booking_type',
            'space.category.images:id,image_path,category_id',
        ])
        ->join('spaces', 'spots.space_id', '=', 'spaces.id')
        ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
        ->where('spaces.space_category_id', $category_id)
        ->orderBy('categories.category');

    // Add location filter if provided
    if ($location_id) {
        $query->where('spots.location_id', $location_id);
    }

     $query->chunk(1000, function ($freeSpots) use (&$spotsByCategory) {
            foreach ($freeSpots as $spot) {
                $category = $spot->space->category ?? null;
                $categoryName = $category?->category ?? 'Uncategorized';

                if (!isset($spotsByCategory[$categoryName])) {
                    $spotsByCategory[$categoryName] = [
                        'category_id' => $category->id ?? null,
                        'category_name' => $categoryName,
                        'booking_type' => $category->booking_type ?? 'Unknown',
                        'images' => $category?->images->pluck('image_path')->toArray() ?? [],
                        'spots' => [],
                    ];
                }

                $spotsByCategory[$categoryName]['spots'][] = [
                    'spot_id' => $spot->id,
                    'space_name' => $spot->space->space_name,
                    'space_fee' => $spot->space->space_fee,
                    'location_id' => $spot->location_id,
                    'location_name' => $spot->location->name ?? 'Unknown',
                    'floor_name' => $spot->floor->name ?? 'Unknown',
                    'floor_id' => $spot->floor_id,
                ];
            }
        });

    return response()->json(['data' => array_values($spotsByCategory)], 200);

    return response()->json(['data' => $spotsByCategory], 200);
}


}
