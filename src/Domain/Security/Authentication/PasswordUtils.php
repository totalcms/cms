<?php

namespace TotalCMS\Domain\Security\Authentication;

use TotalCMS\Domain\Property\Data\PasswordData;

/**
 * Password Utilities.
 */
class PasswordUtils
{
	public static function verify(string $password, PasswordData $passwordData): bool
	{
		return password_verify($password, (string)$passwordData);
	}
}
