<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Email type property data.
 */
class EmailData extends PropertyData implements \Stringable
{
	public string $email;

	public function __construct(string $email = '', public array $settings = [])
	{
		$this->email = $this->cleanEmail($email);
	}

	private function cleanEmail(string $email): string
	{
		if ($email === '') {
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
