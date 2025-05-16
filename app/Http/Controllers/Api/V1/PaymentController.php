<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant, User, Space, BookedRef, SpacePaymentModel, TimeSetUpModel, ReservedSpots};
use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Models\TaxModel;

class PaymentController extends Controller
{
    /**
     * Initiate a payment for a booking
     */
    public function initiatePay(Request $request, $slug)
    {
        DB::beginTransaction();
        try {
            // Validate input
            $validated = $this->validateBookingRequest($request);

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

            // Validate recurrent booking
            if ($this->isInvalidRecurrentBooking($validated, $tenant)) {
                return response()->json(['message' => 'This space is only available for monthly booking'], 422);
            }

            // Calculate amount
            $totalDuration = $this->calculateTotalDuration($chosenDays, $tenantAvailability);
            $amount = $this->calculateBookingAmount($validated, $tenant, $totalDuration);

            // Apply taxes
            $taxData = [];
            foreach (TaxModel::where('tenant_id', $tenant->tenant_id)->get() as $tax) {
                $taxAmount = $amount * ($tax->percentage / 100);
                $amount += $taxAmount;
                $taxData[] = ['tax_name' => $tax->name, 'amount' => $taxAmount];
            }

            // Create user
            $userController = new UserContrl();
            $user = $userController->create_visitor_user($validated, (object)[
                'id' => $tenant->tenant_id,
                'spot_id' => $tenant->id,
                'slug' => $slug,
            ]);

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
                'payment_ref' => $reference,
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

            // Verify payment
            $paymentInfo = $this->verifyPaymentWithPaystack($validated['reference']);
            if (!$paymentInfo || $paymentInfo['status'] !== 'success') {
                return response()->json([
                    'error' => 'Payment verification failed',
                    'message' => $paymentInfo['message'] ?? 'Unknown error'
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
            $tenant = $this->getTenantFromSpot($validated['spot_id']);
            if (!$tenant) {
                return response()->json(['message' => 'Spot not found'], 404);
            }

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
                    'start_time' => $day['start U_time'],
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

            // Update payment status
            $updated = SpacePaymentModel::where('payment_ref', $paymentInfo['reference'])->update([
                'amount' => $paymentInfo['amount'] / 100,
                'payment_status' => 'completed',
            ]);

            if ($updated === 0) {
                throw new Exception('Payment record not found or already updated');
            }

            DB::commit();

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
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^([0-9\s\-\+\(\)]*)$/',
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
            $open = Carbon::parse($availableDays[$day['day']]->open_time)->format('H:i:s');
            $close = Carbon::parse($availableDays[$day['day']]->close_time)->format('H:i:s');
            $start = Carbon::parse($day['start_time'])->format('H:i:s');
            $end = Carbon::parse($day['end_time'])->format('H:i:s');
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
    private function calculateBookingAmount($validated, $tenant, $totalDuration)
    {
        $numberWeeks = (int) ($validated['number_weeks'] ?? 1);
        $numberMonths = (int) ($validated['number_months'] ?? 0);
        $numberDays = count($validated['chosen_days']);
        $discount = ($tenant->space_discount > 0) ? $tenant->space_discount : null;
        $total = 0;

        switch ($tenant->space->category->booking_type) {
            case 'monthly':
                $total = $tenant->space->space_fee * ($numberMonths ?: 1);
                if ($discount && $tenant->min_space_discount_time <= $numberMonths) {
                    $total -= ($total * ($discount / 100));
                }
                break;

            case 'weekly':
                $total = $tenant->space->space_fee * $numberWeeks;
                if ($discount && $tenant->min_space_discount_time <= $numberWeeks) {
                    $total -= ($total * ($discount / 100));
                }
                break;

            case 'hourly':
                $total = $tenant->space->space_fee * $totalDuration * $numberWeeks;
                if ($discount && $tenant->min_space_discount_time <= $totalDuration) {
                    $total -= ($total * ($discount / 100));
                }
                break;

            case 'daily':
                $total = $tenant->space->space_fee * $numberDays;
                if ($discount && $tenant->min_space_discount_time <= $numberDays) {
                    $total -= ($total * ($discount / 100));
                }
                break;

            default:
                $total = 0;
        }
        return $total;
    }

    /**
     * Initialize Paystack payment
     */
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

            $response = json_decode($result, true);
            if (!$response || $response['status'] !== true) {
                throw new Exception('Paystack initialization failed: ' . ($response['message'] ?? 'Unknown error'));
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Verify payment with Paystack
     */
    private function verifyPaymentWithPaystack(string $reference): ?array
    {
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . env('PAYMENTBEARER'),
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
}

// namespace App\Http\Controllers\Api\V1;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Validator;
// use App\Models\{BookSpot, Spot, Tenant, User, Space, BookedRef, SpacePaymentModel,TimeSetUpModel,ReservedSpots};
// use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
// use Illuminate\Support\Facades\Storage;
// use Carbon\Carbon;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Validation\Rule;
// use Exception;
// use Illuminate\Support\Facades\Log;
// class PaymentController extends Controller
// {
    
// public function initiatePay(Request $request, $slug)
//     {
//         try {
//             // Validate input
//             $validator = Validator::make($request->all(), [
//                 'spot_id' => 'required|numeric|exists:spots,id',
//                 'company_name' => 'required|string|max:255',
//                 'first_name' => 'required|string|max:255',
//                 'last_name' => 'required|string|max:255',
//                 'email' => 'required|email|max:255|unique:users,email',
//                 'phone' => [
//                     'required',
//                     'unique:users,phone',
//                     'regex:/^([0-9\s\-\+\(\)]*)$/',
//                     'max:20'
//                 ],
//                 'type' => 'required|in:one-off,recurrent',
//                 'number_weeks' => 'nullable|numeric|min:1|max:3',
//                 'number_months' => 'nullable|numeric|min:0|max:12',
//                 'chosen_days' => 'required|array',
//                 'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
//                 'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
//                 'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s',
//             ], [
//                 'phone.required' => 'The phone number is required.',
//                 'phone.unique' => 'This phone number is already registered. Please login.',
//                 'phone.regex' => 'The phone number format is invalid.',
//                 'phone.max' => 'The phone number may not be greater than 20 characters.',
//             ])->after(function ($validator) use ($request) {
//                 foreach ($request->input('chosen_days', []) as $index => $day) {
//                     if (isset($day['start_time'], $day['end_time'])) {
//                         $startTime = Carbon::parse($day['start_time']);
//                         $endTime = Carbon::parse($day['end_time']);
//                         if ($endTime->lte($startTime)) {
//                             $validator->errors()->add(
//                                 "chosen_days.$index.end_time",
//                                 "The end time must be after the start time for {$day['day']}."
//                             );
//                         }
//                     }
//                 }
//             });

//             if ($validator->fails()) {
//                 return response()->json(['errors' => $validator->errors()], 422);
//             }

//             $validatedData = $validator->validated();
//             $validatedData['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validatedData['phone']);
//             $email = $validatedData['email'];
//             $number_days = count($validatedData['chosen_days']);
//             $number_weeks = (int) ($validatedData['number_weeks'] ?? 0);
//             $number_months = (int) ($validatedData['number_months'] ?? 0);

//             // Pre-parse chosen days
//             $chosenDays = collect($validatedData['chosen_days'])->map(function ($day) {
//                 return [
//                     'day' => $day['day'],
//                     'start_time' => Carbon::parse($day['start_time']),
//                     'end_time' => Carbon::parse($day['end_time']),
//                 ];
//             });

//             if($validatedData['type'] === 'recurrent'){
//                 $lastDay = $chosenDays->first();
                
//                 $expiry_day = $lastDay['end_time']->copy()->addWeeks($number_weeks)->addMonths($number_months);
//             }else{
//                 $lastDay = $chosenDays->last();
//                 $expiry_day = $lastDay['end_time'];
//             }
//             // Fetch tenant availability
//             $cacheKey = "tenant_availability_{$slug}_" . md5(json_encode($chosenDays->pluck('day')->sort()->values()->toArray()));
//             $tenant_available = Cache::remember($cacheKey, now()->addHours(1), function () use ($slug, $chosenDays) {
//                 return TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
//                     ->where('tenants.slug', $slug)
//                     ->whereIn('time_set_ups.day', $chosenDays->pluck('day')->map(fn($day) => strtolower($day)))
//                     ->select('tenants.id as tenant_id', 'time_set_ups.open_time', 'time_set_ups.day', 'time_set_ups.close_time')
//                     ->get();
//             });

//             if ($tenant_available->isEmpty()) {
//                 return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
//             }

//             // Validate all requested days are available
//             $availableDayKeys = $tenant_available->pluck('day')->map(fn($day) => strtolower($day))->toArray();
//             $requestedDays = $chosenDays->pluck('day')->map(fn($day) => strtolower($day))->toArray();
//             $missingDays = array_diff($requestedDays, $availableDayKeys);
//             if (!empty($missingDays)) {
//                 return response()->json([
//                     'message' => 'The following days are not available: ' . implode(', ', $missingDays)
//                 ], 422);
//             }

//             // Fetch tenant details
//             $tenantCacheKey = "tenant_spot_{$validatedData['spot_id']}";
//             $tenant = Cache::remember($tenantCacheKey, now()->addHours(1), function () use ($validatedData) {
//                 return Spot::where('spots.id', $validatedData['spot_id'])
//                     ->join('spaces', 'spaces.id', '=', 'spots.space_id')
//                     ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
//                     ->select(
//                         'spaces.id as space_id',
//                         'spaces.space_category_id',
//                         'spots.id',
//                         'spots.tenant_id',
//                         'spaces.space_name',
//                         'spaces.space_fee',
//                         'spaces.min_space_discount_time',
//                         'spaces.space_discount',
//                         'categories.category',
//                         'categories.booking_type',
//                         'categories.min_duration'
//                     )
//                     ->first();
//             });

//             if (!$tenant) {
//                 return response()->json(['message' => 'Spot not found'], 404);
//             }

//             // Check for conflicts
//             $hasConflicts = ReservedSpots::where('spot_id', $validatedData['spot_id'])
//                 ->whereIn('day', $chosenDays->pluck('day'))
//                 ->where('expiry_day', '>=', Carbon::now())
//                 ->where(function ($query) use ($chosenDays) {
//                     $chosenDays->each(function ($day) use ($query) {
//                         $query->orWhere(function ($subQuery) use ($day) {
//                             $subQuery->where('day', $day['day'])
//                                      ->where('start_time', '<', $day['end_time'])
//                                      ->where('end_time', '>', $day['start_time']);
//                         });
//                     });
//                 })
//                 ->exists();

//             if ($hasConflicts) {
//                 $chosenDaysByDay = $chosenDays->keyBy('day');
//                 $reservedSpots = ReservedSpots::where('spot_id', $validatedData['spot_id'])
//                     ->whereIn('day', $chosenDays->pluck('day'))
//                     ->where('expiry_day', '>=', Carbon::now())
//                     ->where(function ($query) use ($chosenDays) {
//                         $chosenDays->each(function ($day) use ($query) {
//                             $query->orWhere(function ($subQuery) use ($day) {
//                                 $subQuery->where('day', $day['day'])
//                                          ->where('start_time', '<', $day['end_time'])
//                                          ->where('end_time', '>', $day['start_time']);
//                             });
//                         });
//                     })
//                     ->get();

//                 $conflicts = $reservedSpots->map(function ($spot) use ($chosenDaysByDay) {
//                     $matchingDay = $chosenDaysByDay->get($spot->day);
//                     if ($matchingDay && (
//                         $spot->start_time < $matchingDay['end_time'] &&
//                         $spot->end_time > $matchingDay['start_time']
//                     )) {
//                         return [
//                             'day' => $spot->day,
//                             'start_time' => Carbon::parse($spot->start_time)->toDateTimeString(),
//                             'end_time' => Carbon::parse($spot->end_time)->toDateTimeString(),
//                         ];
//                     }
//                     return null;
//                 })->filter()->values();

//                 $conflictMessages = $conflicts->map(function ($conflict) {
//                     return "Day: {$conflict['day']}, reserved from {$conflict['start_time']} to {$conflict['end_time']}";
//                 })->implode('; ');

//                 return response()->json([
//                     'message' => 'This workspace is already reserved during the selected time',
//                     'conflicts' => $conflicts->isNotEmpty() ? $conflictMessages : 'Specific conflict details unavailable',
//                 ], 422);
//             }

//             // Validate availability and calculate duration
//             $total_duration = 0;
//             $availableDays = $tenant_available->keyBy(fn ($item) => strtolower($item->day));
//             foreach ($chosenDays as $day) {
//                 $dayKey = strtolower($day['day']);
//                 if (!$availableDays->has($dayKey)) {
//                     return response()->json(['message' => "This space is not available on {$day['day']}"], 422);
//                 }

//                 $tenantTime = $availableDays[$dayKey];
//                 $openTime = Carbon::parse($tenantTime->open_time);
//                 $closeTime = Carbon::parse($tenantTime->close_time);
//                 $startTime = $day['start_time'];
//                 $endTime = $day['end_time'];

//                 if ($startTime->format('H:i') < $openTime->format('H:i') || $endTime->format('H:i') > $closeTime->format('H:i')) {
//                     return response()->json(['message' => "This workspace is not available during the selected time on {$day['day']}"], 422);
//                 }

//                 $total_duration += $startTime->diffInHours($endTime);
//             }

//             if ($validatedData['type'] === 'recurrent' && $number_weeks > 1 && $number_months === 0 && $tenant->booking_type === 'monthly') {
//                 return response()->json(['message' => 'This space is only available for monthly booking'], 422);
//             }

//             // Calculate amount
//             $amount = 0;
//             if ($validatedData['type'] === 'one-off') {
//                 $number_weeks>0 ? $number_weeks : 1;
//                 switch ($tenant->booking_type) {
//                     case 'monthly':
//                         if ($number_months > 0 || $number_weeks > 0) {
//                             return response()->json(['message' => 'One-off bookings cannot span multiple months or weeks for monthly spaces'], 422);
//                         }
//                         $total = $tenant->space_fee;
//                         if ($tenant->min_space_discount_time <= 1) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'weekly':
//                         $total = $tenant->space_fee*$total_duration* $number_weeks;
//                         if ($tenant->min_space_discount_time <= 1) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;
//                         $total = $tenant->space_fee;
//                         if ($tenant->min_space_discount_time <= 1) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'hourly':
//                         $total = $tenant->space_fee * $total_duration* $number_weeks;
//                         if ($tenant->min_space_discount_time < $total_duration) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'daily':
//                         $total = $tenant->space_fee * $number_days;
//                         if ($tenant->min_space_discount_time < $number_days) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     default:
//                         $total = 0;
//                 }
//             } else {
//                 $number_weeks>0 ? $number_weeks : 1;
//                 switch ($tenant->booking_type) {
//                     case 'monthly':
//                         $total = $tenant->space_fee * $number_months;
//                         if ($tenant->min_space_discount_time < $number_months) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'weekly':
//                         $total = $tenant->space_fee * $number_weeks;
//                         if ($tenant->min_space_discount_time < $number_weeks) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'hourly':
//                         $total = $tenant->space_fee * $total_duration * $number_weeks;
//                         if ($tenant->min_space_discount_time < $total_duration) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     case 'daily':
//                         $total = $tenant->space_fee * $number_weeks;
//                         if ($tenant->min_space_discount_time < $number_days) {
//                             $total -= ($total * ($tenant->space_discount / 100));
//                         }
//                         break;

//                     default:
//                         $total = 0;
//                 }
//             }
//             $amount = $total * 100;

//             $validatedData['chosen_days'] = json_encode($validatedData['chosen_days']);
//             $validatedData['user_type_id'] = 3;
//             $userController = new UserContrl();
//             $user = $userController->create_visitor_user($validatedData, (object)[
//                 'id' => $tenant->tenant_id,
//                 'spot_id' => $tenant->id,
//                 'slug' => $slug,
//             ]);

//             $payment_data = $this->initializePaystackPayment($email, $amount, $slug);
//             if ($payment_data['data']['authorization_url'] && $payment_data['data']['reference']) {
//                 DB::transaction(function () use ($user, $validatedData, $tenant, $payment_data, $amount) {
//                     BookedRef::create([
//                         'booked_ref' => $payment_data['data']['reference'],
//                         'booked_by_user' => $user->id,
//                         'user_id' => $user->id,
//                         'spot_id' => $validatedData['spot_id'],
//                     ]);
//                     $this->registerPayment([
//                         'data' => [
//                             'user_id' => $user->id,
//                             'spot_id' => $validatedData['spot_id'],
//                             'tenant_id' => $tenant->tenant_id,
//                             'amount' => $amount,
//                             'stage' => 'pending',
//                             'reference' => $payment_data['data']['reference'],
//                             'payment_method' => 'prepaid',
//                         ]
//                     ]);
//                 });
//             }

//             return response()->json([
//                 'user' => $user,
//                 'amount' => $amount,
//                 'url' => $payment_data['data']['authorization_url'],
//                 'payment_ref' => $payment_data['data']['reference'],
//                 'message' => 'Booking initialized successfully.'
//             ], 200);
//         } catch (Exception $e) {
//             Log::error('Payment initiation failed: ' . $e->getMessage());
//             return response()->json([
//                 'error' => 'internal_error',
//                 'message' => 'An unexpected error occurred. Please try again later.'
//             ], 500);
//         }
//     }
    


//     private function initializePaystackPayment($email, $amount, $slug)
//     {
//         $booked = new BookedRef();
//         $reference = $booked->generateRef($slug);

//         $ch = curl_init();
//         try {
//             curl_setopt_array($ch, [
//                 CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
//                 CURLOPT_POST => true,
//                 CURLOPT_POSTFIELDS => http_build_query([
//                     'email' => $email,
//                     'amount' => $amount * 100, // Convert to kobo
//                     'reference' => $reference,
//                 ]),
//                 CURLOPT_HTTPHEADER => [
//                     "Authorization: Bearer " . env('PAYMENTBEARER'),
//                     "Cache-Control: no-cache",
//                 ],
//                 CURLOPT_RETURNTRANSFER => true,
//                 CURLOPT_TIMEOUT => 30,
//             ]);

//             $result = curl_exec($ch);
//             if (curl_errno($ch)) {
//                 throw new Exception('cURL error: ' . curl_error($ch));
//             }

//             return json_decode($result, true) ?? null;
//         } finally {
//             curl_close($ch);
//         }
//     }

//     private function registerPayment($data)
//     {
//         return SpacePaymentModel::create([
//             'user_id' => $data['data']['user_id'],
//             'spot_id' => $data['data']['spot_id'],
//             'tenant_id' => $data['data']['tenant_id'],
//             'amount' => $data['data']['amount'],
//             'payment_status' => $data['data']['stage'],
//             'payment_ref' => $data['data']['reference'],
//             'payment_method' => 'prepaid',
//         ]);
//     }
  
   
//     public function confirmPayment(Request $request, $slug)
//     {
//         try {
//             // Validate input
//             $validator = Validator::make($request->all(), [
//                 'spot_id' => 'required|numeric|exists:spots,id',
//                 'company_name' => 'required|string|max:255',
//                 'first_name' => 'required|string|max:255',
//                 'last_name' => 'required|string|max:255',
//                 'email' => 'required|email|max:255',
//                 'reference' => 'required|string|max:800',
//                 'user_id' => 'required|numeric|exists:users,id',
//                 'phone' => [
//                     'required',
//                     Rule::exists('users', 'phone')->where('id', $request->user_id),
//                     'regex:/^([0-9\s\-\+\(\)]*)$/',
//                     'max:20'
//                 ],
//                 'type' => 'required|in:one-off,recurrent',
//                 'number_weeks' => 'nullable|numeric|min:1|max:3',
//                 'number_months' => 'nullable|numeric|min:0|max:12',
//                 'chosen_days' => 'required_if:type,recurrent|array',
//                 'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
//                 'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
//                 'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
//             ], [
//                 'phone.required' => 'The phone number is required.',
//                 'phone.regex' => 'The phone number format is invalid.',
//                 'phone.max' => 'The phone number may not be greater than 20 characters.',
//             ]);

//             if ($validator->fails()) {
//                 return response()->json(['errors' => $validator->errors()], 422);
//             }

//             $validated = $validator->validated();
//             $chosenDays = collect($validated['chosen_days'])->map(function ($day) {
//                 return [
//                     'day' => $day['day'],
//                     'start_time' => \Carbon\Carbon::parse($day['start_time']),
//                     'end_time' => \Carbon\Carbon::parse($day['end_time']),
//                 ];
//             });

//             $number_weeks = (int) ($validated['number_weeks'] ?? 0);
//             $number_months = (int) ($validated['number_months'] ?? 0);
//             $lastDay = $chosenDays->first();
//             $expiry_day = $lastDay['end_time']->copy()->addWeeks($number_weeks)->addMonths($number_months);

//             // Verify payment
//             $paymentInfo = $this->verifyPaymentWithPaystack($validated['reference']);
            

//             if (!$paymentInfo || $paymentInfo['status'] !== 'success') {
//                 return response()->json(['error' => 'Payment verification failed', 'message' => $paymentInfo['status'] ?? 'unknown'], 422);
//             }

//             // Check booking reference and ensure no existing booking
//             $bookingRef = BookedRef::where('booked_ref', $paymentInfo['reference'])
//                 ->leftJoin('book_spots', 'booked_refs.id', '=', 'book_spots.booked_ref_id')
//                 ->select('booked_refs.id')
//                 ->whereNull('book_spots.id')
//                 ->first();

//             if (!$bookingRef) {
//                 return response()->json(['error' => 'Booking not initiated or already exists'], 422);
//             }
//             $bookingRefId = $bookingRef->id;

//             // Check for conflicts
//             $hasConflicts = ReservedSpots::where('spot_id', $validated['spot_id'])
//                 ->whereIn('day', $chosenDays->pluck('day'))
//                 ->where('expiry_day', '>=', \Carbon\Carbon::now())
//                 ->where(function ($query) use ($chosenDays) {
//                     $chosenDays->each(function ($day) use ($query) {
//                         $query->orWhere(function ($subQuery) use ($day) {
//                             $subQuery->where('day', $day['day'])
//                                      ->where('start_time', '<', $day['end_time'])
//                                      ->where('end_time', '>', $day['start_time']);
//                         });
//                     });
//                 })
//                 ->exists();

//             if ($hasConflicts) {
//                 $chosenDaysByDay = $chosenDays->keyBy('day');
//                 $reservedSpots = ReservedSpots::where('spot_id', $validated['spot_id'])
//                     ->whereIn('day', $chosenDays->pluck('day'))
//                     ->where('expiry_day', '>=', \Carbon\Carbon::now())
//                     ->where(function ($query) use ($chosenDays) {
//                         $chosenDays->each(function ($day) use ($query) {
//                             $query->orWhere(function ($subQuery) use ($day) {
//                                 $subQuery->where('day', $day['day'])
//                                          ->where('start_time', '<', $day['end_time'])
//                                          ->where('end_time', '>', $day['start_time']);
//                             });
//                         });
//                     })
//                     ->get();

//                 $conflicts = $reservedSpots->map(function ($spot) use ($chosenDaysByDay) {
//                     $matchingDay = $chosenDaysByDay->get($spot->day);
//                     if ($matchingDay && (
//                         $spot->start_time < $matchingDay['end_time'] &&
//                         $spot->end_time > $matchingDay['start_time']
//                     )) {
//                         return [
//                             'day' => $spot->day,
//                             'end_time' => \Carbon\Carbon::parse($spot->end_time)->toDateTimeString(),
//                         ];
//                     }
//                     return null;
//                 })->filter()->values();

//                 $conflictMessages = $conflicts->map(function ($conflict) {
//                     return "Day: {$conflict['day']}, reserved until {$conflict['end_time']}";
//                 })->implode('; ');

//                 return response()->json([
//                     'message' => 'This workspace is already reserved during the selected time',
//                     'conflicts' => $conflicts->isNotEmpty() ? $conflictMessages : 'Specific conflict details unavailable',
//                 ], 422);
//             }

//             return DB::transaction(function () use ($validated, $bookingRefId, $paymentInfo, $chosenDays, $expiry_day) {

//                 $bookSpot = BookSpot::create([
//                     'spot_id' => $validated['spot_id'],
//                     'user_id' => $validated['user_id'],
//                     'booked_by_user' => $validated['user_id'],
//                     'type' => $validated['type'],
//                     'chosen_days' => json_encode($validated['chosen_days']),
//                     'fee' => $paymentInfo['amount'] / 100,
//                     'invoice_ref' => $paymentInfo['reference'],
//                     'booked_ref_id' => $bookingRefId,
//                     'number_weeks' => $validated['number_weeks'] ?? 1,
//                     'number_months' => $validated['number_months'] ?? 1,
//                     'expiry_day' => $expiry_day,
//                     'start_time' => $chosenDays->first()['start_time'],
//                 ]);
//                 // Batch insert ReservedSpots
//                 $reservedSpotsData = $chosenDays->map(function ($day) use ($validated, $expiry_day) {
//                     return [
//                         'user_id' => $validated['user_id'],
//                         'spot_id' => $validated['spot_id'],
//                         'day' => $day['day'],
//                         'start_time' => $day['start_time'],
//                         'end_time' => $day['end_time'],
//                         'expiry_day' => $expiry_day,
//                         'created_at' => now(),
//                         'updated_at' => now(),
//                         'booked_spot_id' => $bookSpot->id,
//                     ];
//                 })->toArray();
//                 ReservedSpots::insert($reservedSpotsData);

//                 // Create BookSpot
               
//                 // Update SpacePaymentModel
//                 $updated = SpacePaymentModel::where('payment_ref', $paymentInfo['reference'])->update([
//                     'amount' => $paymentInfo['amount'] / 100,
//                     'payment_status' => 'completed',
//                 ]);

//                 if ($updated === 0) {
//                     throw new \Exception('Payment record not found or already updated');
//                 }

//                 return response()->json([
//                     'message' => 'Payment confirmed and spot booked successfully',
//                     'data' => $bookSpot,
//                 ], 201);
//             });
//         } catch (Exception $e) {
//             Log::error('Payment confirmation failed: ' . $e->getMessage());
//             return response()->json([
//                 'error' => 'server_error',
//                 'message' => 'An unexpected error occurred. Please try again later.'.$e->getMessage()
//             ], 500);
//         }
//     }
    
//     private function verifyPaymentWithPaystack(string $reference): ?array
//     {
//         $ch = curl_init();
//         try {
//             curl_setopt_array($ch, [
//                 CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
//                 CURLOPT_RETURNTRANSFER => true,
//                 CURLOPT_TIMEOUT => 10,
//                 CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
//                 CURLOPT_CUSTOMREQUEST => "GET",
//                 CURLOPT_HTTPHEADER => [
//                     "Authorization: Bearer " . env('PAYMENTBEARER'),
//                     "Cache-Control: no-cache",
//                 ],
//             ]);
    
//             $response = curl_exec($ch);
    
//             if (curl_errno($ch)) {
//                 throw new Exception('cURL error: ' . curl_error($ch));
//             }
    
//             $result = json_decode($response, true);
//             return $result['data'] ?? null;
    
//         } catch (Exception $e) {
//             Log::error("Paystack verification failed: " . $e->getMessage());
//             return null;
//         } finally {
//             curl_close($ch);
//         }
//     }
    
// }


