<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot,InvoiceModel,Tenant, User, Space, BookedRef, SpacePaymentModel, TimeSetUpModel, ReservedSpots};
use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
use App\Http\Controllers\Api\V1\InvoiceController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Models\TaxModel;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingRecieptMail;
class PaymentController extends Controller
{
    //Save tenant Unique paystack Secret key
    public function saveSecretKey(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $ten = $this->checkTenant($tenant_slug);  

        if((int)$user->user_type_id !== 1){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        //validate request data
        $validator = Validator::make($request->all(), [
           'key' => 'required|string|max:255',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();

        $tenant = Tenant::where('id', $ten->id)->firstOrFail();

        $tenant->paystack_secret_key = Crypt::encryptString($request->key);

        $response = $tenant->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 422);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'Tenant paystack key updated successfully', 'data'=>$tenant], 201);
    }
    /**
     * Initiate a payment for a booking
     */
    public function initiatePay(Request $request, $slug)
    {
        DB::beginTransaction();
     try {
            //Validate input
            $validated = $this->validateBookingRequest($request);
           $user_email_phone = User::where('phone', $validated['phone'])->orWhere('email', $validated['email'])->first();

    if ($user_email_phone) {
        return response()->json(['message' => 'Email or phone already taken'], 422);
    }

            // Early validation for one-off vs recurrent
            if ($validated['type'] === 'one-off' && ($validated['number_weeks'] || $validated['number_months'])) {
                return response()->json(['message' => 'One-off booking cannot have weeks or months specified'], 422);
            }
            if ($validated['type'] === 'recurrent' && (!$validated['number_weeks'] && !$validated['number_months'])) {
                return response()->json(['message' => 'Recurrent booking must have weeks or months specified'], 422);
            }

            // Clean phone number
            $validated['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validated['phone']);

            // Fetch tenant and spot details
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

            // Validate days and times
            if (!$this->areAllDaysAvailable($chosenDays, $tenantAvailability)) {
                return response()->json(['message' => 'One or more days are not available for booking'], 422);
            }
            if (!$this->areChosenTimesValid($chosenDays, $tenantAvailability)) {
                return response()->json(['message' => 'Chosen time is outside available hours'], 422);
            }

            // conflit validation
            if ($this->hasConflicts($validated['spot_id'], $chosenDays)) {
                return $this->handleConflictResponse($validated['spot_id'], $chosenDays);
            }

            // Prevent duplicate reservations for the same user/time/spot
            foreach ($chosenDays as $day) {
                $exists = ReservedSpots::where([
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

            // Validate recurrent booking
            if ($this->isInvalidRecurrentBooking($validated, $tenant)) {
                return response()->json(['message' => 'This space is only available for monthly booking'], 422);
            }

            // Calculate amount
            $totalDuration = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
            
            $amount = ceil($this->calculateBookingAmount($validated, $tenant, $totalDuration));

            // Apply taxes
            $taxData = [];
            foreach (TaxModel::where('tenant_id', $tenant->tenant_id)->get() as $tax) {
                $taxAmount = $amount * ($tax->percentage / 100);
                $amount += $taxAmount;
                $taxData[] = ['tax_name' => $tax->name, 'amount' => $taxAmount];
            }
            $validated['user_type_id'] = 3;
            // Create user
            $userController = new UserContrl();
            $user = $userController->create_visitor_user($validated, (object)[
                'id' => $tenant->tenant_id,
                'spot_id' => $tenant->id,
                'slug' => $slug,
            ]);
            
           if (isset($user['error'])) {
    return response()->json(['message' => $user['error']],422);
}


            // Initialize Paystack payment
            $paymentData = $this->initializePaystackPayment($user->email, $amount, $slug);
            if (!$paymentData || !isset($paymentData['data']['authorization_url'], $paymentData['data']['reference'])) {
                throw new Exception('Failed to initialize payment');
            }

            // Store booking and payment data
            $reference = $paymentData['data']['reference'];
            $bookedRef = BookedRef::create([
                'booked_ref' => $reference,
                'booked_by_user' => $user->id,
                'user_id' => $user->id,
                'spot_id' => $validated['spot_id'],
                'fee' => $amount,
            ]);

            $this->registerPayment([
                'data' => [
                    'user_id' => $user->id,
                    'spot_id' => $validated['spot_id'],
                    'tenant_id' => $tenant->tenant_id,
                    'amount' => $amount,
                    'stage' => 'pending',
                    'reference' => $reference,
                    'payment_method' => 'prepaid',
                ]
            ]);

            DB::commit();

            return response()->json([
                'user' => $user,
                'amount' => $amount,
                'url' => $paymentData['data']['authorization_url'],
                'access_code'=>$paymentData['data']['access_code'],
                'payment_ref' => $reference,
                // 'access_code'=>$paymentData['data']['access_code'],
                'message' => 'Booking initialized successfully.'
            ], 200);
     } catch (Exception $e) {
            DB::rollBack();
            Log::error('Payment initiation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'error' => 'internal_error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
       }
   }

    /**
     * Confirm a payment and finalize booking
     */
    public function confirmPayment(Request $request, $slug)
    {
    DB::beginTransaction();
    try {
            // Validate input
            $validated = $this->validateConfirmRequest($request);
           
            // Normalize chosen days
            $chosenDays = $this->normalizeChosenDays($validated['chosen_days']);
            $expiryDay = $this->calculateExpiryDate($validated['type'], $chosenDays, $validated);   
            $user = User::findorFail($validated['user_id']);
            
             $tenant = $this->getTenantFromSpot($validated['spot_id']);
              $tenantAvailability = $this->getTenantAvailability($slug, $chosenDays);
        if ($tenantAvailability->isEmpty()) {
            return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
        }
             $totalDuration = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
               $amount = $this->calculateBookingAmount($validated, $tenant, $totalDuration);

            // Apply taxes
            $taxData = [];
            foreach (TaxModel::where('tenant_id', $tenant->tenant_id)->get() as $tax) {
                $taxAmount = $amount * ($tax->percentage / 100);
                $amount += $taxAmount;
                $taxData[] = ['tax_name' => $tax->name, 'amount' => $taxAmount];
            }



            // Verify payment
            $paymentInfo = $this->verifyPaymentWithPaystack($validated['reference'], $slug);
            
            if (!$paymentInfo || $paymentInfo['status'] !== 'success') {
                return response()->json([
                    'error' => 'Payment verification failed',
                    'message' => $paymentInfo['gateway_response'] ?? 'Unknown error'
                ], 422);
            }

            // Check booking reference
            $bookingRef = BookedRef::where('booked_ref', $validated['reference'])
                ->leftJoin('book_spots', 'booked_refs.id', '=', 'book_spots.booked_ref_id')
                ->select('booked_refs.id')
                ->whereNull('book_spots.id')
                ->first();

            if (!$bookingRef) {
                return response()->json(['error' => 'Booking not initiated or already exists'], 422);
            }

            // Fetch tenant
          ;
            if (!$tenant) {
                return response()->json(['message' => 'Spot not found'], 404);
            }
            $tenantData = Tenant::with('bankAccounts')->where('slug', $slug)->first();
            // Check availability
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

            // Check conflicts
            if ($this->hasConflicts($validated['spot_id'], $chosenDays)) {
                return $this->handleConflictResponse($validated['spot_id'], $chosenDays);
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

            // Create booking
            $bookSpot = BookSpot::create([
                'spot_id' => $validated['spot_id'],
                'user_id' => $validated['user_id'],
                'booked_by_user' => $validated['user_id'],
                'type' => $validated['type'],
                'chosen_days' => json_encode($chosenDays->toArray()),
                'fee' => $paymentInfo['amount'] / 100,
                'invoice_ref' => $paymentInfo['reference'],
                'booked_ref_id' => $bookingRef->id,
                'number_weeks' => $validated['number_weeks'] ?? 1,
                'number_months' => $validated['number_months'] ?? 1,
                'expiry_day' => $expiryDay,
                'start_time' => $chosenDays->first()['start_time'],
                'tenant_id'=>$tenant->tenant_id,
 ]);

            // Create reserved spots
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

           
           
        $invoiceController = new InvoiceController();
        $invoiceResponse = $invoiceController->create([
            'user_id' => $validated['user_id'],
            'amount' => $amount,
            'book_spot_id' => $bookSpot->id,
            'booked_by_user_id' => $user->id,
            'tenant_id' => $tenant->tenant_id,
        ], $validated['spot_id']);

         $invoiceRef = $invoiceResponse['invoice']['invoice_ref'];
        $bookSpot->update(['invoice_ref' => $invoiceRef]);
         // Update payment status
       $updated = SpacePaymentModel::where('payment_ref', $validated['reference'])->first();

if (!$updated) {
    throw new Exception('Payment record not found or already updated');
} else {
    $updated->update([
        'invoice_ref' => $invoiceRef,
        'amount' => $paymentInfo['amount'] / 100,
        'payment_status' => 'completed'
    ]);
}

        
            $invoice_model = InvoiceModel::where('invoice_ref',$validated['reference'])->update(['status'=>'paid']);

        $chosenDays = json_decode($bookSpot->chosen_days, true);
        // Generate Schedule
        $schedule = $this->generateSchedule($chosenDays, Carbon::parse($expiryDay));


            
        $ReceiptData = [
            'user_id' => $validated['user_id'],
            'space_price' => $tenant->space->space_fee,
            'total_price' => $amount,
            'space_category' => $tenant->space->category->category,
            'space' => $tenant->space->space_name,
            'space_booking_type' => $tenant->space->category->booking_type,
            'user_invoice' => "{$user->first_name} {$user->last_name}",
            'tenant_name' => $tenantData->company_name,
            'book_data' => $bookSpot,
            'taxes' => $taxData,
            'invoice_ref' => $invoiceRef,
            'schedule' => $schedule,
        ];

           // DB::commit();
         Mail::to($user->email)->send(new BookingRecieptMail($ReceiptData));
            return response()->json([
                'message' => 'Payment confirmed and spot booked successfully',
                'data' => $bookSpot,
            ], 201);
       } catch (Exception $e) {
            DB::rollBack();
            Log::error('Payment confirmation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'error' => 'server_error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
       }
    }

    /**
     * Validate booking request
     */
    private function validateBookingRequest(Request $request)
    {
        return Validator::make($request->all(), [
            'spot_id' => 'required|numeric|exists:spots,id',
            'company_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => [
                'required','regex:/^([0-9\s\-\+\(\)]*)$/',
                'max:20'
            ],
            'type' => 'required|in:one-off,recurrent',
            'number_weeks' => 'nullable|numeric|min:0|max:3',
            'number_months' => 'nullable|numeric|min:0|max:12',
            'chosen_days' => 'required|array',
            'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
        ], [
            'phone.required' => 'The phone number is required.',
            'phone.unique' => 'This phone number is already registered. Please login.',
            'phone.regex' => 'The phone number format is invalid.',
            'phone.max' => 'The phone number point may not be greater than 20 characters.',
        ])->validate();
    }

    /**
     * Validate confirm payment request
     */
    private function validateConfirmRequest(Request $request)
    {
        return Validator::make($request->all(), [
            'spot_id' => 'required|numeric|exists:spots,id',
            'company_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'reference' => 'required|string|max:800',
            'user_id' => 'required|numeric|exists:users,id',
            'phone' => [
                'required',
                Rule::exists('users', 'phone')->where('id', $request->user_id),
                'regex:/^([0-9\s\-\+\(\)]*)$/',
                'max:20'
            ],
            'type' => 'required|in:one-off,recurrent',
            'number_weeks' => 'nullable|numeric|min:0|max:3',
            'number_months' => 'nullable|numeric|min:0|max:12',
            'chosen_days' => 'required_if:type,recurrent|array',
            'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
            'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
        ], [
            'phone.required' => 'The phone number is required.',
            'phone.regex' => 'The phone number format is invalid.',
            'phone.max' => 'The phone number may not be greater than 20 characters.',
        ])->validate();
    }

    /**
     * Normalize chosen days
     */
<<<<<<< HEAD
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
=======
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

>>>>>>> 7157d3ee1c50c5b17d842a7e7d45112a5895f16a

    /**
     * Calculate expiry date
     */
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

    /**
     * Get tenant from spot
     */
    private function getTenantFromSpot($spotId)
    {
        return Cache::remember("tenant_spot_{$spotId}", now()->addHour(), function () use ($spotId) {
            return Spot::with(['space.category'])->find($spotId);
        });
    }

    /**
     * Get tenant availability
     */
    private function getTenantAvailability($slug, $chosenDays)
    {
        $cacheKey = "tenant_availability_{$slug}_" . md5(implode(',', $chosenDays->pluck('day')->sort()->toArray()));
        return Cache::remember($cacheKey, now()->addHour(), function () use ($slug, $chosenDays) {
            return TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
                ->where('tenants.slug', $slug)
                ->whereIn('time_set_ups.day', $chosenDays->pluck('day'))
                ->select('time_set_ups.day', 'time_set_ups.open_time', 'time_set_ups.close_time', 'tenants.id as tenant_id')
                ->get();
        });
    }

    /**
     * Check if all days are available
     */
    private function areAllDaysAvailable($chosenDays, $availability)
    {
        $availableDays = $availability->pluck('day')->map(fn($d) => strtolower($d))->toArray();
        $requestedDays = $chosenDays->pluck('day')->toArray();
        return empty(array_diff($requestedDays, $availableDays));
    }

    /**
     * Validate chosen times
     */
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
    /**
     * Check for booking conflicts
     */
    private function hasConflicts($spotId, $chosenDays, $excludeBookingId = null)
    {
        $bookings = BookSpot::where('spot_id', $spotId)
            ->where('expiry_day', '>=', Carbon::now())
            ->when($excludeBookingId, function ($query) use ($excludeBookingId) {
                $query->where('id', '!=', $excludeBookingId);
            })
            ->get();

        foreach ($bookings as $booking) {
            $existingDays = collect(json_decode($booking->chosen_days, true));
            foreach ($existingDays as $slot) {
                foreach ($chosenDays as $inputDay) {
                    if (
                        strtolower($slot['day']) === strtolower($inputDay['day']) &&
                        Carbon::parse($slot['start_time']) < $inputDay['end_time'] &&
                        Carbon::parse($slot['end_time']) > $inputDay['start_time']
                    ) {
                        return true;
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

    /**
     * Calculate total duration
     */
    private function calculateTotalDuration($chosenDays, $availability)
    {
        $availableDays = $availability->keyBy(fn($item) => strtolower($item->day));
        return $chosenDays->sum(function ($day) use ($availableDays) {
            $start = $day['start_time'];
            $end = $day['end_time'];
            return $start->diffInHours($end);
        });
    }

    /**
     * Check if recurrent booking is invalid
     */
    private function isInvalidRecurrentBooking($validated, $tenant)
    {
        return $validated['type'] === 'recurrent' &&
               ($validated['number_weeks'] > 1 && $validated['number_months'] === 0) &&
               $tenant->space->category->booking_type === 'monthly';
    }

    /**
     * Calculate booking amount
     */
    // private function calculateBookingAmount($validated, $tenant, $totalDuration)
    // {
    

    //     $numberWeeks = (int) ($validated['number_weeks']);
    //     //for numberweeks, or months is less than 1, make it 1 only
    //     if($numberWeeks>0){
    //         $numberWeeks= $numberWeeks;

    //     }else{
    //         $numberWeeks = 1;
    //     }
    //     $numberMonths = (int) ($validated['number_months'] ?? 0);
    //     $numberDays = count($validated['chosen_days']);
    //     $discount = ($tenant->space_discount > 0) ? $tenant->space_discount : null;
    //     $total = 0;

    //     switch ($tenant->space->category->booking_type) {
    //         case 'monthly':
    //             $total = $tenant->space->space_fee * ($numberMonths ?: 1);
    //             if ($discount && $tenant->min_space_discount_time <= $numberMonths) {
    //                 $total -= ($total * ($discount / 100));
    //             }
    //             break;

    //         case 'weekly':
    //             $total = $tenant->space->space_fee * $numberWeeks;
    //             if ($discount && $tenant->min_space_discount_time <= $numberWeeks) {
    //                 $total -= ($total * ($discount / 100));
    //             }
    //             break;

    //         case 'hourly':
    //             $total = $tenant->space->space_fee * $totalDuration * $numberWeeks;
    //             dd($tenant->space->space_fee);
    //             if ($discount && $tenant->min_space_discount_time <= $totalDuration) {
    //                 $total -= ($total * ($discount / 100));
    //             }
    //             break;

    //         case 'daily':
    //             $total = $tenant->space->space_fee * $numberDays;
    //             if ($discount && $tenant->min_space_discount_time <= $numberDays) {
    //                 $total -= ($total * ($discount / 100));
    //             }
    //             break;

    //         default:
    //             $total = 0;
    //     }
    //     return $total;
    // }
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


    /**
     * Initialize Paystack payment
     */
    private function initializePaystackPayment($email, $amount, $slug)
    {
        $tenant = $this->checkTenant($slug);

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
                    "Authorization: Bearer " . Crypt::decryptString($tenant->paystack_secret_key),
                    "Cache-Control: no-cache",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception('cURL error: ' . curl_error($ch));
            }

            $response = json_decode($result, true);
            if (!$response || $response['status'] !== true) {
                throw new Exception('Paystack initialization failed: ' . ($response['message'] ?? 'Unknown error'));
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }

    //initialize tenant Old
    //     private function initializePaystackPayment($email, $amount, $slug)
    // {
        
    //     $booked = new BookedRef();
    //     $reference = $booked->generateRef($slug);

    //     $ch = curl_init();
    //     try {
    //         curl_setopt_array($ch, [
    //             CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
    //             CURLOPT_POST => true,
    //             CURLOPT_POSTFIELDS => http_build_query([
    //                 'email' => $email,
    //                 'amount' => $amount * 100, // Convert to kobo
    //                 'reference' => $reference,
    //             ]),
    //             CURLOPT_HTTPHEADER => [
    //                 "Authorization: Bearer " . env('PAYMENTBEARER'),
    //                 "Cache-Control: no-cache",
    //             ],
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_TIMEOUT => 30,
    //         ]);

    //         $result = curl_exec($ch);
    //         if (curl_errno($ch)) {
    //             throw new Exception('cURL error: ' . curl_error($ch));
    //         }

    //         $response = json_decode($result, true);
    //         if (!$response || $response['status'] !== true) {
    //             throw new Exception('Paystack initialization failed: ' . ($response['message'] ?? 'Unknown error'));
    //         }

    //         return $response;
    //     } finally {
    //         curl_close($ch);
    //     }
    // }

    /**
     * Verify payment with Paystack
     */
    private function verifyPaymentWithPaystack(string $reference, $slug): ?array
    {
        $tenant = $this->checkTenant($slug);

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . Crypt::decryptString($tenant->paystack_secret_key),
                    "Cache-Control: no-cache",
                ],
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception('cURL error: ' . curl_error($ch));
            }

            $result = json_decode($response, true);
            if (!$result || $result['status'] !== true) {
                throw new Exception('Paystack verification failed: ' . ($result['message'] ?? 'Unknown error'));
            }

            return $result['data'];
        } catch (Exception $e) {
            Log::error("Paystack verification failed: " . $e->getMessage(), ['exception' => $e]);
            return null;
        } finally {
            curl_close($ch);
        }
    }

    //Verify payment with paystack old
    //     private function verifyPaymentWithPaystack(string $reference): ?array
    // {
    //     $ch = curl_init();
    //     try {
    //         curl_setopt_array($ch, [
    //             CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_TIMEOUT => 10,
    //             CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //             CURLOPT_CUSTOMREQUEST => "GET",
    //             CURLOPT_HTTPHEADER => [
    //                 "Authorization: Bearer " . env('PAYMENTBEARER'),
    //                 "Cache-Control: no-cache",
    //             ],
    //         ]);

    //         $response = curl_exec($ch);
    //         if (curl_errno($ch)) {
    //             throw new Exception('cURL error: ' . curl_error($ch));
    //         }

    //         $result = json_decode($response, true);
    //         if (!$result || $result['status'] !== true) {
    //             throw new Exception('Paystack verification failed: ' . ($result['message'] ?? 'Unknown error'));
    //         }

    //         return $result['data'];
    //     } catch (Exception $e) {
    //         Log::error("Paystack verification failed: " . $e->getMessage(), ['exception' => $e]);
    //         return null;
    //     } finally {
    //         curl_close($ch);
    //     }
    // }

    /**
     * Register payment
     */
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

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }
}
