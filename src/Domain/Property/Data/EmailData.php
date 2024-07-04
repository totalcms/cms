<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Email type property data.
 */
class EmailData extends PropertyData
{
	public string $email;

	public function __construct(string $email)
	{
		$this->email = self::cleanEmail($email);
	}

	private static function cleanEmail(string $email): string
	{
		if (empty($email)) {
			return $email;
		}

		$email = filter_var($email, FILTER_SANITIZE_EMAIL);

		if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Invalid email');
		}

		return $email;
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->email;
	}
}
