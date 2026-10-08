<?php

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mail that tells someone what already happened (an application came in, was approved or
 * turned down). The decision stands whether or not the mail server cooperates, so a failed
 * send is logged and reported back as false, never raised.
 */
class QuietMail
{
    public static function send(string $to, Mailable $mail, string $what): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Notification mail failed', ['what' => $what, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
