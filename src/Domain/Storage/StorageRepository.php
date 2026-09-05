<?php

namespace TotalCMS\Domain\Storage;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Storage\Exception\CorruptedStorageFileException;

/**
 * Repository.
 */
abstract class StorageRepository
{
	protected Serializer $serializer;

	public const FILE_EXT = '.json';

	/**
	 * The constructor.
	 *
	 * @param StorageFilesystemAdapter $filesystem The filesystem factory
	 */
	public function __construct(protected StorageAdapterInterface $filesystem)
	{
		$this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
	}

	/**
	 * fetch and deserialize a file.
	 *
	 * @template CLASS of object
	 *
	 * @param class-string<CLASS> $className
	 *
	 * @return CLASS|null
	 */
	protected function fetchAndDeserialize(string $file, string $className): ?object
	{
		$contents = null;

		if ($this->filesystem->fileExists($file)) {
			$contents = $this->filesystem->read($file);
		}

		if ($contents === null || $contents === '') {
			return null;
		}

		// A hand-edited or badly-imported file is the user's problem, but it must
		// not surface as a vendor "Syntax error" from whatever happened to touch
		// it first — that names neither the file nor the cause, and it escapes
		// through callers that cannot fail (see SchemaRepository::schemaExists).
		try {
			$object = $this->serializer->deserialize($contents, $className, 'json');
		} catch (NotEncodableValueException $e) {
			throw new CorruptedStorageFileException($file, $e->getMessage(), $e);
		}

		if ($object instanceof $className) {
			return $object;
		}

		return null;
	}
}
