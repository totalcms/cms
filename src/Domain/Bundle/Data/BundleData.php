<?php

namespace TotalCMS\Domain\Bundle\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class BundleData
{
	/** @var array<string,string> */
	public array $bundle;
	private readonly Serializer $serializer;

	public function __construct()
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	public function __toString(): string
	{
		return $this->toJson();
	}

	public function toJson(): string
	{
		return $this->serializer->serialize($this->bundle, 'json');
	}

	/** @param array<string,string> $data */
	public static function fromArray(array $data): BundleData
	{
		$bundleData         = new BundleData();
		$bundleData->bundle = $data;

		return $bundleData;
	}
}
