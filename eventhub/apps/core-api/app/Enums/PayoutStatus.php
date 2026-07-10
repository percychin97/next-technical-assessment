<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Pending    = 'pending';
    case Approved   = 'approved';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
