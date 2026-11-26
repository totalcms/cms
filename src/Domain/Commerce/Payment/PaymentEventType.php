<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

enum PaymentEventType: string
{
	case Paid        = 'paid';
	case Failed      = 'failed';
	case Expired     = 'expired';
	case Refunded    = 'refunded';
	case Disputed    = 'disputed';
	case DisputeWon  = 'dispute_won';
	case DisputeLost = 'dispute_lost';
}
