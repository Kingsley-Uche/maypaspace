<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingExpirationReminderMail;
use Carbon\Carbon;

use App\Models\BookSpot;

class CheckBookingExpiration implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //get all bookings with paid invoice
        $bookings = DB::table('book_spots as bs')
            ->join('spots as s', 'bs.spot_id', '=', 's.id')
            ->join('spaces as sp', 's.space_id', '=', 'sp.id')
            ->join('users as u', 'bs.user_id', '=', 'u.id')
            ->join('tenants as t', 'bs.tenant_id', '=', 't.id')
            ->join('invoices as i', function ($join) {
                $join->on('bs.invoice_ref', '=', 'i.invoice_ref')
                    ->where('i.status', '=', 'paid');
            })
            ->select([
                'bs.id',
                'bs.spot_id',
                'bs.user_id',
                'bs.expiry_day',
                'bs.start_time',
                'bs.fee',
                'bs.tenant_id',
                'bs.invoice_ref',

                's.space_id',
                'sp.space_name',

                'u.first_name',
                'u.last_name',
                'u.email',

                't.company_name',

                DB::raw('i.status as invoice_status'),
            ])
        ->get();

        foreach($bookings as $booking){

            //carbon parse the relevant dates
            $expiry = Carbon::parse($booking->expiry_day);
            $start = Carbon::parse($booking->start_time);

            //ensure paid invoice is not expired
            if (!$expiry->isPast()) {

                //find total duration of the booking
                $duration = $start->diffInDays($expiry); 
                
                //find the total days left before expiry of the booking
                $daysLeft = now()->diffInDays($expiry, false);

                $messageData = [
                    'name' => $booking->first_name.' '.$booking->last_name,
                    'space' => $booking->space_name,
                    'expiry' => $booking->expiry_day,
                    'tenant' => $booking->company_name,
                ];

                //if total duration is more than 60days
                if($duration > 60){

                    //if initial booking was for more than 60days and 
                    //days left is > 7 but < 30
                    //and today is monday (when these conditions are met, reminders will be sent every monday)
                    if ($daysLeft > 7 && $daysLeft < 30 && now()->isMonday()) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    }
                    else if ($daysLeft <= 7 && $daysLeft > 2 && $daysLeft % 2 === 0) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    }
                    else if ($daysLeft <= 2) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    }
                }else if($duration <= 60){
                    
                    if ($daysLeft > 7 && $daysLeft < 14 && now()->isMonday()) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    }
                    else if ($daysLeft <= 7 && $daysLeft > 2 && $daysLeft % 2 === 0) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    }
                    else if ($daysLeft <= 2) {
                        Mail::to($booking->email)->queue(new BookingExpirationReminderMail($messageData));
                        \Log::info("booking expiration mail queued for booking {$booking->id}");
                    } 
                }
                
            }
        }


    }
}
