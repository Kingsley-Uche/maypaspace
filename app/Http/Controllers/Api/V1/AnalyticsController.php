<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\BookSpot;
use App\Models\Tenant;

class AnalyticsController extends Controller
{
    public function index(Request $request, $tenant_slug){

        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug, $user);

        $startTimeA = $request->query('startTimeA');
        $endTimeA = $request->query('endTimeA');

        $startTimeB = $request->query('startTimeB');
        $endTimeB = $request->query('endTimeB');

        $days = $request->query('days');
        
        $categoryId = $request->query('categoryId');

        $spotId = $request->query('spotId');
        
        $filterStartDateA = Carbon::parse($startTimeA);
        $filterEndDateA = Carbon::parse($endTimeA);

        $filterStartDateB = Carbon::parse($startTimeB);
        $filterEndDateB = Carbon::parse($endTimeB);

        if($spotId && !$days){
           $resultsA = $this->fetchFilterDataWithSpot($filterStartDateA, $filterEndDateA, $categoryId, $spotId, $tenant->id);

           $resultsB = $this->fetchFilterDataWithSpot($filterStartDateB, $filterEndDateB, $categoryId, $spotId, $tenant->id); 
        }elseif($spotId && $days){
           $resultsA = $this->fetchFilterDataWithSpot($filterStartDateA, $filterEndDateA, $categoryId, $spotId, $tenant->id);

           $resultsB = $this->fetchFilterDataWithSpot($filterStartDateB, $filterEndDateB, $categoryId, $spotId, $tenant->id); 
        }elseif($days && !$spotId){
            $resultsA = $this->fetchFilterData($filterStartDateA, $filterEndDateA, $categoryId, $tenant->id);

            $resultsB = $this->fetchFilterData($filterStartDateB, $filterEndDateB, $categoryId, $tenant->id);
        }
        else{
           $resultsA = $this->fetchFilterData($filterStartDateA, $filterEndDateA, $categoryId, $tenant->id);

           $resultsB = $this->fetchFilterData($filterStartDateB, $filterEndDateB, $categoryId, $tenant->id);
        }

        $summedDataA = $this->loopResults($resultsA, $filterStartDateA, $filterEndDateA, $days);

        $summedDataB = $this->loopResults($resultsB, $filterStartDateB, $filterEndDateB, $days);

        $summingBooking = $this->statGetter($summedDataA['totalBooking'], $summedDataB['totalBooking']);

        $summingHour = $this->statGetter($summedDataA['totalHours'], $summedDataB['totalHours']);



        $allData = 
                [
                    'booking' => [
                        'bookingA' => $summedDataA['totalBooking'],
                        'bookingB' => $summedDataB['totalBooking'],
                        'percentage' => $summingBooking
                    ],
                    'hour' => [
                        'hourA' => $summedDataA['totalHours'],
                        'hourB' => $summedDataB['totalHours'],
                        'percentage' => $summingHour
                    ]
                ];

