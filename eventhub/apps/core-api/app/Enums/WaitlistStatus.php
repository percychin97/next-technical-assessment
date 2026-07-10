<?php

namespace App\Enums;

enum WaitlistStatus: string
{
    case Active    = 'active';
    case Notified  = 'notified';
    case Expired   = 'expired';
    case Fulfilled = 'fulfilled';
}
