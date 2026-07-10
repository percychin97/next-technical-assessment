<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Failed    = 'failed';
}
