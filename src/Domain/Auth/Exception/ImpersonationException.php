<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Exception;

/** Thrown when an impersonation request fails a guard (authz, target, nesting). */
final class ImpersonationException extends \RuntimeException
{
}
