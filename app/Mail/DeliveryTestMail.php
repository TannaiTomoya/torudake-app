<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 送信の設定を確かめるための試し送り（torudake:mail-check --to。#39）。
 */
class DeliveryTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: '【'.config('app.name').'】メールの試し送り');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.delivery-test', with: [
            'sentAt' => now()->isoFormat('M月D日(ddd) HH:mm'),
            'loginUrl' => route('login'),
        ]);
    }
}
