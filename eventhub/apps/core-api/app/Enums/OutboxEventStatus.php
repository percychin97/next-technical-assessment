<?php

namespace App\Enums;

enum OutboxEventStatus: string
{
    case Pending   = 'pending';
    case Published = 'published';
    case Failed    = 'failed';
}
