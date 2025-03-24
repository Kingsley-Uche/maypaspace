<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\BookSpot;
use App\Models\Spot;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon; // Import Carbon for date manipulation

class BookSpotController extends Controller
{
    // Method to create a booking
    public function Create(Request $request)
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            'start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'booked_for_user' => 'required|numeric|exists:users,id',
            'spot_id' => 'required|numeric|exists:spots,id'
        ]);
        

        // Validate request data
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        // Check if the spot has already been booked for the given time
        $status = BookSpot::where('spot_id', $validatedData['spot_id'])
            ->where('end_time', '>=', Carbon::parse($validatedData['start_time']))
            ->first();

        if ($status) {
            // Spot is already booked, return an error
            return response()->json([
                'error' => 'booked',
                'message' => 'This spot is already booked until ' . Carbon::parse($status->end_time)->addMinute()->toDateTimeString()
            ], 422);
        }

        // Create a new booking
        $booked = BookSpot::create([
            'fee' => strip_tags($validatedData['fee']),
            'start_time' => strip_tags($validatedData['start_time']),
            'end_time' => strip_tags($validatedData['end_time']),
            'booked_by_user' => Auth::user()->id, // The authenticated user making the booking
            'user_id' => strip_tags($validatedData['booked_for_user']),
            'spot_id' => strip_tags($validatedData['spot_id']),
        ]);

        // Update the status in the Spot table to mark it as booked
        Spot::where('id', $booked->spot_id)->update(['book_status' => 'yes']);

        // Return a successful booking response
        return response()->json([
            'message' => 'You successfully booked this space',
            'booking' => $booked
        ], 201);
    }

    // Method to cancel a booking
    public function cancelBooking(Request $request)
    {
        // Validate the booking ID
        $validator = Validator::make($request->all(), [
            'book_spot_id' => 'required|numeric|exists:book_spots,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Find the booking based on the ID
        $booking = BookSpot::find($request->booking_id);

        // Check if the authenticated user is either the one who booked or the one it was booked for
        if (!$booking || ($booking->booked_by_user !== Auth::user()->id && $booking->user_id !== Auth::user()->id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Cancel the booking (delete it)
        $booking->delete();

        // Update the corresponding spot status to 'no' (available)
        Spot::where('id', $booking->spot_id)->update(['book_status' => 'no']);

        // Return a success message
        return response()->json(['message' => 'Booking successfully canceled'], 200);
    }
    public function getBookings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_type' => 'required|string|in:valid,all',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
    
        $validatedData = $validator->validated();
        
        if ($validatedData['booking_type'] === 'valid') {
            $bookings = BookSpot::where('end_time', '>=', Carbon::now())
                ->orderBy('id', 'DESC')
                ->paginate(15);
        } elseif ($validatedData['booking_type'] === 'all') {
            $bookings = BookSpot::orderBy('id', 'DESC')
                ->paginate(15);
        }
    
        return response()->json(['data' => $bookings], 200);
    }
    
}
