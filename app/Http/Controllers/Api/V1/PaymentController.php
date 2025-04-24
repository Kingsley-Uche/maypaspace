<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{BookSpot, Spot, Tenant, User, Space, BookedRef, SpacePaymentModel,TimeSetUpModel,ReservedSpots};
use App\Http\Controllers\Api\V1\UserFunctionsController as UserContrl;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;
use Illuminate\Support\Facades\Log;
class PaymentController extends Controller
{
    
public function initiatePay(Request $request, $slug)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
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
                'number_weeks' => 'nullable|numeric|min:1|max:3',
                'number_months' => 'nullable|numeric|min:0|max:12',
                'chosen_days' => 'required|array',
                'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
                'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
                'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s',
            ], [
                'phone.required' => 'The phone number is required.',
                'phone.unique' => 'This phone number is already registered. Please login.',
                'phone.regex' => 'The phone number format is invalid.',
                'phone.max' => 'The phone number may not be greater than 20 characters.',
            ])->after(function ($validator) use ($request) {
                foreach ($request->input('chosen_days', []) as $index => $day) {
                    if (isset($day['start_time'], $day['end_time'])) {
                        $startTime = Carbon::parse($day['start_time']);
                        $endTime = Carbon::parse($day['end_time']);
                        if ($endTime->lte($startTime)) {
                            $validator->errors()->add(
                                "chosen_days.$index.end_time",
                                "The end time must be after the start time for {$day['day']}."
                            );
                        }
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validatedData = $validator->validated();
            $validatedData['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validatedData['phone']);
            $email = $validatedData['email'];
            $number_days = count($validatedData['chosen_days']);
            $number_weeks = (int) ($validatedData['number_weeks'] ?? 0);
            $number_months = (int) ($validatedData['number_months'] ?? 0);

            // Pre-parse chosen days
            $chosenDays = collect($validatedData['chosen_days'])->map(function ($day) {
                return [
                    'day' => $day['day'],
                    'start_time' => Carbon::parse($day['start_time']),
                    'end_time' => Carbon::parse($day['end_time']),
                ];
            });

            $lastDay = $chosenDays->first();
            $expiry_day = $lastDay['end_time']->copy()->addWeeks($number_weeks)->addMonths($number_months);

            // Fetch tenant availability
            $cacheKey = "tenant_availability_{$slug}_" . md5(json_encode($chosenDays->pluck('day')->sort()->values()->toArray()));
            $tenant_available = Cache::remember($cacheKey, now()->addHours(1), function () use ($slug, $chosenDays) {
                return TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
                    ->where('tenants.slug', $slug)
                    ->whereIn('time_set_ups.day', $chosenDays->pluck('day')->map(fn($day) => strtolower($day)))
                    ->select('tenants.id as tenant_id', 'time_set_ups.open_time', 'time_set_ups.day', 'time_set_ups.close_time')
                    ->get();
            });

            if ($tenant_available->isEmpty()) {
                return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
            }

            // Validate all requested days are available
            $availableDayKeys = $tenant_available->pluck('day')->map(fn($day) => strtolower($day))->toArray();
            $requestedDays = $chosenDays->pluck('day')->map(fn($day) => strtolower($day))->toArray();
            $missingDays = array_diff($requestedDays, $availableDayKeys);
            if (!empty($missingDays)) {
                return response()->json([
                    'message' => 'The following days are not available: ' . implode(', ', $missingDays)
                ], 422);
            }

            // Fetch tenant details
            $tenantCacheKey = "tenant_spot_{$validatedData['spot_id']}";
            $tenant = Cache::remember($tenantCacheKey, now()->addHours(1), function () use ($validatedData) {
                return Spot::where('spots.id', $validatedData['spot_id'])
                    ->join('spaces', 'spaces.id', '=', 'spots.space_id')
                    ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
                    ->select(
                        'spaces.id as space_id',
                        'spaces.space_category_id',
                        'spots.id',
                        'spots.tenant_id',
                        'spaces.space_name',
                        'spaces.space_fee',
                        'spaces.min_space_discount_time',
                        'spaces.space_discount',
                        'categories.category',
                        'categories.booking_type',
                        'categories.min_duration'
                    )
                    ->first();
            });

            if (!$tenant) {
                return response()->json(['message' => 'Spot not found'], 404);
            }

            // Check for conflicts
            $hasConflicts = ReservedSpots::where('spot_id', $validatedData['spot_id'])
                ->whereIn('day', $chosenDays->pluck('day'))
                ->where('expiry_day', '>=', Carbon::now())
                ->where(function ($query) use ($chosenDays) {
                    $chosenDays->each(function ($day) use ($query) {
                        $query->orWhere(function ($subQuery) use ($day) {
                            $subQuery->where('day', $day['day'])
                                     ->where('start_time', '<', $day['end_time'])
                                     ->where('end_time', '>', $day['start_time']);
                        });
                    });
                })
                ->exists();

            if ($hasConflicts) {
                $chosenDaysByDay = $chosenDays->keyBy('day');
                $reservedSpots = ReservedSpots::where('spot_id', $validatedData['spot_id'])
                    ->whereIn('day', $chosenDays->pluck('day'))
                    ->where('expiry_day', '>=', Carbon::now())
                    ->where(function ($query) use ($chosenDays) {
                        $chosenDays->each(function ($day) use ($query) {
                            $query->orWhere(function ($subQuery) use ($day) {
                                $subQuery->where('day', $day['day'])
                                         ->where('start_time', '<', $day['end_time'])
                                         ->where('end_time', '>', $day['start_time']);
                            });
                        });
                    })
                    ->get();

                $conflicts = $reservedSpots->map(function ($spot) use ($chosenDaysByDay) {
                    $matchingDay = $chosenDaysByDay->get($spot->day);
                    if ($matchingDay && (
                        $spot->start_time < $matchingDay['end_time'] &&
                        $spot->end_time > $matchingDay['start_time']
                    )) {
                        return [
                            'day' => $spot->day,
                            'start_time' => Carbon::parse($spot->start_time)->toDateTimeString(),
                            'end_time' => Carbon::parse($spot->end_time)->toDateTimeString(),
                        ];
                    }
                    return null;
                })->filter()->values();

                $conflictMessages = $conflicts->map(function ($conflict) {
                    return "Day: {$conflict['day']}, reserved from {$conflict['start_time']} to {$conflict['end_time']}";
                })->implode('; ');

                return response()->json([
                    'message' => 'This workspace is already reserved during the selected time',
                    'conflicts' => $conflicts->isNotEmpty() ? $conflictMessages : 'Specific conflict details unavailable',
                ], 422);
            }

            // Validate availability and calculate duration
            $total_duration = 0;
            $availableDays = $tenant_available->keyBy(fn ($item) => strtolower($item->day));
            foreach ($chosenDays as $day) {
                $dayKey = strtolower($day['day']);
                if (!$availableDays->has($dayKey)) {
                    return response()->json(['message' => "This space is not available on {$day['day']}"], 422);
                }

                $tenantTime = $availableDays[$dayKey];
                $openTime = Carbon::parse($tenantTime->open_time);
                $closeTime = Carbon::parse($tenantTime->close_time);
                $startTime = $day['start_time'];
                $endTime = $day['end_time'];

                if ($startTime->format('H:i') < $openTime->format('H:i') || $endTime->format('H:i') > $closeTime->format('H:i')) {
                    return response()->json(['message' => "This workspace is not available during the selected time on {$day['day']}"], 422);
                }

                $total_duration += $startTime->diffInHours($endTime);
            }

            if ($validatedData['type'] === 'recurrent' && $number_weeks > 1 && $number_months === 0 && $tenant->booking_type === 'monthly') {
                return response()->json(['message' => 'This space is only available for monthly booking'], 422);
            }

            // Calculate amount
            $amount = 0;
            if ($validatedData['type'] === 'one-off') {
                $number_weeks>0 ? $number_weeks : 1;
                switch ($tenant->booking_type) {
                    case 'monthly':
                        if ($number_months > 0 || $number_weeks > 0) {
                            return response()->json(['message' => 'One-off bookings cannot span multiple months or weeks for monthly spaces'], 422);
                        }
                        $total = $tenant->space_fee;
                        if ($tenant->min_space_discount_time <= 1) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'weekly':
                        $total = $tenant->space_fee*$total_duration* $number_weeks;
                        if ($tenant->min_space_discount_time <= 1) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;
                        $total = $tenant->space_fee;
                        if ($tenant->min_space_discount_time <= 1) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'hourly':
                        $total = $tenant->space_fee * $total_duration* $number_weeks;
                        if ($tenant->min_space_discount_time < $total_duration) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'daily':
                        $total = $tenant->space_fee * $number_days;
                        if ($tenant->min_space_discount_time < $number_days) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    default:
                        $total = 0;
                }
            } else {
                $number_weeks>0 ? $number_weeks : 1;
                switch ($tenant->booking_type) {
                    case 'monthly':
                        $total = $tenant->space_fee * $number_months;
                        if ($tenant->min_space_discount_time < $number_months) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'weekly':
                        $total = $tenant->space_fee * $number_weeks;
                        if ($tenant->min_space_discount_time < $number_weeks) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'hourly':
                        $total = $tenant->space_fee * $total_duration * $number_weeks;
                        if ($tenant->min_space_discount_time < $total_duration) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    case 'daily':
                        $total = $tenant->space_fee * $number_weeks;
                        if ($tenant->min_space_discount_time < $number_days) {
                            $total -= ($total * ($tenant->space_discount / 100));
                        }
                        break;

                    default:
                        $total = 0;
                }
            }
            $amount = $total * 100;

            $validatedData['chosen_days'] = json_encode($validatedData['chosen_days']);
            $validatedData['user_type_id'] = 3;
            $userController = new UserContrl();
            $user = $userController->create_visitor_user($validatedData, (object)[
                'id' => $tenant->tenant_id,
                'spot_id' => $tenant->id,
                'slug' => $slug,
            ]);

            $payment_data = $this->initializePaystackPayment($email, $amount, $slug);
            if ($payment_data['data']['authorization_url'] && $payment_data['data']['reference']) {
                DB::transaction(function () use ($user, $validatedData, $tenant, $payment_data, $amount) {
                    BookedRef::create([
                        'booked_ref' => $payment_data['data']['reference'],
                        'booked_by_user' => $user->id,
                        'user_id' => $user->id,
                        'spot_id' => $validatedData['spot_id'],
                    ]);
                    $this->registerPayment([
                        'data' => [
                            'user_id' => $user->id,
                            'spot_id' => $validatedData['spot_id'],
                            'tenant_id' => $tenant->tenant_id,
                            'amount' => $amount,
                            'stage' => 'pending',
                            'reference' => $payment_data['data']['reference'],
                            'payment_method' => 'prepaid',
                        ]
                    ]);
                });
            }

            return response()->json([
                'user' => $user,
                'amount' => $amount,
                'url' => $payment_data['data']['authorization_url'],
                'payment_ref' => $payment_data['data']['reference'],
                'message' => 'Booking initialized successfully.'
            ], 200);
        } catch (Exception $e) {
            Log::error('Payment initiation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'internal_error',
                'message' => 'An unexpected error occurred. Please try again later.'
            ], 500);
        }
    }
    


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
            // Validate input
            $validator = Validator::make($request->all(), [
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
                'number_weeks' => 'nullable|numeric|min:1|max:3',
                'number_months' => 'nullable|numeric|min:0|max:12',
                'chosen_days' => 'required_if:type,recurrent|array',
                'chosen_days.*.day' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
                'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
                'chosen_days.*.end_time' => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
            ], [
                'phone.required' => 'The phone number is required.',
                'phone.regex' => 'The phone number format is invalid.',
                'phone.max' => 'The phone number may not be greater than 20 characters.',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();
            $chosenDays = collect($validated['chosen_days'])->map(function ($day) {
                return [
                    'day' => $day['day'],
                    'start_time' => \Carbon\Carbon::parse($day['start_time']),
                    'end_time' => \Carbon\Carbon::parse($day['end_time']),
                ];
            });

            $number_weeks = (int) ($validated['number_weeks'] ?? 0);
            $number_months = (int) ($validated['number_months'] ?? 0);
            $lastDay = $chosenDays->first();
            $expiry_day = $lastDay['end_time']->copy()->addWeeks($number_weeks)->addMonths($number_months);

            // Verify payment
            $paymentInfo = $this->verifyPaymentWithPaystack($validated['reference']);
            

            if (!$paymentInfo || $paymentInfo['status'] !== 'success') {
                return response()->json(['error' => 'Payment verification failed', 'message' => $paymentInfo['status'] ?? 'unknown'], 422);
            }

            // Check booking reference and ensure no existing booking
            $bookingRef = BookedRef::where('booked_ref', $paymentInfo['reference'])
                ->leftJoin('book_spots', 'booked_refs.id', '=', 'book_spots.booked_ref_id')
                ->select('booked_refs.id')
                ->whereNull('book_spots.id')
                ->first();

            if (!$bookingRef) {
                return response()->json(['error' => 'Booking not initiated or already exists'], 422);
            }
            $bookingRefId = $bookingRef->id;

            // Check for conflicts
            $hasConflicts = ReservedSpots::where('spot_id', $validated['spot_id'])
                ->whereIn('day', $chosenDays->pluck('day'))
                ->where('expiry_day', '>=', \Carbon\Carbon::now())
                ->where(function ($query) use ($chosenDays) {
                    $chosenDays->each(function ($day) use ($query) {
                        $query->orWhere(function ($subQuery) use ($day) {
                            $subQuery->where('day', $day['day'])
                                     ->where('start_time', '<', $day['end_time'])
                                     ->where('end_time', '>', $day['start_time']);
                        });
                    });
                })
                ->exists();

            if ($hasConflicts) {
                $chosenDaysByDay = $chosenDays->keyBy('day');
                $reservedSpots = ReservedSpots::where('spot_id', $validated['spot_id'])
                    ->whereIn('day', $chosenDays->pluck('day'))
                    ->where('expiry_day', '>=', \Carbon\Carbon::now())
                    ->where(function ($query) use ($chosenDays) {
                        $chosenDays->each(function ($day) use ($query) {
                            $query->orWhere(function ($subQuery) use ($day) {
                                $subQuery->where('day', $day['day'])
                                         ->where('start_time', '<', $day['end_time'])
                                         ->where('end_time', '>', $day['start_time']);
                            });
                        });
                    })
                    ->get();

                $conflicts = $reservedSpots->map(function ($spot) use ($chosenDaysByDay) {
                    $matchingDay = $chosenDaysByDay->get($spot->day);
                    if ($matchingDay && (
                        $spot->start_time < $matchingDay['end_time'] &&
                        $spot->end_time > $matchingDay['start_time']
                    )) {
                        return [
                            'day' => $spot->day,
                            'end_time' => \Carbon\Carbon::parse($spot->end_time)->toDateTimeString(),
                        ];
                    }
                    return null;
                })->filter()->values();

                $conflictMessages = $conflicts->map(function ($conflict) {
                    return "Day: {$conflict['day']}, reserved until {$conflict['end_time']}";
                })->implode('; ');

                return response()->json([
                    'message' => 'This workspace is already reserved during the selected time',
                    'conflicts' => $conflicts->isNotEmpty() ? $conflictMessages : 'Specific conflict details unavailable',
                ], 422);
            }

            return DB::transaction(function () use ($validated, $bookingRefId, $paymentInfo, $chosenDays, $expiry_day) {
                // Batch insert ReservedSpots
                $reservedSpotsData = $chosenDays->map(function ($day) use ($validated, $expiry_day) {
                    return [
                        'user_id' => $validated['user_id'],
                        'spot_id' => $validated['spot_id'],
                        'day' => $day['day'],
                        'start_time' => $day['start_time'],
                        'end_time' => $day['end_time'],
                        'expiry_day' => $expiry_day,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->toArray();
                ReservedSpots::insert($reservedSpotsData);

                // Create BookSpot
                $bookSpot = BookSpot::create([
                    'spot_id' => $validated['spot_id'],
                    'user_id' => $validated['user_id'],
                    'booked_by_user' => $validated['user_id'],
                    'type' => $validated['type'],
                    'chosen_days' => $validated['type'] === 'recurrent' ? json_encode($validated['chosen_days']) : null,
                    'fee' => $paymentInfo['amount'] / 100,
                    'invoice_ref' => $paymentInfo['reference'],
                    'booked_ref_id' => $bookingRefId,
                    'number_weeks' => $validated['number_weeks'] ?? 1,
                    'number_months' => $validated['number_months'] ?? 1,
                    'expiry_day' => $expiry_day,
                ]);

                // Update SpacePaymentModel
                $updated = SpacePaymentModel::where('payment_ref', $paymentInfo['reference'])->update([
                    'amount' => $paymentInfo['amount'] / 100,
                    'payment_status' => 'completed',
                ]);

                if ($updated === 0) {
                    throw new \Exception('Payment record not found or already updated');
                }

                return response()->json([
                    'message' => 'Payment confirmed and spot booked successfully',
                    'data' => $bookSpot,
                ], 201);
            });
        } catch (Exception $e) {
            Log::error('Payment confirmation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'server_error',
                'message' => 'An unexpected error occurred. Please try again later.'.$e->getMessage()
            ], 500);
        }
    }
    
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
            return $result['data'] ?? null;
    
        } catch (Exception $e) {
            Log::error("Paystack verification failed: " . $e->getMessage());
            return null;
        } finally {
            curl_close($ch);
        }
    }
    
}