        return response()->json($allData);

    }

    //Fetch filter data

    private function fetchFilterData($filterStartDate, $filterEndDate, $categoryId, $tenant_id){

        $results = BookSpot::select('id', 'spot_id', 'booked_ref_id', 'type', 'chosen_days', 'start_time', 'expiry_day')
        ->where('tenant_id', $tenant_id)
        ->whereHas('spot.space.category', function ($query) use ($categoryId) {
            $query->where('id', $categoryId);
        })
        ->where(function ($query) use ($filterStartDate, $filterEndDate) {
            $query->whereBetween('start_time', [$filterStartDate, $filterEndDate])
                ->orWhereBetween('expiry_day', [$filterStartDate, $filterEndDate])
                ->orWhere(function ($query) use ($filterStartDate, $filterEndDate) {
                    $query->where('start_time', '<', $filterStartDate)
                            ->where('expiry_day', '>', $filterEndDate);
                });
        })
        ->with([
            'spot.space.category',
            'bookedRef'
        ])
        ->get();

        return $results;
    }

    private function fetchFilterDataWithSpot($filterStartDate, $filterEndDate, $categoryId, $spotId, $tenant_id){
                
        $results = BookSpot::select('id', 'spot_id', 'type', 'chosen_days', 'start_time', 'expiry_day')
        ->where('spot_id', $spotId)
        ->where('tenant_id', $tenant_id)
        ->whereHas('spot.space.category', function ($query) use ($categoryId) {
            $query->where('id', $categoryId);
        })
        ->where(function ($query) use ($filterStartDate, $filterEndDate) {
            $query->whereBetween('start_time', [$filterStartDate, $filterEndDate])
                ->orWhereBetween('expiry_day', [$filterStartDate, $filterEndDate])
                ->orWhere(function ($query) use ($filterStartDate, $filterEndDate) {
                    $query->where('start_time', '<', $filterStartDate)
                            ->where('expiry_day', '>', $filterEndDate);
                });
        })
        ->with([
            'spot.space.category',
        ])
        ->get();
        
        return $results;
    }

    private function loopResults($results, $filterStartDate, $filterEndDate, $days){
        $totalHours = 0;
        $totalBooking = 0;

        if(!$days){
            foreach($results as $result){

                if($result->type != "recurrent"){
                    $datetime1 = Carbon::parse($result->expiry_day);
                    $data = json_decode($result->chosen_days, true);

                    foreach($data as $datum){
                        $start = Carbon::parse($datum['start_time']);

                        // Parse expiry_day with the extracted timezone
                        $expiry = Carbon::parse($datum['end_time']);

                        // Now both are in the same timezone
                        $duration = $start->diffInHours($expiry);

                        if($start->between($filterStartDate, $filterEndDate)){
                            $totalBooking++;
                            $totalHours += $duration;

                        }else{
                            $totalBooking = $totalBooking + 0;
                            $totalHours += 0;
                        }

                    } //end of foreach
                }else{
                    $datetime1 = Carbon::parse($result->expiry_day);
                    $datetime2 = Carbon::parse($result->start_time);

                    $data = json_decode($result->chosen_days, true);

                    //$diffInDays = $datetime1->diffInDays($datetime2, true);

                    foreach($data as $datum){
                         //$currentDate = $datum['start_time'];

                        $start = Carbon::parse($datum['start_time']);

                        // Parse expiry_day with the extracted timezone
                        $expiry = Carbon::parse($datum['end_time']);

                        $weekday = $datum['day'];

                        // Now both are in the same timezone
                        $duration = $start->diffInHours($expiry);

                        for($i=0; $i < 5; $i++){
                            if($datetime1->greaterThan($start)){
                                if($start->between($filterStartDate, $filterEndDate)){
                                    $totalBooking++;
                                    $totalHours += $duration; 
                                }
                            }

                            $start = $start->addDays(7);
                        }
                    } //end of data foreach

                }//end of if else for result type check
            } // end of results foreach
        }else{
            foreach($results as $result){
                if($result->type != "recurrent"){
                    $datetime1 = Carbon::parse($result->expiry_day);
                    $data = json_decode($result->chosen_days, true);

                    foreach($data as $datum){
                        $start = Carbon::parse($datum['start_time']);

                        // Parse expiry_day with the extracted timezone
                        $expiry = Carbon::parse($datum['end_time']);

                        $weekday = $datum['day'];

                        $checkWeekDay[] = $weekday;
                        $checkWeekDay[] = in_array($weekday, $days);

                        // Now both are in the same timezone
                        $duration = $start->diffInHours($expiry);

                        

                        if(in_array($weekday, $days) && $start->between($filterStartDate, $filterEndDate)){
                            $totalBooking++;
                            $totalHours += $duration; 
                             $checkWeekDay[] = in_array($weekday, $days);

                        }else{
                            $totalBooking = $totalBooking + 0;
                            $totalHours += 0;
                        }

                    } //end of foreach
                }else{
                    $datetime1 = Carbon::parse($result->expiry_day);
                    $datetime2 = Carbon::parse($result->start_time);

                    $data = json_decode($result->chosen_days, true);

                    //$diffInDays = $datetime1->diffInDays($datetime2, true);

                    foreach($data as $datum){
                         //$currentDate = $datum['start_time'];

                        $start = Carbon::parse($datum['start_time']);

                        // Parse expiry_day with the extracted timezone
                        $expiry = Carbon::parse($datum['end_time']);

                        $weekday = $datum['day'];

                        $checkWeekDay[] = $weekday;
                         $checkWeekDay[] = in_array($weekday, $days);

                        // Now both are in the same timezone
                        $duration = $start->diffInHours($expiry);

                        for($i=0; $i < 5; $i++){
                            if(in_array($weekday, $days) && $datetime1->greaterThan($start)){
                                if($start->between($filterStartDate, $filterEndDate)){
                                    $totalBooking++;
                                    $totalHours += $duration; 
                                }else{
                                        $totalBooking = $totalBooking + 0;
                                        $totalHours += 0;
                                }
                            }else{
                                    $totalBooking = $totalBooking + 0;
                                    $totalHours += 0;
                            }

                            $start = $start->addDays(7);
                        }
                    } //end of data foreach

                }//end of if else for result type check
            
        } //end of days if
        
    }
    $summedData = ['totalBooking' => $totalBooking, 'totalHours' => $totalHours];

    return $summedData;
}

    public function indexPayment(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug, $user);

        $startTimeA = $request->query('startTimeA');
        $endTimeA = $request->query('endTimeA');

        $startTimeB = $request->query('startTimeB');
        $endTimeB = $request->query('endTimeB');

        $filterStartDateA = Carbon::parse($startTimeA);
        $filterEndDateA = Carbon::parse($endTimeA);

        $filterStartDateB = Carbon::parse($startTimeB);
        $filterEndDateB = Carbon::parse($endTimeB);

        $resultsA = $this->fetchFilterPayment($filterStartDateA, $filterEndDateA, $tenant->id);

        $resultsB = $this->fetchFilterPayment($filterStartDateB, $filterEndDateB, $tenant->id);

        $dataA = $this->fetchLoopPayment($resultsA, $filterStartDateA, $filterEndDateA);

        $dataB = $this->fetchLoopPayment($resultsB, $filterStartDateB, $filterEndDateB);

        $durationStat = $this->statGetter($dataA['totalAmountForDuration'], $dataB['totalAmountForDuration']);
        $accountStat = $this->statGetter($dataA['totalAccountProcessed'], $dataB['totalAccountProcessed']);
        

        $summedData = [
            'duration' =>
            [
                'totalAmountForDurationA' => round($dataA['totalAmountForDuration'], 2),
                'totalAmountForDurationB' => round($dataB['totalAmountForDuration'], 2),
                'percentage'=> $durationStat
            ],
            'account' => 
            [
                'totalAccountProcessedA' => $dataA['totalAccountProcessed'],
                'totalAccountProcessedB' => $dataB['totalAccountProcessed'],
                'percentage'=> $accountStat
            ]
        ];
    
        return response()->json($summedData);
    }
    //Sort Payments

    private function fetchFilterPayment($filterStartDate, $filterEndDate, $tenant_id){

        $results = BookSpot::where('tenant_id', $tenant_id)
        ->where(function ($query) use ($filterStartDate, $filterEndDate) {
            $query->whereBetween('start_time', [$filterStartDate, $filterEndDate])
                ->orWhereBetween('expiry_day', [$filterStartDate, $filterEndDate])
                ->orWhere(function ($query) use ($filterStartDate, $filterEndDate) {
                    $query->where('start_time', '<', $filterStartDate)
                            ->where('expiry_day', '>', $filterEndDate);
                });
        })
        ->with([
            'invoice'
        ])
        ->get();

        return $results;
    }

    private function fetchLoopPayment($results, $filterStartDate, $filterEndDate){
        $totalAmountForDuration = 0;
        $arrayOfAccounts = [];
        $totalAccountProcessed = 0;

        foreach($results as $result){
            if(!in_array($result->invoice->user_id, $arrayOfAccounts)){
                $arrayOfAccounts[] = $result->invoice->user_id;
                $totalAccountProcessed += 1;
            }

            if($result->type != "recurrent"){
                $data = json_decode($result->chosen_days, true);

                $totalDays = count($data);

                $totalFee = $result->invoice->amount;

                $amountPerDay = $totalFee / $totalDays;

                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);

                    if($start->between($filterStartDate, $filterEndDate)){
                        $totalAmountForDuration += $amountPerDay;
                    }else{
                        $totalAmountForDuration += 0;
                    }

                } //end of foreach
            }else{
                $data = json_decode($result->chosen_days, true);

                $datetime1 = Carbon::parse($result->expiry_day);

                

                $totalDays = 0;

                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);
                    
                    for($i=0; $i < 5; $i++){
                        if($datetime1->greaterThan($start)){
                            
                            $totalDays += 1;
                            
                        }

                        $start = $start->addDays(7);
                    }
                } //end of data foreach

                $totalFee = $result->invoice->amount;

                $amountPerDay = $totalFee / $totalDays;

                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);
                    
                    for($i=0; $i < 5; $i++){
                        if($datetime1->greaterThan($start)){
                            if($start->between($filterStartDate, $filterEndDate)){
                                $totalAmountForDuration += $amountPerDay;
                            }
                        }
                        $start = $start->addDays(7);
                    }
                } //end of data foreach

            }//end of if else for result type check
        } // end of results foreach

        return $summedData = [
            'totalAmountForDuration' => round($totalAmountForDuration, 2),
            'totalAccountProcessed' => $totalAccountProcessed,
        ];
    } //end of fetchLoopPayment

    private function statGetter($numberA, $numberB){

        $sumTotal = $numberA - $numberB;

        if($numberB == 0){
            $total = $sumTotal * 100;
        }else{
            $total = ($sumTotal / $numberB) * 100;
        }

        return $total;
    }

    public function getAccountsAndRevenue(Request $request, $tenant_slug){

        $startDate = $request->query('startTimeA');
        $endDate = $request->query('endTimeA');

        $filterStartDate = Carbon::parse($startDate);
        $filterEndDate = Carbon::parse($endDate);

        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug, $user);

        $results = BookSpot::where('tenant_id', $tenant->id)->select('id', 'type', 'user_id', 'chosen_days', 'expiry_day', 'start_time', 'fee', 'invoice_ref')->where(function ($query) use ($filterStartDate, $filterEndDate) {
            $query->whereBetween('start_time', [$filterStartDate, $filterEndDate])
                ->orWhereBetween('expiry_day', [$filterStartDate, $filterEndDate])
                ->orWhere(function ($query) use ($filterStartDate, $filterEndDate) {
                    $query->where('start_time', '<', $filterStartDate)
                            ->where('expiry_day', '>', $filterEndDate);
                });
        })
        ->with([
            'invoice:id,invoice_ref,amount',
            'user:id,first_name,last_name'
        ])
        ->get();

        $accounts = [];
        
        foreach($results as $result){
            //Initialize User Info Details
            $id = $result->user_id;

            $name = $result->user->first_name.' '.$result->user->last_name;
            $userInfo = ['id'=>$id, 'name'=>$name, 'fee'=>0]; //User Info Gotten
            
            if($result->type !== 'recurrent'){
                $data = json_decode($result->chosen_days, true);
                //Get amount to be paid per day by dividing fee by total amount of days
                $days = count($data);
                $amountPerDay = $result->fee / $days; //Amount Per Day Gotten

                //Looping through the days to ensure it is not past the filter date
                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);

                    if($start->between($filterStartDate, $filterEndDate)){
                        $found = false;

                        foreach ($accounts as &$account) {
                            if ($account['id'] === $id) {
                                $account['fee'] += $amountPerDay;
                                $found = true;
                                break;
                            }
                        }
                        unset($account); // Breaks reference

                        if (!$found) {
                            $userInfo = ['id'=>$id, 'name'=>$name, 'fee'=>$amountPerDay];
                            $accounts[] = $userInfo;
                        }
                    }
                } //end of foreach
            }else{
                $data = json_decode($result->chosen_days, true);

                $datetime1 = Carbon::parse($result->expiry_day);

                

                $totalDays = 0;

                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);
                    
                    for($i=0; $i < 5; $i++){
                        if($datetime1->greaterThan($start)){
                            
                            $totalDays += 1;
                            
                        }

                        $start = $start->addDays(7);
                    }
                } //end of data foreach

                $totalFee = $result->fee;

                $amountPerDay = $totalFee / $totalDays;

                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);
                    
                    for($i=0; $i < 5; $i++){
                        if($datetime1->greaterThan($start)){
                            if($start->between($filterStartDate, $filterEndDate)){
                               $found = false;

                                foreach ($accounts as &$account) {
                                    if ($account['id'] === $id) {
                                        $account['fee'] += $amountPerDay;
                                        $found = true;
                                        break;
                                    }
                                }
                                unset($account); // Breaks reference

                                if (!$found) {
                                    $userInfo = ['id'=>$id, 'name'=>$name, 'fee'=>$amountPerDay];
                                    $accounts[] = $userInfo;
                                } 
                            }
                        }
                        $start = $start->addDays(7);
                    }
                } //end of data foreach
            } //End of result type
        }

        return response()->json($accounts);
    }

    
    public function getAccountsAndRevenueDuplicate(){

        $filterStartDate = Carbon::parse('2025-05-01');
        $filterEndDate = Carbon::parse('2025-07-31');

        $results = BookSpot::select('id', 'type', 'user_id', 'chosen_days', 'expiry_day', 'start_time', 'fee', 'invoice_ref')->where(function ($query) use ($filterStartDate, $filterEndDate) {
            $query->whereBetween('start_time', [$filterStartDate, $filterEndDate])
                ->orWhereBetween('expiry_day', [$filterStartDate, $filterEndDate])
                ->orWhere(function ($query) use ($filterStartDate, $filterEndDate) {
                    $query->where('start_time', '<', $filterStartDate)
                            ->where('expiry_day', '>', $filterEndDate);
                });
        })
        ->with([
            'invoice:id,invoice_ref,amount',
            'user:id,first_name,last_name'
        ])
        ->get();

        $accounts = [['id'=>1, 'name'=>'awesome', 'fee'=>0], ['id'=>2, 'name'=>'emeka', 'fee'=>8]];
        
        foreach($results as $result){
            //Initialize User Info Details
            $id = $result->user_id;

            $found = false;

            foreach ($accounts as &$account) {
                if ($account['id'] === $id) {
                    $account['fee'] += 1;
                    $found = true;
                    break;
                }
            }
            unset($account); // Breaks reference

            if (!$found) {
                $userInfo = ['id'=>$id, 'name'=>'emeka', 'fee'=>1];
                $accounts[] = $userInfo;
            }

            dd($accounts);
            $name = $result->user->first_name.' '.$result->user->last_name;
            $userInfo = ['id'=>$id, 'name'=>$name, 'fee'=>0]; //User Info Gotten
            
            if($result->type !== 'recurrent'){
                $data = json_decode($result->chosen_days, true);
                //Get amount to be paid per day by dividing fee by total amount of days
                $days = count($data);
                $amountPerDay = $result->fee / $days; //Amount Per Day Gotten

                //Looping through the days to ensure it is not past the filter date
                foreach($data as $datum){
                    $start = Carbon::parse($datum['start_time']);

                    if($start->between($filterStartDate, $filterEndDate)){
                       
                    }
                } //end of foreach

                return response()->json($days);
            }
        }

        return response()->json($results);
    }


    private function checkTenant($tenant_slug, $user){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        if($user->user_type_id != 1 && $user->user_type_id != 2){
            return response()->json(['message' => 'You are not authorizeddd'], 403);
        }

        if($user->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        return $tenant;

    }


} //End of controller.
