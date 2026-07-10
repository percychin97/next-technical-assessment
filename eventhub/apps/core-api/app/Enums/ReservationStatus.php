<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Held      = 'held';
    case Confirmed = 'confirmed';
    case Released  = 'released';
    case Expired   = 'expired';
}
