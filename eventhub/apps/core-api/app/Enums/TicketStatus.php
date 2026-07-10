<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid     = 'valid';
    case CheckedIn = 'checked_in';
    case Voided    = 'voided';
}
