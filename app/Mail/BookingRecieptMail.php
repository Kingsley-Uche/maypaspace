<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingRecieptMail extends Mailable
{
    use Queueable, SerializesModels;

    public $receiptData;

    /**
     * Create a new message instance.
     */
    public function __construct($receiptData)
    {

        $this->receiptData = $receiptData;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Booking Receipt')
                    ->view('emails.booking_receipt')
                    ->with([
                        'receiptData' => $this->receiptData,
                    ]);
    }
}
