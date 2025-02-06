<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\Models\Tenant;
use App\Models\Booking;

class BookingController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        //validate request data
        $validator = Validator::make($request->all(), [
           'booking_date' => 'required|date|after_or_equal:today',
           'booking_start_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
           'booking_end_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
           'attendee' => 'required|numeric|gte:1',
           'product' => 'required|numeric|exists:products,id|gte:1',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        $tenant = Tenant::where('slug', $tenant_slug)->first();
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        

        $booking = $this->bookingPreparation($validatedData, $user->id, $tenant->id);

        //return response if create fails
        if(!$booking){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'you successfully booked this space', 'booking'=>$booking], 201);       
    }

    public function adminCreate(Request $request, $tenant_slug){
        $user = $request->user();

        //validate request data
        $validator = Validator::make($request->all(), [
           'booking_date' => 'required|date|after_or_equal:today',
           'booking_start_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
           'booking_end_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
           'attendee' => 'required|numeric|gte:1',
           'product' => 'required|numeric|exists:products,id|gte:1',
           'booked_by' => 'required|numeric|exists:users,id|gte:1', 
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        $tenant = Tenant::where('slug', $tenant_slug)->first();
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        

        $booking = $this->bookingPreparation($validatedData, $validatedData['booked_by'], $tenant->id);

        //return response if create fails
        if(!$booking){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'you successfully booked this space for user', 'booking'=>$booking], 201);       
    }


    public function index(Request $request, $tenant_slug){
        $user = $request->user();

        if($user->user_type_id !== 1){
            return response()->json(['message' => 'You are not authorized'], 401);
        }

        $tenant = Tenant::where('slug', $tenant_slug)->first();
         //fetch all bookings
        $bookings = Booking::where('tenant_id', $tenant->id)->get();
 
        return response()->json(['data'=>$bookings], 201);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

         //validate request data
         $validator = Validator::make($request->all(), [
            'booking_date' => 'required|date|after_or_equal:today',
            'booking_start_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
            'booking_end_time' => 'required|regex:/^(2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9]$/',
            'attendee' => 'required|numeric|gte:1',
            'product' => 'required|numeric|exists:products,id|gte:1',
         ]);

        //send response if validation fails
        if($validator->fails()){
            return response()->json(['errors'=>$validator->errors()], 422);
        }

        //using the provided id, find the booking to be updated
        $booking = Booking::findOrFail($id);

        //retrieve validatedData from the validator instance
        $validatedData = $validator->validated();

        //sanitize and save validated request data
        $booking->booking_date = htmlspecialchars($validatedData['booking_date'], ENT_QUOTES, 'UTF-8');
        $booking->booking_start_time = htmlspecialchars($validatedData['booking_start_time'], ENT_QUOTES, 'UTF-8');
        $booking->booking_end_time = htmlspecialchars($validatedData['booking_end_time'], ENT_QUOTES, 'UTF-8');
        $booking->attendee = htmlspecialchars($validatedData['attendee'], ENT_QUOTES, 'UTF-8');
        $booking->product_id = htmlspecialchars($validatedData['product'], ENT_QUOTES, 'UTF-8');

        $response = $booking->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 422);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'Booking updated successfully', 'data'=>$booking], 201);
    }

    public function destroy(Request $request){

        $user = $request->user();

         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the booking to be deleted using the Id
        $booking = Booking::findOrFail($request->id);

        if($user->user_type_id !== $booking->booked_by){
            return response()->json(['message' => 'You are not authorized'], 401);
        }

        //delete the booking
        $response = $booking->delete();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Booking deleted successfully'], 200);
    }

    private function bookingPreparation($validatedData, $booker, $tenant){

        $booking = Booking::create([
            'booking_date' => htmlspecialchars($validatedData['booking_date'], ENT_QUOTES, 'UTF-8'),
            'booking_start_time' => htmlspecialchars($validatedData['booking_start_time'], ENT_QUOTES, 'UTF-8'),
            'booking_end_time' => $validatedData['booking_end_time'],
            'attendee' => $validatedData['attendee'],
            'product_id' => $validatedData['product'],
            'tenant_id' => $tenant,
            'booked_by' => $booker,
        ]);

        return $booking;
    }
}
