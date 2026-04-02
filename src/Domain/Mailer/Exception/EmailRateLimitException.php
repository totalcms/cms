<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Exception;

/**
 * Thrown when an email job cannot be processed due to rate limits.
 * The job should be reset to pending without counting as a failure.
 */
class EmailRateLimitException extends \RuntimeException
{
}
