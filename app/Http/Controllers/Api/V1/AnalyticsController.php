<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BookSpot;

class AnalyticsController extends Controller
{
    public function index(){
        $results = BookSpot::select('id', 'spot_id', 'booked_ref_id', 'type', 'chosen_days', 'start_time', 'expiry_day')->whereHas('spot.space.category', function ($query) {
                    $query->where('id', 3); // or use `space_category_id` if it's on space
                })
                ->with([
                    'spot.space.category',
                    'bookedRef'
                ])->get();

        $totalHours = 0;
        $totalBooking = 0;

        $differenceInTime = [];

        foreach($results as $result){
                $data = json_decode($result->chosen_days, true);

                $datetime1 = Carbon::parse($result->expiry_day)->startOfDay();
                $datetime2 = Carbon::parse($result->start_time)->startOfDay();


                if($datetime1->greaterThan($datetime2)){
                   $diffInDays = $datetime1->diffInDays($datetime2, true);

                   $weeks = $diffInDays / 7;

                   if(count($data) > 1){
                        foreach($data as $datum){                        
                            $start = Carbon::parse($datum['start_time']);

                            // Parse expiry_day with the extracted timezone
                            $expiry = Carbon::parse($datum['end_time']);

                            // Now both are in the same timezone
                            $duration = $start->diffInHours($expiry);

                            $sumOfStartAndDays = $start->addDays($diffInDays);

                            if($sumOfStartAndDays->greaterThan($result->expiry_day)){
                               $totalBooking = $totalBooking + ($weeks - 1);
                               
                               $totalHours = $totalHours + 0;
                            }else{
                                $totalBooking++;
                                $totalHours += $duration; 
                            }

                        }
                    }elseif(count($data) <= 1){
                        foreach($data as $datum){
                            $start = Carbon::parse($datum['start_time']);
                            //$timezone = $start->getTimezone();

                            // Parse expiry_day with the extracted timezone
                            $expiry = Carbon::parse($datum['end_time']);

                            // Now both are in the same timezone
                            $duration = $start->diffInHours($expiry);

                            $totalHours += $duration; 
                            $totalBooking++; 
                        }
                        
                    }
                }

                // if(count($data) > 1){
                //     foreach($data as $datum){                        
                //         $start = Carbon::parse($datum['start_time']);

                //         // Parse expiry_day with the extracted timezone
                //         $expiry = Carbon::parse($datum['end_time']);

                //         // Now both are in the same timezone
                //         $duration = $start->diffInHours($expiry);

                //         $totalHours += $duration; 
                //         $totalBooking++; 
                //     }
                // }elseif(count($data) <= 1){
                //     foreach($data as $datum){
                //         $start = Carbon::parse($datum['start_time']);
                //         //$timezone = $start->getTimezone();

                //         // Parse expiry_day with the extracted timezone
                //         $expiry = Carbon::parse($datum['end_time']);

                //         // Now both are in the same timezone
                //         $duration = $start->diffInHours($expiry);

                //         $totalHours += $duration; 
                //         $totalBooking++; 
                //     }
                //      // Parse start_time and get its timezone
                //     // $start = Carbon::parse($result->start_time);
                //     // $timezone = $start->getTimezone();

                //     // // Parse expiry_day with the extracted timezone
                //     // $expiry = Carbon::createFromFormat('Y-m-d H:i:s', $result->expiry_day, $timezone);

                //     // // Now both are in the same timezone
                //     // $duration = $start->diffInHours($expiry);

                //     // $totalHours += $duration; 

                //     // $totalBooking++ ;
                // }
        }

        $summedData = 
                    [
                        'totalBooking' => $totalBooking,
                        'totalHours' => $totalHours,
                        'diffInDays' => $differenceInTime
                    ];

        return response()->json($summedData);
    }

    // public function filterByCategory(Request $request, $category_id){
    //     $

    //     return response()->json($result);
    // }
}
