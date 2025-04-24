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
    return BookSpot::where('spot_id', $spotId)
        ->where('expiry_day', '>=', now())
        ->where(function ($query) use ($chosenDays) {
            foreach ($chosenDays as $day) {
                $query->orWhere(function ($q) use ($day) {
                    $q->whereJsonContains('chosen_days', ['day' => $day['day']])
                        ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(chosen_days, "$[*].start_time")) < ?', [$day['end_time']])
                        ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(chosen_days, "$[*].end_time")) > ?', [$day['start_time']]);
                });
            }
        })->exists();
}

private function handleConflictResponse($spotId, $chosenDays)
{
    $conflicts = BookSpot::where('spot_id', $spotId)
        ->where('expiry_day', '>=', now())
        ->get()
        ->flatMap(function ($booking) use ($chosenDays) {
            return collect(json_decode($booking->chosen_days, true))->filter(function ($bookedDay) use ($chosenDays) {
                return $chosenDays->contains(function ($cd) use ($bookedDay) {
                    return $cd['day'] === $bookedDay['day'] &&
                        $cd['start_time'] < $bookedDay['end_time'] &&
                        $cd['end_time'] > $bookedDay['start_time'];
                });
            })->map(fn($day) => "Day: {$day['day']}, reserved from {$day['start_time']} to {$day['end_time']}");
        });

    return response()->json([
        'message' => 'This spot is already reserved during the selected time',
        'conflicts' => $conflicts->implode('; ')
    ], 422);
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


    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_time'    => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'end_time'      => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'booked_for_user' => 'required|numeric|exists:users,id',
            'spot_id'       => 'required|numeric|exists:spots,id',
            'floor_id'      => 'required|numeric|exists:spaces,floor_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $tenant = Auth::user();
        
        $booking = BookSpot::where('id', $validated['spot_id'])
            ->where('booked_by_user', $tenant->id)
            ->firstOrFail();
        
        $space = Space::where([
            'tenant_id'   => $tenant->tenant_id,
            'floor_id'    => $validated['floor_id']
        ])->firstOrFail();

        $existingBooking = BookSpot::where('spot_id', $validated['spot_id'])
            ->where('id', '!=', $validated['spot_id']) // Exclude the current booking from the check
            ->where('end_time', '>=', $validated['start_time'])
            ->exists();

        if ($existingBooking) {
            return response()->json([
                'error'   => 'booked',
                'message' => "Spot already booked until " . Carbon::parse($existingBooking->end_time)
                    ->addMinute()
                    ->toDateTimeString()
            ], 422);
        }

        DB::transaction(function () use ($validated, $booking, $space) {
            $booking->update([
                'fee'           => $space->space_fee,
                'start_time'    => $validated['start_time'],
                'end_time'      => $validated['end_time'],
                'user_id'       => $validated['booked_for_user'],
                'spot_id'       => $validated['spot_id']
            ]);

            Spot::where('id', $booking->spot_id)->update(['book_status' => 'yes']);
        });

        return response()->json(['message' => 'Booking successfully updated'], 200);
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

        DB::transaction(function () use ($booking) {
            $booking->delete();
            Spot::where('id', $booking->spot_id)->update(['book_status' => 'no']);
        });

        return response()->json(['message' => 'Booking successfully canceled'], 200);
    }

    // get bookings using time range
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
    
        $query = BookSpot::with([
            'spot' => function ($query) {
                $query->select('id', 'book_status', 'space_id', 'location_id', 'floor_id', 'tenant_id');
            },
            'spot.space' => function ($query) {
                $query->select('id', 'space_name',  'space_fee', 'space_category_id', 'tenant_id');
            },
            'spot.space.category' => function ($query) {
                $query->select('id', 'category',  'tenant_id');
            },
            'bookedRef' => function ($query) {
                $query->select('id', 'booked_ref');  // Select the correct fields from the booked_refs table
            }
        ])
        ->join('spots', 'book_spots.spot_id', '=', 'spots.id')
        ->join('spaces', 'spots.space_id', '=', 'spaces.id')
        ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
        ->where('spaces.tenant_id', $tenantId)
        ->select(
            'book_spots.id', 
            'book_spots.start_time', 
            'book_spots.end_time', 
            'book_spots.fee', 
            'book_spots.user_id', 
            'book_spots.spot_id', 
            'book_spots.created_at',
            'book_spots.booked_ref_id'  // Add this to your select statement
        );
        
    
        switch ($validated['booking_type']) {
            case 'valid':
                $query->where('book_spots.end_time', '>=', Carbon::now());
                break;
    
            case 'expired':
                $query->where('book_spots.end_time', '<', Carbon::now());
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
                break;
        }
    
        $bookings = $query->orderByDesc('book_spots.id')->paginate(15);
    
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
   
    public function getUnbookedSpots(Request $request)
    {
        $user = Auth::user();
        $spotsByCategory = [];

        // Map day names to Carbon dayOfWeek (0=Sunday, 1=Monday, ..., 6=Saturday)
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
                'location' => function ($query) {
                    $query->select('id', 'name');
                },
                'space' => function ($query) {
                    $query->select('id', 'space_name', 'space_fee', 'space_category_id')
                          ->with(['category' => function ($query) {
                              $query->select('id', 'category');
                          }]);
                },
                'floor' => function ($query) {
                    $query->select('id', 'name'); // Assuming floor has a name field
                },
                'bookedspots' => function ($query) {
                    $query->select('id', 'spot_id', 'chosen_days', 'expiry_day')
                          ->where('expiry_day', '>=', Carbon::now());
                }
            ])
            ->join('spaces', 'spots.space_id', '=', 'spaces.id')
            ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
            ->orderBy('categories.category')
            ->chunk(1000, function ($spots) use (&$spotsByCategory, $dayMap) {
                foreach ($spots as $spot) {
                    $isBooked = false;
                    $bookedTimes = [];

                   
                    if ($spot->bookedspots->isNotEmpty()) {
                        
                        foreach ($spot->bookedspots as $bookedSpot) {
                            $chosenDays = json_decode($bookedSpot->chosen_days, true);
                            $endDate = Carbon::parse($bookedSpot->expiry_day);
                            foreach ($chosenDays as $chosenDay) {
                    
                                $dayName = strtolower($chosenDay['day']);
                                if (!isset($dayMap[$dayName])) {
                                    continue; // Skip invalid day names
                                }
                                $dayOfWeek = $dayMap[$dayName];
                                // Extract time portion from start_time and end_time
                                $startTime = Carbon::parse($chosenDay['start_time'])->format('H:i:s');
                                $endTime = Carbon::parse($chosenDay['end_time'])->format('H:i:s');

                                // Generate dates for the day of the week until expiry_day
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
                            'booked_times' => $bookedTimes, // Include for reference, empty for unbooked spots
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

}
