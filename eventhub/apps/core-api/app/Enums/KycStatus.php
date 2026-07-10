<?php

namespace App\Enums;

enum KycStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
