<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendUserEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $emailContent;
    public $files;

    public function __construct($emailContent, $files = [])
    {
        $this->emailContent = $emailContent;
        $this->files = $files;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailContent['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.emailUser',
        );
    }

    public function build()
    {
        $email = $this->view('emails.emailUser')
            ->with([
                'emailContent' => $this->emailContent,
            ]);

        foreach ($this->files as $file) {
            $email->attach(
                storage_path('app/public/email_attachments/'.$this->emailContent['sentEmailId'].'/'.$file->name),
                [
                    'as' => $file->name,
                ]
            );
        }

        return $email;
    }
}

