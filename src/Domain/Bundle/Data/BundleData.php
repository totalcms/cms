<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Bundle\Data;

use TotalCMS\Domain\Storage\SerializerFactory;

class BundleData implements \Stringable
{
	/** @var array<string,string> */
	public array $bundle;

	public function __toString(): string
	{
		return $this->toJson();
	}

	public function toJson(): string
	{
		return SerializerFactory::json()->serialize($this->bundle, 'json');
	}

	/** @param array<string,string> $data */
	public static function fromArray(array $data): BundleData
	{
		$bundleData         = new BundleData();
		$bundleData->bundle = $data;

		return $bundleData;
	}
}
