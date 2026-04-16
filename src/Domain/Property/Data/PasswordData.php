<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class PasswordData extends PropertyData implements \Stringable
{
	public string $hash = '';

	public function __construct(string $password = '', public array $settings = [])
	{
		if ($password !== '') {
			// Only set the hash if a password is defined
			$this->hash = $this->isPasswordHash($password) ? $password : $this->hashPassword($password);
		}
	}

	private function hashPassword(string $password): string
	{
		return password_hash($password, PASSWORD_DEFAULT);
	}

	private function isPasswordHash(string $password): bool
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
