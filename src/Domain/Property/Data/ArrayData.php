<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Array type property data.
 */
class ArrayData extends PropertyData implements \Stringable
{
	/** @var array<string> */
	public array $data;

	/** @param array<string>|string $data */
	public function __construct(array|string $data = [], public array $settings = [])
	{
		// Convert string input to array if needed
		if (is_string($data)) {
			$data = $data === '' ? [] : explode(',', $data);
		}

		$this->data = $this->repairdata($data);
	}

	/**
	 * @param array<mixed> $data
	 *
	 * @return array<string>
	 * */
	private function repairdata(array $data): array
	{
		$data = array_filter($data);
		$data = array_values($data);

		if (!$this->verifydata($data)) {
			throw new \InvalidArgumentException('data must be an array list:' . json_encode($data));
		}

		return $data;
	}

	/** @param array<mixed> $data */
	private function verifydata(array $data): bool
	{
		return array_is_list($data);
	}

	/** @return array<mixed> */
	public function transform(): array
	{
		return $this->data;
	}

	public function __toString(): string
	{
		return implode(',', $this->data);
	}
}
