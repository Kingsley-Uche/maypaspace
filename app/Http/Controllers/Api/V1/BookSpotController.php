<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant, Location, User, Space,BookedRef};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookSpotController extends Controller
{
    // Create a new booking
    public function create(Request $request)
    {
        // Validate the input data
        $validator = Validator::make($request->all(), [
            'start_time'    => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'end_time'      => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'booked_for_user' => 'required|numeric|exists:users,id',
            'spot_id'       => 'required|numeric|exists:spots,id',
            'location_id'   => 'required|numeric|exists:locations,id',
            'floor_id'      => 'required|numeric|exists:spaces,floor_id',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
    
        $validated = $validator->validated();
        $loggedUser = Auth::user();
    
        // Fetch space details with indexing for better performance
        $space = Space::where([
            'tenant_id'   => $loggedUser->tenant_id,
            'location_id' => $validated['location_id'],
            'floor_id'    => $validated['floor_id']
        ])->firstOrFail();
    
        // Fetch existing booking and check for conflicts with detailed info
        $existingBooking = BookSpot::where('spot_id', $validated['spot_id'])
            ->where('end_time', '>=', $validated['start_time'])
            ->orderBy('end_time', 'desc')
            ->first(); // Retrieve booking instead of using exists()
    
        if ($existingBooking) {
            return response()->json([
                'error'   => 'booked',
                'message' => "Spot already booked until " . Carbon::parse($existingBooking->end_time)
                    ->addMinute()
                    ->toDateTimeString()
            ], 422);
        }
    
        // Perform transaction with exception handling
        try {
            DB::transaction(function () use ($validated, $space, $loggedUser) {
                // Generate booking reference
                $booking_ref = new BookedRef();
                $booking_ref->booked_ref = $booking_ref->generateRef($loggedUser->tenant->slug);
                $booking_ref->booked_by_user = $loggedUser->id;
                $booking_ref->user_id = $validated['booked_for_user'];
                $booking_ref->spot_id = $validated['spot_id'];
                $booking_ref->save();
    
                // Create the booking
                $booking = BookSpot::create([
                    'fee'           => $space->space_fee,
                    'start_time'    => $validated['start_time'],
                    'end_time'      => $validated['end_time'],
                    'booked_by_user' => $loggedUser->id,
                    'user_id'       => $validated['booked_for_user'],
                    'spot_id'       => $validated['spot_id'],
                    'booked_ref_id' => $booking_ref->id,
                ]);
    
                // Update spot availability status
                //Spot::where('id', $booking->spot_id)->update(['book_status' => 'yes']);
            });
        } catch (\Exception $e) {
            Log::error('Booking failed: ' . $e->getMessage()); // Log the error for debugging
            return response()->json(['error' => 'Booking process failed. Please try again later.'], 500);
         }
         
    
        // Return success response
        return response()->json(['message' => 'Space successfully booked'], 201);
    }
    

    // Update an existing booking
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
    
    public function getUnbookedSpots()
{
  
    $user = Auth::user();
    
    $spotsByCategory = []; // Changed variable name to reflect grouping
        Spot::where('spots.tenant_id', $user->tenant_id)
            ->where('spots.book_status', 'no')
            ->select('spots.id', 'spots.space_id', 'spots.location_id', 'spots.floor_id')
            ->with(['location' => function($query) {
                $query->select('id', 'name');
            }])
            ->with(['space' => function($query) {
                $query->select('id', 'space_name', 'space_fee', 'space_category_id')
                      ->with(['category' => function($query) {
                          $query->select('id', 'category');
                      }]);
            }])
            ->join('spaces', 'spots.space_id', '=', 'spaces.id')
            ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
            ->orderBy('categories.category')
            ->chunk(1000, function ($freeSpots) use (&$spotsByCategory) {
                foreach ($freeSpots as $spot) {
                    $categoryName = $spot->space->category->category;
                    // Initialize category array if it doesn't exist
                    if (!isset($spotsByCategory[$categoryName])) {
                        $spotsByCategory[$categoryName] = [];
                    }
                    
                    // Add spot to its category
                    $spotsByCategory[$categoryName][] = [
                        'spot_id' => $spot->id,
                        'space_name' => $spot->space->space_name,
                        'space_fee' => $spot->space->space_fee,
                        'location_id' => $spot->location_id,
                        'location_name' => $spot->location->name,
                        'floor_id' => $spot->floor_id,
                        'floor_name' => $spot->floor->name,
                    ];
                }
            });
        
        if (empty($spotsByCategory)) {
            return response()->json(['message' => 'No free spots available'], 404);
        }
        
        return response()->json(['data' => $spotsByCategory], 200);
}
}
