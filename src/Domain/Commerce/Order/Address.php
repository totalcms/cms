<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Exception\CommerceException;

/**
 * Where an order ships or is billed. Country and region decide tax and
 * shipping; the rest is for the label.
 */
final readonly class Address
{
	private const FIELDS = ['name', 'company', 'line1', 'line2', 'city', 'region', 'postalCode', 'country', 'phone'];

	private const LONGEST = 200;

	public function __construct(
		public string $name = '',
		public string $company = '',
		public string $line1 = '',
		public string $line2 = '',
		public string $city = '',
		public string $region = '',
		public string $postalCode = '',
		public string $country = '',
		public string $phone = '',
	) {
	}

	/**
	 * An address as a form sent it: trimmed, each field held to 200
	 * characters, the country in capitals.
	 *
	 * @param array<mixed> $in
	 *
	 * @throws CommerceException when a country is given that is not a two-letter code
	 */
	public static function fromInput(array $in): self
	{
		$fields = [];
		foreach (self::FIELDS as $field) {
			$value          = $in[$field] ?? '';
			$fields[$field] = is_string($value) || is_int($value) ? mb_substr(trim((string)$value), 0, self::LONGEST) : '';
		}

		$fields['country'] = strtoupper($fields['country']);
		if ($fields['country'] !== '' && preg_match('/^[A-Z]{2}\z/', $fields['country']) !== 1) {
			throw CommerceException::addressInvalid();
		}

		return new self(...$fields);
	}

	public function isEmpty(): bool
	{
		return array_filter($this->toArray(), static fn (string $value): bool => $value !== '') === [];
	}

	/** @return array<string,string> */
	public function toArray(): array
	{
		return [
			'name'       => $this->name,
			'company'    => $this->company,
			'line1'      => $this->line1,
			'line2'      => $this->line2,
			'city'       => $this->city,
			'region'     => $this->region,
			'postalCode' => $this->postalCode,
			'country'    => $this->country,
			'phone'      => $this->phone,
		];
	}
}
