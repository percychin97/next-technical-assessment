<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft              = 'draft';
    case Published          = 'published';
    case Ongoing            = 'ongoing';
    case Completed          = 'completed';
    case CancellationPending = 'cancellation_pending';
    case Cancelled          = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft              => in_array($next, [self::Published, self::Cancelled]),
            self::Published          => in_array($next, [self::Ongoing, self::CancellationPending, self::Cancelled]),
            self::Ongoing            => $next === self::Completed,
            self::CancellationPending => $next === self::Cancelled,
            self::Completed,
            self::Cancelled          => false,
        };
    }
}
