<?php

namespace App\Enums;

enum RefundRequestStatus: string
{
    case Requested  = 'requested';
    case Approved   = 'approved';
    case Denied     = 'denied';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
