<?php

namespace App\Enums;

enum OrderStatus: string
{
    case AwaitingPayment   = 'awaiting_payment';
    case Paid              = 'paid';
    case Failed            = 'failed';
    case Expired           = 'expired';
    case PaymentReview     = 'payment_review';
    case RefundPending     = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded          = 'refunded';
}
