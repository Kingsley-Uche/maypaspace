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
            $validator = Validator::make($request->all(), [
                'spot_id'         => 'required|numeric|exists:spots,id',
                'company_name'    => 'required|string|max:255',
                'first_name'      => 'required|string|max:255',
                'last_name'       => 'required|string|max:255',
                'email'           => 'required|email|max:255',
                'phone'           => [
                    'required',
                    'unique:users,phone',
                    'regex:/^([0-9\s\-\+\(\)]*)$/',
                    'max:20'
                ],
                'type'            => 'required|in:one-off,recurrent',
                'number_weeks'    => 'required_if:type,recurrent|numeric|min:1|max:3',
                'number_months'   => 'required_if:type,recurrent|numeric|min:0|max:12',
                'chosen_days'     => 'required_if:type,recurrent|array',
                'chosen_days.*.day'        => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
                'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
                'chosen_days.*.end_time'   => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
            ], [
                'phone.required' => 'The phone number is required.',
                'phone.unique'   => 'This phone number is already registered. Please login.',
                'phone.regex'    => 'The phone number format is invalid.',
                'phone.max'      => 'The phone number may not be greater than 20 characters.',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $validatedData = $validator->validated();
            $validatedData['phone'] = preg_replace('/[^0-9+\-() ]/', '', $validatedData['phone']);
            $email = $validatedData['email'];
            $number_days = count($validatedData['chosen_days']);
    
            $tenant_available = TimeSetUpModel::join('tenants', 'time_set_ups.tenant_id', '=', 'tenants.id')
                ->where('tenants.slug', $slug)
                ->select('tenants.id as tenant_id', 'time_set_ups.open_time', 'time_set_ups.day', 'time_set_ups.close_time')
                ->get();
    
            if ($tenant_available->isEmpty()) {
                return response()->json(['message' => 'Workspace not available for the chosen time'], 404);
            }
    
            $tenant = Spot::where('spots.id', $validatedData['spot_id'])
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
                    'categories.min_duration',
                )
                ->first();
    
            if (!$tenant) {
                return response()->json(['message' => 'Spot not found'], 404);
            }
    
            $total_duration = 0;
    
            if ($validatedData['type'] === 'recurrent') {
                foreach ($validatedData['chosen_days'] as $day) {
                    $startTime = \Carbon\Carbon::parse($day['start_time']);
                    $endTime   = \Carbon\Carbon::parse($day['end_time']);
                    $total_duration += $startTime->diffInHours($endTime);
                }
    
                if (
                    ($validatedData['number_weeks'] ?? 0) > 1 &&
                    ($validatedData['number_months'] ?? 0) === 0 &&
                    $tenant->booking_type === 'monthly'
                ) {
                    return response()->json(['message' => 'This space is only available for monthly booking'], 422);
                }
    
                $availableDays = $tenant_available->keyBy(fn ($item) => strtolower($item->day));
    
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
    
                    if (
                        $startTime->format('H:i') < $openTime->format('H:i') ||
                        $endTime->format('H:i') > $closeTime->format('H:i')
                    ) {
                        return response()->json([
                            'message' => "This workspace is not available during the selected time on {$day['day']}"
                        ], 422);
                    }
                }
    
                $validatedData['chosen_days'] = json_encode($validatedData['chosen_days']);
            } else {
                $validatedData['recurrence'] = null;
    
                foreach ($validatedData['chosen_days'] as $day) {
                    $startTime = \Carbon\Carbon::parse($day['start_time']);
                    $endTime   = \Carbon\Carbon::parse($day['end_time']);
                    $total_duration += $startTime->diffInHours($endTime);
                }
    
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
    
            if (\App\Models\User::where('email', $validatedData['email'])->exists()) {
                return response()->json(['message' => 'Email already registered. Please log in'], 422);
            }
    
            $validatedData['user_type_id'] = 3;
    
            $userController = new UserContrl();
            $user = $userController->create_visitor_user($validatedData, (object)[
                'id'      => $tenant->tenant_id,
                'spot_id' => $tenant->id,
                'slug'    => $slug,
            ]);
    
            // --------------------------
            // Booking amount calculation
            // --------------------------
            $amount = 0;
            $number_weeks  = $validatedData['number_weeks'] ?? 1;
            $number_months = $validatedData['number_months'] ?? 1;
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
                    $total = $tenant->space_fee * $total_duration;
                    if ($tenant->min_space_discount_time < $total_duration) {
                        $total -= ($total * ($tenant->space_discount / 100));
                    }

                    break;
    
                case 'daily':
                    $weeks = $number_weeks ?? 1;
                    $months = $number_months ?? 1;
                    $total_days = $number_days * $weeks * $months;
                    $total = $tenant->space_fee * $total_days;
                    if ($tenant->min_space_discount_time < $total_days) {
                        $total -= ($total * ($tenant->space_discount / 100));
                    }
                    break;
    
                default:
                    $total = 0;
            }
    
            $amount = $total * 100;

            $payment_data = $this->initializePaystackPayment($email,$amount,$slug);
            if( $payment_data['data']['authorization_url']&&  $payment_data['data']['reference']){
                
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
            }
            
          
            return response()->json([
                'user' => $user,
                'amount' => $amount,
                'url'=> $payment_data['data']['authorization_url'],
                'payment_ref' => $payment_data['data']['reference'],
                'access_code'=> $payment_data['data']['access_code'],
                'message' => 'Booking initialized successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'internal_error',
                'message' => $e->getMessage()
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
                'spot_id'         => 'required|numeric|exists:spots,id',
                'company_name'    => 'required|string|max:255',
                'first_name'      => 'required|string|max:255',
                'last_name'       => 'required|string|max:255',
                'email'           => 'required|email|max:255',
                'reference'       => 'required|string|max:800',
                'user_id'         => 'required|numeric|exists:users,id',
                'phone'           => [
                    'required', 'exists:users,phone',
                    'regex:/^([0-9\s\-\+\(\)]*)$/', 'max:20'
                ],
                'type'            => 'required|in:one-off,recurrent',
                'number_weeks'    => 'required_if:type,recurrent|numeric|min:1|max:3',
                'number_months'   => 'required_if:type,recurrent|numeric|min:0|max:12',
                'chosen_days'     => 'required_if:type,recurrent|array',
                'chosen_days.*.day'        => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
                'chosen_days.*.start_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:now',
                'chosen_days.*.end_time'   => 'required|date_format:Y-m-d H:i:s|after:chosen_days.*.start_time',
            ], [
                'phone.required' => 'The phone number is required.',
                'phone.regex'    => 'The phone number format is invalid.',
                'phone.max'      => 'The phone number may not be greater than 20 characters.',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $validated = $validator->validated();
            $reference = strip_tags($validated['reference']);
    
            // Find booking reference early
           

            // Verify payment
            $paymentInfo = $this->verifyPaymentWithPaystack($reference);
            if (!$paymentInfo || $paymentInfo['status'] !== 'success') {
                return response()->json(['error' => 'Payment verification failed', 'message' => $paymentInfo['status'] ?? 'unknown'], 422);
            }
    
            

            $bookingRefId = BookedRef::where('booked_ref', $paymentInfo['reference'])
            ->value('id');
            if (!$bookingRefId) {
                return response()->json(['error' => 'Booking not initiated'], 422);
            }
            if(bookSpot::where('booked_ref_id', $bookingRefId)->exists()){
                return response()->json(['error' => 'Booking already exists'], 422);

            }
            return DB::transaction(function () use ($validated, $bookingRefId, $paymentInfo) {
    
                $bookSpot = BookSpot::create([
                    'spot_id'        => $validated['spot_id'],
                    'user_id'        => $validated['user_id'],
                    'booked_by_user' => $validated['user_id'],
                    'type'           => $validated['type'],
                    'chosen_days'    => $validated['type'] === 'recurrent' ? $validated['chosen_days'] : null,
                    'fee'            => $paymentInfo['amount'] / 100,
                    'invoice_ref'    => $paymentInfo['reference'],
                    'booked_ref_id'  =>  $bookingRefId,
                    'number_weeks'   => $validated['number_weeks'] ?? 1,
                    'number_months'  => $validated['number_months'] ?? 1,
                ]);
    
                SpacePaymentModel::where('payment_ref', $paymentInfo['reference'])->update([
                    'amount'         => $paymentInfo['amount'] / 100,
                    'payment_status' => 'completed',
                ]);
    
                return response()->json([
                    'message' => 'Payment confirmed and spot booked successfully',
                    'data'    => $bookSpot,
                ], 201);
            });
    
        } catch (Exception $e) {
            return response()->json([
                'error'   => 'server_error',
                'message' => 'An error occurred: ' . $e->getMessage(),
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


