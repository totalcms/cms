<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Factory\LoggerFactory;

/**
 * Twig sub-adapter for data field access.
 *
 * Accessed in Twig as `cms.data.*`.
 */
readonly class DataTwigAdapter
{
	private LoggerInterface $logger;

	public function __construct(
		private ObjectFetcher $objectFetcher,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');
	}

	/**
	 * Get a raw data property from an object.
	 */
	public function raw(string $collection, string $id, string $property): mixed
	{
		$object = $this->object($collection, $id);

		if (array_key_exists($property, $object)) {
			return $object[$property];
		}

		$this->logger->debug("Property '{$property}' not found on object '{$id}' in collection '{$collection}'");

		return '';
	}

	/** @param array<string,string> $options */
	public function text(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'text',
			'property'   => 'text',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function code(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'code',
			'property'   => 'code',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function styledtext(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'styledtext',
			'property'   => 'styledtext',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function toggle(string $id, array $options = []): bool
	{
		$options = array_merge([
			'collection' => 'toggle',
			'property'   => 'status',
		], $options);

		return boolval($this->raw($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function date(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'date',
			'property'   => 'date',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function color(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'color',
			'property'   => 'color',
		], $options);

		$color = $this->raw($options['collection'], $id, $options['property']);

		if (!is_array($color)) {
			return [];
		}

		return $color;
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function colour(string $id, array $options = []): array
	{
		return $this->color($id, $options);
	}

	/**
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	public function image(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$image = $this->raw($options['collection'], $id, $options['property']);

		if (!is_array($image)) {
			return [];
		}

		return $image;
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<mixed>
	 */
	public function gallery(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$gallery = $this->raw($options['collection'], $id, $options['property']);

		if (!is_array($gallery)) {
			return [];
		}

		return $gallery;
	}

	/**
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	public function file(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'file',
			'property'   => 'file',
		], $options);

		$file = $this->raw($options['collection'], $id, $options['property']);

		if (!is_array($file)) {
			return [];
		}

		return $file;
	}

	/**
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	public function depot(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'depot',
			'property'   => 'depot',
		], $options);

		$depot = $this->raw($options['collection'], $id, $options['property']);

		if (!is_array($depot)) {
			return [];
		}

		return $depot;
	}

	/** @param array<string,string> $options */
	public function svg(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'svg',
			'property'   => 'svg',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param array<string,string> $options
	 */
	public function email(string $id, array $options = [], bool $obfuscate = false): string
	{
		$options = array_merge([
			'collection' => 'email',
			'property'   => 'email',
		], $options);

		$email = strval($this->raw($options['collection'], $id, $options['property']));

		return $obfuscate ? HTMLUtils::htmlencode($email) : $email;
	}

	/** @param array<string,string> $options */
	public function url(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'url',
			'property'   => 'url',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function number(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'number',
			'property'   => 'number',
		], $options);

		return strval($this->raw($options['collection'], $id, $options['property']));
	}

	/**
	 * Get an object from a collection.
	 *
	 * @return array<string,mixed>
	 */
	public function object(string $collection, string $id): array
	{
		try {
			$object = $this->objectFetcher->fetchObject($collection, $id);
		} catch (\Exception $e) {
			$this->logger->warning("Object '{$id}' not found in collection '{$collection}'", ['error' => $e->getMessage()]);

			return [];
		}

		return $object->toArray();
	}
}
