<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant,Location, User, Space, BookedRef, SpacePaymentModel,TimeSetUpModel,ReservedSpots};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
class BookSpotController extends Controller
{
    
    // Update an existing booking

    public function create(Request $request, $slug)
{
   try {
        $validated = $this->validateBookingRequest($request);
        $loggedUser = Auth::user();

        $tenant = $this->getTenantFromSpot($validated['spot_id']);
        if (!$tenant) {
            return response()->json(['message' => 'Spot not found'], 404);
        }

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
            return $this->handleConflictResponse($validated['spot_id'], $chosenDays);
        }

        $totalDuration = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
    

        if ($this->isInvalidRecurrentBooking($validated, $tenant)) {
            return response()->json(['message' => 'This space is only available for monthly booking'], 422);
        }

        $amount = $this->calculateBookingAmount($validated, $tenant, $totalDuration);
        // At this point, you can proceed with saving the booking...

        $booked = new BookedRef();
                $reference = $booked->generateRef($slug);
        
                    // Create BookedRef with payment reference
                    $bookedRef = BookedRef::create([
                        'user_id' => $validated['user_id'],
                        'booked_by_user' => $loggedUser->id,
                       'spot_id' => $validated['spot_id'],
                        'fee' => $amount,
                        'payment_ref' =>'N/A',
                        'booked_ref'=>$reference
                    ]);
        
                    // Create BookSpot record
                    $bookSpot = BookSpot::create([
                        'spot_id' => $validated['spot_id'],
                        'user_id' => $validated['user_id'],
                        'booked_by_user' => $loggedUser->id,
                        'booked_ref_id' => $bookedRef->id,
                        'type' => $validated['type'],
                        'chosen_days' => json_encode($chosenDays->toArray()),
                        'expiry_day' => $expiryDay,
                        'start_time' => $chosenDays->first()['start_time'],
                    ]);
                
        
                    $reservedSpotsData = $chosenDays->map(function ($day) use ($validated, $expiryDay, $bookSpot) {
                        return [
                            'user_id' => $validated['user_id'],
                            'spot_id' => $validated['spot_id'],
                            'day' => $day['day'],
                            'start_time' => $day['start_time'],
                            'end_time' => $day['end_time'],
                            'expiry_day' => $expiryDay,
                            'created_at' => now(),
                            'updated_at' => now(),
                            'booked_spot_id' => $bookSpot->id,
                        ];
                    })->toArray();
                    ReservedSpots::insert($reservedSpotsData);
                    SpacePaymentModel::create([
                        'user_id' => $validated['user_id'],
                        'spot_id' => $validated['spot_id'],
                        'tenant_id' => $tenant->tenant_id,
                        'amount' => $amount,
                        'payment_status' => 'pending',
                        'payment_ref' => $reference,
                        'payment_method' => 'postpaid',
                    ]);
                    return response()->json([
                        'message' => 'Booking successfully created',
                    ], 201);

    } catch (\Exception $e) {
        return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
    }
}


private function validateBookingRequest(Request $request)
{
    return Validator::make($request->all(), [
        'spot_id' => 'required|numeric|exists:spots,id',
        'user_id' => 'required|numeric|exists:users,id',
        'type' => 'required|in:one-off,recurrent',
        'number_weeks' => 'nullable|numeric|min:1|max:3',
        'number_months' => 'nullable|numeric|min:0|max:12',
        'chosen_days' => 'required_if:type,recurrent|array',
        'book_spot_id'=>'nullable|numeric|min:0',
        'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
        'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
        'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
    ])->validate();
}

