<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Exception;

/**
 * Thrown when authentication succeeds (password verified) but the account is
 * not active. Distinct from a generic auth failure so the login flow can
 * surface a "resend verification email" link instead of a "wrong password"
 * message.
 */
class AccountNotActiveException extends \RuntimeException
{
}
