<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The one JSON serializer the data objects and repositories share.
 *
 * Building a Serializer is not free: every ObjectNormalizer brings its own
 * PropertyAccessor and reflection extractor, and ObjectData used to build
 * one per record, so a listing page constructed hundreds of them to use
 * none. The serializer is stateless apart from reflection caches that are
 * only useful when shared, so one instance per process serves every caller.
 */
final class SerializerFactory
{
	private static ?Serializer $json = null;

	public static function json(): Serializer
	{
		return self::$json ??= new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}
}