private function getTenantFromSpot($spotId)
{
    return Cache::remember("tenant_spot_{$spotId}", now()->addHour(), function () use ($spotId) {
        return Spot::with(['space.category'])->find($spotId);
    });
}
private function normalizeChosenDays(array $days)
{
    return collect($days)->map(function ($day) {
        return [
            'day' => strtolower($day['day']),
            'start_time' => Carbon::parse($day['start_time'])->timezone('Africa/Lagos'),
            'end_time' => Carbon::parse($day['end_time'])->timezone('Africa/Lagos'),
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
        $open = Carbon::parse($availableDays[$day['day']]->open_time)->format('H:i:s');
        $close = Carbon::parse($availableDays[$day['day']]->close_time)->format('H:i:s');
        $day['start_time'] = Carbon::parse($day['start_time'])->format('H:i:s');
        $day['end_time'] = Carbon::parse($day['end_time'])->format('H:i:s');
        if ($day['start_time']<($open) || $day['end_time']>($close)) {
            return false;
        }
    }
    return true;
}

private function hasConflicts($spotId, $chosenDays)
{
   // dd(Carbon::parse(now()));

   // return 
   $bookings = BookSpot::where('spot_id', $spotId)
   ->where('expiry_day', '>=', Carbon::now())
   ->get();

$conflict = false;

foreach ($bookings as $booking) {
   $chosen = json_decode($booking->chosen_days, true);
   foreach ($chosen as $slot) {
       foreach ($chosenDays as $inputDay) {
           if (
               strtolower($slot['day']) === strtolower($inputDay['day']) &&
               $slot['start_time'] < $inputDay['end_time'] &&
               $slot['end_time'] > $inputDay['start_time']
           ) {
               $conflict = true;
               break 3;
           }
       }
   }
}

return $conflict;

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
                $bookedStart = Carbon::parse($bookedSlot['start_time'])->timezone('Africa/Lagos');
                $bookedEnd = Carbon::parse($bookedSlot['end_time'])->timezone('Africa/Lagos');

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
    return $chosenDays->sum(function ($day) use ($availableDays) {
        $start = $day['start_time'];
        $end = $day['end_time'];
        return $start->diffInHours($end);
    });
}

private function isInvalidRecurrentBooking($validated, $tenant)
{
    return $validated['type'] === 'recurrent' &&
           ($validated['number_weeks'] > 1 && $validated['number_months'] === 0) &&
           $tenant->booking_type === 'monthly';
}

private function calculateBookingAmount($validated, $tenant, $totalDuration)
{
    $number_weeks =$validated['number_weeks'] ?? 1;
    $number_months = $validated['number_months'] ?? 1;
    $number_days = $validated['number_days'] ?? 1; // in case you're using daily bookings

    $discount = ($tenant->space_discount > 0) ? $tenant->space_discount : null;
    $total = 0;
   
    switch ($tenant->space->category->booking_type) {
        case 'monthly':
            $total =$tenant->space->space_fee  * $number_months;
            if ($discount && $tenant->min_space_discount_time <= $number_months) {
                $total -= ($total * ($discount / 100));
            }
            break;

        case 'weekly':
            $total = $tenant->space->space_fee  * $number_weeks;
            if ($discount && $tenant->min_space_discount_time <= $number_weeks) {
                $total -= ($total * ($discount / 100));
            }
            break;

        case 'hourly':
           // dd('hii');
            $total = $tenant->space->space_fee * $totalDuration * $number_weeks;
            
            if ($discount && $tenant->min_space_discount_time <= $totalDuration) {
                $total -= ($total * ($discount / 100));
            }
            break;

        case 'daily':
            $total =$tenant->space->space_fee  * $number_days;
            if ($discount && $tenant->min_space_discount_time <= $number_days) {
                $total -= ($total * ($discount / 100));
            }
            break;

        default:
            $total = 0;
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

            // Update spot status
            //Spot::where('id', $validated['spot_id'])->update(['book_status' => 'yes']);
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

        return response()->json(['message' => 'Booking successfully canceled'], 200);
    }

    public function getBookings(Request $request)
{
    $validator = Validator::make($request->all(), [
        'booking_type' => 'required|string|in:valid,all,expired,past',
        'start_time'   => 'required_with:end_time|date_format:Y-m-d H:i:s',
        'end_time'     => 'required_with:start_time|date_format:Y-m-d H:i:s|after:start_time',
    ]);

    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    $validated = $validator->validated();
    $tenantId = Auth::user()->tenant_id;

    $query = BookSpot::withTrashed()->with([
        'spot' => function ($query) {
            $query->select('id', 'book_status', 'space_id', 'location_id', 'floor_id', 'tenant_id');
        },
        'spot.space' => function ($query) {
            $query->select('id', 'space_name', 'space_fee', 'space_category_id', 'tenant_id');
        },
        'spot.space.category' => function ($query) {
            $query->select('id', 'category', 'tenant_id');
        },
        'bookedRef' => function ($query) {
            $query->select('id', 'booked_ref');
        }
    ])
    ->join('spots', 'book_spots.spot_id', '=', 'spots.id')
    ->join('spaces', 'spots.space_id', '=', 'spaces.id')
    ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
    ->where('spaces.tenant_id', $tenantId)
    ->select(
        'book_spots.id as book_spot_id',
        'book_spots.start_time',
        'book_spots.expiry_day',
        'book_spots.fee',
        'book_spots.user_id',
        'book_spots.spot_id',
        'book_spots.created_at',
        'book_spots.booked_ref_id',
        'book_spots.chosen_days',
        'book_spots.deleted_at'
    );

    switch ($validated['booking_type']) {
        case 'valid':
            $query->where('book_spots.expiry_day', '>=', Carbon::now());
            break;

        case 'expired':
            $query->where('book_spots.expiry_day', '<', Carbon::now());
            break;

        case 'past':
            if (!$request->has('start_time') || !$request->has('end_time')) {
                return response()->json([
                    'error' => 'Both start_time and end_time are required for past bookings'
                ], 422);
            }
            $query->whereBetween('book_spots.start_time', [
                $validated['start_time'],
                $validated['end_time']
            ]);
            break;

        case 'all':
            // no additional filter
            break;
    }

    $bookings = $query->orderByDesc('book_spots.id')->paginate(15);

    // Add custom book_status to each booking
    $bookings->getCollection()->transform(function ($booking) {
        $now = Carbon::now();

        if ($booking->deleted_at !== null) {
            $booking->book_status = 'canceled';
        } elseif ($now->gt(Carbon::parse($booking->expiry_day))) {
            $booking->book_status = 'completed';
        } elseif (
            $now->between(Carbon::parse($booking->start_time), Carbon::parse($booking->expiry_day))
        ) {
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

 
    public function getFreeSpots(Request $request, $tenant_slug)
    {
        $location_id = null;
    
        if ($request->has('location_id') && $request->location_id != null) {
            $validator = Validator::make($request->all(), [
                'location_id' => 'required|numeric|exists:locations,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }
    
            $location_id = $request->location_id;
        }
    
        $tenant = Tenant::where('slug', $tenant_slug)->first();
    
        if (!$tenant) {
            return response()->json(['error' => 'workspace doesn\'t exist'], 404);
        }
    
        $spotsByCategory = [];
    
        $query = Spot::where('spots.tenant_id', $tenant->id)
            ->where('spots.book_status', 'no');
    
        if ($location_id) {
            $query->where('spots.location_id', $location_id);
        }
    
        $query->select('spots.id', 'spots.space_id', 'spots.location_id', 'spots.floor_id')
            ->with([
                'location:id,name',
                'space:id,space_name,space_fee,space_category_id',
                'space.category:id,category'
            ])
            ->join('spaces', 'spots.space_id', '=', 'spaces.id')
            ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
            ->orderBy('categories.category')
            ->chunk(1000, function ($freeSpots) use (&$spotsByCategory) {
                foreach ($freeSpots as $spot) {
                    $categoryName = $spot->space->category->category ?? 'Uncategorized';
                    if (!isset($spotsByCategory[$categoryName])) {
                        $spotsByCategory[$categoryName] = [];
                    }
                    $spotsByCategory[$categoryName][] = [
                        'spot_id' => $spot->id,
                        'space_name' => $spot->space->space_name,
                        'space_fee' => $spot->space->space_fee,
                        'location_id' => $spot->location_id,
                        'floor_id' => $spot->floor_id,
                    ];
                }
            });
    
        return response()->json(['data' => $spotsByCategory], 200);
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
            'space.category:id,category',
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
                    'spot_id' => $spot->id,
                    'space_name' => $spot->space->space_name,
                    'space_fee' => $spot->space->space_fee,
                    'location_id' => $spot->location_id,
                    'location_name' => $spot->location->name ?? null,
                    'floor_id' => $spot->floor_id,
                    'floor_name' => $spot->floor->name ?? null,
                    'booked_times' => $bookedTimes,
                    'book_spot_id' => $spot->bookedSpots->first()->id ?? null,
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
        ->where('expiry_day', '<', now('Africa/Lagos'))
        ->exists();
}

}
