<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Exception;

/**
 * Thrown when a login fails because the identifier matched no account or the
 * password was wrong. The two cases are deliberately indistinguishable — the
 * message never says which, and never names the account — so the login form
 * cannot be used to find out which addresses have accounts. The detail goes
 * to the login log instead.
 */
class InvalidCredentialsException extends \RuntimeException
{
	public function __construct()
	{
		parent::__construct('Invalid login credentials');
	}
}
