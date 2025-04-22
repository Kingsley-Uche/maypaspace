<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant, User, Space, BookedRef, SpacePaymentModel,TimeSetUpModel};
use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentController extends Controller
{
    public function initiatePay(Request $request, $slug)
{
    try {
        // Validation rules for both one‑off and recurrent bookings
        $validator = Validator::make($request->all(), [
            'spot_id'         => 'required|numeric|exists:spots,id',
            'company_name'    => 'required|string|max:255',
            'first_name'      => 'required|string|max:255',
            'last_name'       => 'required|string|max:255',
            'email'           => 'required|email|max:255',
            // If type is recurrent, number_days must be provided (between 1 and 52 weeks)
            'number_days'     => 'required_if:type,recurrent|numeric|min:1|max:365',
            'phone'           => [
                'required',
                'unique:users,phone',
                'regex:/^([0-9\s\-\+\(\)]*)$/',
                'max:20'
            ],
            'type'            => 'required|in:one-off,recurrent',
            'recurrence'      => 'required_if:type,recurrent|in:daily,weekly,monthly,yearly',
            // For recurrent bookings, chosen_days is an array of objects
            'chosen_days'     => 'required_if:type,recurrent|array',
            'chosen_days.*.day'         => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'chosen_days.*.start_time'  => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'chosen_days.*.end_time'    => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
        ], [
            'phone.required'  => 'The phone number is required.',
            'phone.unique'    => 'This phone number is already registered. Please login.',
            'phone.regex'     => 'The phone number format is invalid.',
            'phone.max'       => 'The phone number may not be greater than 20 characters.',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $validatedData = $validator->validated();
        
        // Sanitize phone number
        $validatedData['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validatedData['phone']);
        
        // Fetch tenant availability (the space’s open and close times per day) 
        $tenant_available = TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
            ->where('tenants.slug', $slug)
            ->select('tenants.id as tenant_id', 'time_set_ups.open_time', 'time_set_ups.day', 'time_set_ups.close_time')
            ->get();
        
        if ($tenant_available->isEmpty()) {
            return response()->json(['message' => 'Workspace not found'], 404);
        }
        
        // Load spot details (join spaces & categories to get pricing)
        $tenant = \App\Models\Spot::where('spots.id', $validatedData['spot_id'])
            ->join('spaces', 'spaces.id', '=', 'spots.space_id')
            ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
            ->select(
                'spots.id',
                'spots.tenant_id',
                'spaces.space_name',
                'spaces.space_price_hourly',
                'spaces.space_price_daily',
                'spaces.space_price_weekly',
                'spaces.space_price_monthly',
                'spaces.space_price_semi_annually',
                'spaces.space_price_annually',
                'categories.category'
            )
            ->first();
        
        if (!$tenant) {
            return response()->json(['message' => 'Spot not found'], 404);
        }
        
        // Validate chosen days (if recurrent) against space availability
        if ($validatedData['type'] === 'recurrent') {
            $availableDays = $tenant_available->keyBy(function ($item) {
                return strtolower($item->day);
            });
            
            foreach ($validatedData['chosen_days'] as $day) {
                $dayKey = strtolower($day['day']);
                if (!$availableDays->has($dayKey)) {
                    return response()->json([
                        'message' => "This space is not available on {$day['day']}"
                    ], 422);
                }
                
                $tenantTime = $availableDays[$dayKey];
                $openTime  = \Carbon\Carbon::parse($tenantTime->open_time);
                $closeTime = \Carbon\Carbon::parse($tenantTime->close_time);
                $startTime = \Carbon\Carbon::parse($day['start_time']);
                $endTime   = \Carbon\Carbon::parse($day['end_time']);
                
                // Validate time boundaries using full time comparison
                if ($startTime->format('H:i') < $openTime->format('H:i') ||
                    $endTime->format('H:i') > $closeTime->format('H:i')) {
                    return response()->json([
                        'message' => "This workspace is not available during the selected time on {$day['day']}"
                    ], 422);
                }
            }
            // Store chosen days as JSON for further processing if needed
            $validatedData['chosen_days'] = json_encode($validatedData['chosen_days']);
        } else {
            // For one-off bookings, we assume a single chosen day entry is provided
            // (e.g., in chosen_days.0) – you may alternatively add top-level start_time/end_time fields.
            $validatedData['chosen_days'] = null;
            $validatedData['recurrence'] = null;
        }
        
        // Check if a user with the provided email already exists
        if (\App\Models\User::where('email', $validatedData['email'])->exists()) {
            return response()->json(['message' => 'Email already registered. Please log in'], 422);
        }
        
        // Check for booking conflicts
        if ($validatedData['type'] === 'one-off') {
            // Assuming one-off bookings have exactly one chosen day entry.
            $startTime = \Carbon\Carbon::parse(data_get($request->all(), 'chosen_days.0.start_time'));
            $endTime   = \Carbon\Carbon::parse(data_get($request->all(), 'chosen_days.0.end_time'));
            
            $existingBooking = \App\Models\BookSpot::where('spot_id', $validatedData['spot_id'])
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->whereBetween('start_time', [$startTime, $endTime])
                          ->orWhereBetween('end_time', [$startTime, $endTime])
                          ->orWhere(function ($q) use ($startTime, $endTime) {
                              $q->where('start_time', '<=', $startTime)
                                ->where('end_time', '>=', $endTime);
                          });
                })
                ->first();
            
            if ($existingBooking) {
                return response()->json([
                    'error' => 'booked',
                    'message' => "Spot already booked until " . 
                                 \Carbon\Carbon::parse($existingBooking->end_time)->toDateTimeString()
                ], 422);
            }
        }
        
        // Create visitor user
        $validatedData['user_type_id'] = 3;
        $userController = new UserContrl();
        $user = $userController->create_visitor_user($validatedData, (object)[
            'id'         => $tenant->tenant_id,
            'spot_id'    => $tenant->id,
            'slug'       => $slug,
            // We'll calculate the amount instead of using a generic fee field below.
        ]);
        
        // Calculate booking amount based on booking type and pricing rules:
        if ($validatedData['type'] === 'one-off') {
            // For one-off booking, use the provided start and end time in chosen_days[0]
            $startTime = \Carbon\Carbon::parse(data_get($request->all(), 'chosen_days.0.start_time'));
            $endTime   = \Carbon\Carbon::parse(data_get($request->all(), 'chosen_days.0.end_time'));
            // Duration in minutes converted to hours (rounded up)
            $durationInMinutes = $endTime->diffInMinutes($startTime);
            $durationInHours   = $durationInMinutes / 60;
            if($durationInHours < 1) {
                $durationInHours = 1; // Minimum chargeable hour
            }
            if($durationInHours > 4 && !empty($tenant->space_price_daily)) {
                $amount = ceil($durationInHours * $tenant->space_price_daily);
            }else{
                $amount = ceil($durationInHours * $tenant->space_price_hourly);
            }
           
        } else {
            // For recurrent bookings, use the recurrence type and number_days to calculate total amount.
            switch ($validatedData['recurrence']) {
                case 'daily':
                    $rate = $tenant->space_price_daily;
                    break;
                case 'weekly':
                    $rate = $tenant->space_price_weekly;
                    break;
                case 'monthly':
                    $rate = $tenant->space_price_monthly;
                    break;
                case 'yearly':
                    $rate = $tenant->space_price_annually;
                    break;
                default:
                    $rate = $tenant->space_price_hourly;
            }
            $amount = $validatedData['number_days'] * $rate;
        }
        
        // Initialize payment using the computed $amount
        $paymentData = $this->initializePaystackPayment($validatedData['email'], $amount, $slug);
        
        if (!$paymentData || !isset($paymentData['status'])) {
            throw new \Exception('Payment initialization failed');
        }
        
        // Prepare payment data
        $paymentData['data']['user_id'] = $user->id;
        $paymentData['data']['spot_id'] = $tenant->id;
        $paymentData['data']['tenant_id'] = $tenant->tenant_id;
        $paymentData['data']['amount'] = $amount;
        
        if ($paymentData['status'] !== true) {
            $paymentData['info'] = 'Payment initiation failed. Your login password has been sent to your email';
            return response()->json($paymentData, 422);
        }
        
        $paymentData['data']['stage'] = 'pending';
        $this->registerPayment($paymentData);
        $paymentData['info'] = 'Payment initiated successfully. Your login password has been sent to your email';
        return response()->json($paymentData, 201);
        
    } catch (\Exception $e) {
        return response()->json([
            'error'   => 'server_error',
            'message' => 'An error occurred: ' . $e->getMessage()
        ], 500);
    }
}

    // public function initiatePay(Request $request, $slug)
    // {
       
    //         try {
    //             // Validation rules
    //             $validator = Validator::make($request->all(), [
    //                 'spot_id' => 'required|numeric|exists:spots,id',
    //                 'company_name' => 'required|string|max:255',
    //                 'first_name' => 'required|string|max:255',
    //                 'last_name' => 'required|string|max:255',
    //                 'email' => 'required|email|max:255',
    //                 'number_days' => 'required_if:type,recurrent|numeric|min:1|max:52',
    //                 'phone' => [
    //                     'required',
    //                     'unique:users,phone',
    //                     'regex:/^([0-9\s\-\+\(\)]*)$/',
    //                     'max:20'
    //                 ],
    //                 'type' => 'required|in:one-off,recurrent',
    //                 'recurrence' => 'required_if:type,recurrent|in:daily,weekly,monthly,yearly',
    //                 'chosen_days' => 'required_if:type,recurrent|array',
    //                 'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
    //                 'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
    //                 'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
    //             ], [
    //                 'phone.required' => 'The phone number is required.',
    //                 'phone.unique' => 'This phone number is already registered. Please login.',
    //                 'phone.regex' => 'The phone number format is invalid.',
    //                 'phone.max' => 'The phone number may not be greater than 20 characters.',
    //             ]);
        
    //             if ($validator->fails()) {
    //                 return response()->json(['errors' => $validator->errors()], 422);
    //             }
        
    //             $validatedData = $validator->validated();
        
    //             // Sanitize phone number
    //             $validatedData['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validatedData['phone']);
        
    //             // Fetch tenant availability with single query
    //             $tenant_available = TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
    //                 ->where('tenants.slug', $slug)
    //                 ->select('tenants.id as tenant_id', 'time_set_ups.open_time', 'time_set_ups.day', 'time_set_ups.close_time')
    //                 ->get();
                    
        
    //             if ($tenant_available->isEmpty()) {
    //                 return response()->json(['message' => 'Workspace not found'], 404);
    //             }
        
    //             // Preload spot details to avoid separate query later
    //             $tenant = Spot::where('spots.id', $validatedData['spot_id'])
    //                 ->join('spaces', 'spaces.id', '=', 'spots.space_id')
    //                 ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
    //                 ->select(
    //                     'spots.id',
    //                     'spots.tenant_id',
    //                     'spaces.space_name',
    //                     'spaces.space_price_daily',
    //                     'spaces.space_price_hourly',
    //                     'spaces.space_price_weekly',
    //                     'spaces.space_price_monthly',
    //                     'spaces.space_price_semi_annually',
    //                     'spaces.space_price_annually',
    //                     'categories.category'
    //                 )
    //                 ->first();
        
    //             if (!$tenant) {
    //                 return response()->json(['message' => 'Spot not found'], 404);
    //             }
        
    //             // Validate chosen days if recurrent booking
    //             if ($validatedData['type'] === 'recurrent') {
                    
    //                 $availableDays = $tenant_available->keyBy('day'); 
    //                 foreach ($validatedData['chosen_days'] as $day) {
    //                     if (!$availableDays->has($day['day'])) {
    //                         return response()->json([
    //                             'message' => "This space is not available on {$day['day']}"
    //                         ], 422);
    //                     }
        
    //                     $tenantTime = $availableDays[$day['day']];
    //                     $openTime = Carbon::parse($tenantTime->open_time);
    //                     $closeTime = Carbon::parse($tenantTime->close_time);
    //                     $startTime = Carbon::parse($day['start_time']);
    //                     $endTime = Carbon::parse($day['end_time']);
        
    //                     // Validate time boundaries
    //                     if ($startTime->format('H:i') < $openTime->format('H:i') ||
    //                         $endTime->format('H:i') > $closeTime->format('H:i')) {
    //                         return response()->json([
    //                             'message' => "This workspace is not available during the selected time on {$day['day']}"
    //                         ], 422);
    //                     }
    //                 }
    //                 // Store chosen days as JSON
    //                 $validatedData['chosen_days'] = json_encode($validatedData['chosen_days']);
    //             } else {
    //                 $validatedData['chosen_days'] = null;
    //                 $validatedData['recurrence'] = null;
    //             }
        
    //             // Check for existing user in a single query
    //             if (User::where('email', $validatedData['email'])->exists()) {
    //                 return response()->json(['message' => 'Email already registered. Please log in'], 422);
    //             }
        
    //             // Check for booking conflicts in a single query
    //             if ($validatedData['type'] === 'one-off') {
    //                 $startTime = Carbon::parse($request->input('chosen_days.0.start_time'));
    //                 $endTime = Carbon::parse($request->input('chosen_days.0.end_time'));
        
    //                 $existingBooking = BookSpot::where('spot_id', $validatedData['spot_id'])
    //                     ->where(function ($query) use ($startTime, $endTime) {
    //                         $query->whereBetween('start_time', [$startTime, $endTime])
    //                               ->orWhereBetween('end_time', [$startTime, $endTime])
    //                               ->orWhere(function ($q) use ($startTime, $endTime) {
    //                                   $q->where('start_time', '<=', $startTime)
    //                                     ->where('end_time', '>=', $endTime);
    //                               });
    //                     })
    //                     ->first();
        
    //                 if ($existingBooking) {
    //                     return response()->json([
    //                         'error' => 'booked',
    //                         'message' => "Spot already booked until " .
    //                             Carbon::parse($existingBooking->end_time)->toDateTimeString()
    //                     ], 422);
    //                 }
    //             }
            
        
    //             // Create visitor user
    //             $validatedData['user_type_id'] = 3;
    //             $userController = new UserContrl();
    //             $user = $userController->create_visitor_user($validatedData, (object) [
    //                 'id' => $tenant->tenant_id,
    //                 'spot_id' => $tenant->id,
    //                 'slug' => $slug,
    //                 'space_fee' => $tenant->space_fee
    //             ]);
                
    //         $paymentData = $this->initializePaystackPayment($validatedData['email'], $tenant->space_fee, $slug);
            
    //         if (!$paymentData || !isset($paymentData['status'])) {
    //             throw new Exception('Payment initialization failed');
    //         }

    //         $paymentData['data']['user_id'] = $user->id;
    //         $paymentData['data']['spot_id'] = $tenant->id;
    //         $paymentData['data']['tenant_id'] = $tenant->tenant_id;
    //         $paymentData['data']['amount'] = $tenant->space_fee;

    //         if ($paymentData['status'] !== true) {
    //             $paymentData['info'] = 'Payment initiation failed. Your login password has been sent to your email';
    //             return response()->json($paymentData, 422);
    //         }

    //         $paymentData['data']['stage'] = 'pending';
    //         $this->registerPayment($paymentData);
    //         $paymentData['info'] = 'Payment initiated successfully. Your login password has been sent to your email';
    //         return response()->json($paymentData, 201);

    //     } catch (Exception $e) {
    //         return response()->json([
    //             'error' => 'server_error',
    //             'message' => 'An error occurred: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    private function initializePaystackPayment($email, $amount, $slug)
    {
        $booked = new BookedRef();
        $reference = $booked->generateRef($slug);

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'email' => $email,
                    'amount' => $amount * 100, // Convert to kobo
                    'reference' => $reference,
                ]),
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . env('PAYMENTBEARER'),
                    "Cache-Control: no-cache",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception('cURL error: ' . curl_error($ch));
            }

            return json_decode($result, true) ?? null;
        } finally {
            curl_close($ch);
        }
    }

    private function registerPayment($data)
    {
        return SpacePaymentModel::create([
            'user_id' => $data['data']['user_id'],
            'spot_id' => $data['data']['spot_id'],
            'tenant_id' => $data['data']['tenant_id'],
            'amount' => $data['data']['amount'],
            'payment_status' => $data['data']['stage'],
            'payment_ref' => $data['data']['reference'],
            'payment_method' => 'prepaid',
        ]);
    }

    public function confirmPayment(Request $request, $slug)
    {
        try {
            $validator = Validator::make($request->all(), [
                'spot_id' => 'required|numeric|exists:spots,id',
                'company_name' => 'required|string|max:255',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|exists:users,email|max:255',
                'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|exists:users,phone|max:20',
                'start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
                'type' => 'required|in:one-off,recurrent',
                'chosen_days' => 'required_if:type,recurrent|array',
                'chosen_days.*' => 'integer|in:1,2,3,4,5,6,7',
                'recurrence' => 'required_if:type,recurrent|in:daily,weekly,monthly,yearly',
                'tenant_id' => 'required|numeric|exists:tenants,id',
                'user_id' => 'required|numeric|exists:users,id',
                'reference' => 'required|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validatedData = $validator->validated();
            $reference = strip_tags($validatedData['reference']);

            $bookingRef = BookedRef::where('booked_ref', $reference)->first();
            if (!$bookingRef) {
                return response()->json(['error' => 'Booking reference not found'], 404);
            }

            $ch = curl_init();
            try {
                curl_setopt_array($ch, [
                    CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer " . config('services.paymentBearer'),
                        "Cache-Control: no-cache",
                    ],
                ]);

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    throw new Exception('cURL error: ' . curl_error($ch));
                }

                $paymentInfo = json_decode($response, true);
                if (!$paymentInfo || $paymentInfo['status'] !== true) {
                    return response()->json(['error' => 'Payment verification failed'], 422);
                }

                return DB::transaction(function () use ($validatedData, $bookingRef, $paymentInfo) {
                    $space = Space::where('id', Spot::findOrFail($validatedData['spot_id'])->space_id)->firstOrFail();
                    
                    $bookSpot = BookSpot::create([
                        'spot_id' => $validatedData['spot_id'],
                        'user_id' => $validatedData['user_id'],
                        'booked_by_user' => $validatedData['user_id'],
                        'start_time' => Carbon::parse($validatedData['start_time']),
                        'end_time' => Carbon::parse($validatedData['end_time']),
                        'type' => $validatedData['type'],
                        'chosen_days' => $validatedData['type'] === 'recurrent' ? $validatedData['chosen_days'] : null,
                        'recurrence' => $validatedData['type'] === 'recurrent' ? $validatedData['recurrence'] : null,
                        'fee' => $space->space_fee,
                        'invoice_ref' => $validatedData['reference'],
                        'booked_ref_id' => $bookingRef->id,
                    ]);

                    SpacePaymentModel::where('payment_ref', $validatedData['reference'])
                        ->update([
                            'amount' => $paymentInfo['data']['amount'] / 100,
                            'payment_status' => 'completed'
                        ]);

                    return response()->json([
                        'message' => 'Payment confirmed and spot booked successfully',
                        'data' => $bookSpot
                    ], 201);
                });

            } finally {
                curl_close($ch);
            }

        } catch (Exception $e) {
            return response()->json([
                'error' => 'server_error',
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
// namespace App\Http\Controllers\Api\V1;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Validator;
// use App\Models\{BookSpot, Spot, Tenant, User, Space, BookedRef, SpacePaymentModel};
// use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
// use Illuminate\Support\Facades\Storage;
// use Carbon\Carbon;
// use Illuminate\Support\Facades\Cache;

// class PaymentController extends Controller
// {
//     public function initiatePay(Request $request, $slug)
//     {
//         // Validate request data
//         $validator = Validator::make($request->all(), [
//             'spot_id'      => 'required|numeric|exists:spots,id',
//             'company_name' => 'required|string',
//             'first_name'   => 'required|string|max:255',
//             'last_name'    => 'required|string|max:255',
//             'email'        => 'required|email',
//             'phone'        => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
//             'start_time'   => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
//             'end_time'     => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
//             'type'         => 'required|in:one-off,recurrent',
//             'chosen_days'  => 'required_if:type,recurrent|array',
//             'chosen_days.*'=> 'integer|in:1,2,3,4,5,6,7',
//             'recurrence'   => 'required_if:type,recurrent|string|in:daily,weekly,monthly,yearly',
//         ]);
    
//         if ($validator->fails()) {
//             return response()->json(['errors' => $validator->errors()], 422);
//         }
    
//         // Retrieve validated data
//         $validatedData = $validator->validated();
    
//         // Sanitize phone number
//         $validatedData['phone'] = htmlspecialchars($validatedData['phone'], ENT_QUOTES, 'UTF-8');
    
//         // Load days frequency and recurrence frequency from cache
//         $days_freq = Cache::remember('days_frequency', 3600, function () {
//             return json_decode(Storage::get('day.json'), true);
//         });

//         $days = $days_freq['days_of_week'];
//         $freq = $days_freq['frequency'];
//         $chosen_freq = $validatedData['recurrence'];
//         $chosen_days = $validatedData['chosen_days'];

//         // Map chosen days to their names
//         $chosen_days = array_map(function ($day) use ($days) {
//             return $days[$day];
//         }, $chosen_days);
//         $chosen_days = json_encode(implode(',', $chosen_days));
    
//         // Check if user with the same email already exists
//         $user_status = User::where('email', $validatedData['email'])->first();
//         if ($user_status) {
//             return response()->json(['message' => 'Email already registered. Please log in'], 422);
//         }
    
//         // Check if spot is already booked during the requested time
//         $existingBooking = BookSpot::where('spot_id', $validatedData['spot_id'])
//             ->where('end_time', '>=', $validatedData['start_time'])
//             ->orderBy('end_time', 'desc')
//             ->first();

//         if ($existingBooking) {
//             return response()->json([
//                 'error'   => 'booked',
//                 'message' => "Spot already booked until " . Carbon::parse($existingBooking->end_time)
//                     ->addMinute()
//                     ->toDateTimeString()
//             ], 422);
//         }

//         // Proceed with creating user and initiating payment
//         $newUser = new UserContrl();
//         $tenant = Spot::where('spots.id', $validatedData['spot_id'])
//             ->join('spaces', 'spaces.id', '=', 'spots.space_id')
//             ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
//             ->select('spots.id', 'spots.tenant_id', 'spaces.space_name','spaces.space_fee', 'categories.category')
//             ->first();

//         // Assign the tenant_id and the slug
//         $tenant->spot_id = $tenant->id;
//         $tenant->id = $tenant->tenant_id;
//         $amount = $tenant->space_fee;
//         $email = $validatedData['email'];
//         $tenant->slug = $slug;
//         $validatedData['user_type_id']= 3;
//         // Create the user and initialize payment
//         $user = $newUser->create_visitor_user($validatedData, $tenant);
//         $data = $this->initializePaystackPayment($email, $amount, $slug);

//         // Attach additional data to the payment response
//         $data['data']['user_id'] = $user->id;
//         $data['data']['spot_id'] = $tenant->spot_id;
//         $data['data']['tenant_id'] = $tenant->id;
//         $data['data']['amount'] = $amount;

//         // Check if payment initialization is successful
//         if ($data['status'] !== true) {
          
//             $data['info'] = 'Payment initiation failed. Your login password has been sent to your email';

//             return response()->json([$data], 422);
//         }
//         $data['data']['stage'] = 'pending';
//         $info =$this->RegisterPayment($data);
        

//         $data['info'] = 'Payment initiated successfully. Your login password has been sent to your email';
//         return response()->json([$data], 201);
//     }

//     private function initializePaystackPayment($email, $amount, $slug)
//     {
//         $booked = new BookedRef();
//         $ref = $booked->generateRef($slug);
//         $url = "https://api.paystack.co/transaction/initialize";
//         $fields = http_build_query([
//             'email' => $email,
//             'amount' => $amount*100,
//             'reference' => $ref,
//         ]);

//         $ch = curl_init();
//         curl_setopt($ch, CURLOPT_URL, $url);
//         curl_setopt($ch, CURLOPT_POST, true);
//         curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
//         curl_setopt($ch, CURLOPT_HTTPHEADER, [
//             "Authorization: Bearer " . env('PAYMENTBEARER'),
//             "Cache-Control: no-cache",
//         ]);
//         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

//         $result = curl_exec($ch);
//         curl_close($ch);

//         return $result ? json_decode($result, true) : null;
//     }

//     private function RegisterPayment($data)
//     {
//         $data =SpacePaymentModel::create([
//             'user_id' => $data['data']['user_id'],
//             'spot_id' => $data['data']['spot_id'],
//             'tenant_id' => $data['data']['tenant_id'],
//             'amount' => $data['data']['amount'],
//             'payment_status' => $data['data']['stage'],
//             'payment_ref' => $data['data']['reference'],
//             'payment_method' => 'prepaid',
//         ]);
//         return $data;
//     }


    


// //  }
// public function confirm_payment(Request $request, $slug)
// {
//     // Validate request input
//     $validator = Validator::make($request->all(), [
//         'spot_id'       => 'required|numeric|exists:spots,id',
//         'company_name'  => 'required|string|max:255',
//         'first_name'    => 'required|string|max:255',
//         'last_name'     => 'required|string|max:255',
//         'email'         => 'required|email|exists:users,email',
//         'phone'         => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'exists:users,phone'],
//         'start_time'    => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
//         'end_time'      => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
//         'type'          => 'required|in:one-off,recurrent',
//         'chosen_days'   => 'required_if:type,recurrent|array',
//         'chosen_days.*' => 'integer|in:1,2,3,4,5,6,7',
//         'recurrence'    => 'required_if:type,recurrent|string|in:daily,weekly,monthly,yearly',
//         'tenant_id'     => 'required|numeric|exists:tenants,id',
//         'user_id'       => 'required|numeric|exists:users,id',
//         'reference'     => 'required|string',
//     ]);

//     if ($validator->fails()) {
//         return response()->json(['errors' => $validator->errors()], 422);
//     }

//     $validatedData = $validator->validated();
//     $booking_ref_id =Bookedref::where('booked_ref', $valiadated['reference'])->first()->id;

//     if(!$booking_ref_id){
//         return response()->json(['error' => 'Booking reference not found.'], 404);
//     }


//     $curl = curl_init();
//     $reference=strip_tags($request->reference);
//           curl_setopt_array($curl, array(
//             CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
//             CURLOPT_RETURNTRANSFER => true,
//             CURLOPT_ENCODING => "",
//             CURLOPT_MAXREDIRS => 10,
//             CURLOPT_TIMEOUT => 30,
//             CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
//             CURLOPT_CUSTOMREQUEST => "GET",
//             CURLOPT_HTTPHEADER =>  array(
//               "Authorization: Bearer " .  config('services.paymentBearer'),
//               "Cache-Control: no-cache",
//             ),
//           ));
    
//           $response = curl_exec($curl);
//           $err = curl_error($curl);
    
//           curl_close($curl);
    
//           if ($err) {
//            // echo "cURL Error #:" . $err;
    
//            return response()->json(['success' => false, 'message' => 'Network error', 'error'=>$err], 422);
    
//           } else {
//             try {
//                 DB::beginTransaction();
        
//                 // Find spot and user
//                 $user = User::findOrFail($validatedData['user_id']);
        
//                 // Calculate fee (this could be dynamic based on your business logic)
//                 $fee = $fee; // Assuming 'price' field exists in Spot
        
//                 // Create booking
//                 $bookSpot = BookSpot::create([
//                     'spot_id'         => $validatedData['spot_id'],
//                     'user_id'         => $validatedData['user_id'],
//                     'booked_by_user'  =>$validatedData['user_id'], // if authenticated
//                     'start_time'      => Carbon::parse($validatedData['start_time']),
//                     'end_time'        => Carbon::parse($validatedData['end_time']),
//                     'type'            => $validatedData['type'],
//                     'chosen_days'     => $validatedData['type'] === 'recurrent' ? json_encode($validatedData['chosen_days']) : null,
//                     'recurrence'      => $validatedData['type'] === 'recurrent' ? $validatedData['recurrence'] : null,
//                     'fee'             => $fee,
//                     'invoice_ref'     => $validatedData['reference'],
//                     'booked_ref_id'   => $booking_ref_id,
//                 ]);
        
//                 DB::commit();
        
//                 return response()->json([
//                     'message' => 'Payment confirmed and spot booked successfully.',
//                     'data'    => $bookSpot
//                 ], 201);
        
//             } catch (\Exception $e) {
//                 DB::rollBack();
//                 return response()->json([
//                     'error' => 'An error occurred while confirming payment.',
//                     'details' => $e->getMessage()
//                 ], 500);
//             }




    

// }

    
//     // Sanitize phone number
 


// }
//     public function confirm_payment(Request $request, $slug)
//     {

//         $validator = Validator::make($request->all(), [
//             'spot_id'      => 'required|numeric|exists:spots,id',
//             'company_name' => 'required|string',
//             'first_name'   => 'required|string|max:255',
//             'last_name'    => 'required|string|max:255',
//             'email'        => 'required|email|exists:users,email',
//             'phone'        => 'required|exists:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
//             'start_time'   => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
//             'end_time'     => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
//             'type'         => 'required|in:one-off,recurrent',
//             'chosen_days'  => 'required_if:type,recurrent|array',
//             'chosen_days.*'=> 'integer|in:1,2,3,4,5,6,7',
//             'recurrence'   => 'required_if:type,recurrent|string|in:daily,weekly,monthly,yearly',
//             'tenant_id'   => 'required|numeric|exists:tenants,id',
//             'user_id'      => 'required|numeric|exists:users,id',
//             'reference'  => 'required|string',
//         ]);
    
//         if ($validator->fails()) {
//             return response()->json(['errors' => $validator->errors()], 422);
//         }
        
    
//         // Retrieve validated data
//         $validatedData = $validator->validated();
    
//         // Sanitize phone number
//         $validatedData['phone'] = htmlspecialchars($validatedData['phone'], ENT_QUOTES, 'UTF-8');
    
//         // Load days frequency and recurrence frequency from cache
//         $days_freq = Cache::remember('days_frequency', 3600, function () {
//             return json_decode(Storage::get('day.json'), true);
//         });

//         $days = $days_freq['days_of_week'];
//         $freq = $days_freq['frequency'];
//         $chosen_freq = $validatedData['recurrence'];
//         $chosen_days = $validatedData['chosen_days'];

//         // Map chosen days to their names
//         $chosen_days = array_map(function ($day) use ($days) {
//             return $days[$day];
//         }, $chosen_days);
//         $chosen_days = json_encode(implode(',', $chosen_days));
      
//       $curl = curl_init();
// $reference=strip_tags($request->reference);
//       curl_setopt_array($curl, array(
//         CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_ENCODING => "",
//         CURLOPT_MAXREDIRS => 10,
//         CURLOPT_TIMEOUT => 30,
//         CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
//         CURLOPT_CUSTOMREQUEST => "GET",
//         CURLOPT_HTTPHEADER =>  array(
//           "Authorization: Bearer " .  config('services.paymentBearer'),
//           "Cache-Control: no-cache",
//         ),
//       ));

//       $response = curl_exec($curl);
//       $err = curl_error($curl);

//       curl_close($curl);

//       if ($err) {
//        // echo "cURL Error #:" . $err;

//        return response()->json(['success' => false, 'message' => 'Network error', 'error'=>$err], 422);

//       } else {
//           $info = json_decode($response);
//           $data = $info->data;
//   $amount = round(floatval($data->amount/100),2);
//   SpacePaymentModel::where('payment_ref', $reference)->where('spot_id', $data['data']['spot_id'])
// ->where('user_id', $data['data']['user_id'])
// ->where('tenant_id', $data['data']['tenant_id'])
// >update([
//     'amount' => $data['data']['amount'],
//     'payment_status' => "completed",]);  
//     $booking_ref_id =Bookedref::where('booked_ref', $valiadated['reference'])->first()->id;
//     $booking = BookSpot::create([
//         'fee'           => $space->space_fee,
//         'start_time'    => $validated['start_time'],
//         'end_time'      => $validated['end_time'],
//         'booked_by_user' =>$validated['user_id'],
//         'user_id'       => $validated['user_id'],
//         'spot_id'       => $validated['spot_id'],
//         'booked_ref_id' => $booking_ref->id,
//         'chosen_days'=>$chosen_days,
//         'recurrence'=>$validated['recurrence'],
//     ]);


//           return response()->json(['success' => true, 'message' => 'transaction confirmed',], 200);
//       }



