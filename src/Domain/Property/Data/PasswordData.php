<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class PasswordData extends PropertyData
{
	public string $hash = '';

	public function __construct(string $password, public array $settings = [])
	{
		if (!empty($password)) {
			// Only set the hash if a password is defined
			$this->hash = self::isPasswordHash($password) ? $password : self::hashPassword($password);
		}
	}

	private static function hashPassword(string $password): string
	{
		return password_hash($password, PASSWORD_DEFAULT);
	}

	private static function isPasswordHash(string $password): bool
	{
		$info = password_get_info($password);

		// Verify that this is a known hash
		return !is_null($info['algo']);
	}

	public function transform(): string
	{
		// TODO: How can we always store the password in the CMS but not expose it in the API?
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->hash;
	}
}
