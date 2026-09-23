<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Turn the arguments of a download or stream URL into a {@see PropertyFile}.
 *
 * `/{collection}/{id}/{property}` is a plain file property. With a trailing
 * `{path}` it is either a depot entry named by that path (with the depot
 * subfolder in `?path=`) or, when the path is a real directory under the
 * property, a file nested in a card or deck item. That decision used to be
 * made separately — and slightly differently — in the download and stream
 * depot actions.
 */
final readonly class PropertyFileResolver
{
	public function __construct(
		private FileFetcher $fileFetcher,
		private DepotFileFetcher $depotFetcher,
		private PropertyFetcher $propertyFetcher,
		private ObjectUpdater $objectUpdater,
	) {
	}

	public function file(string $collection, string $id, string $property): PropertyFile
	{
		return $this->make(PropertyFileKind::File, $collection, $id, $property);
	}

	/**
	 * @param string      $rawPath      The `{path}` route argument, URL-encoded as it arrived.
	 * @param string|null $depotSubpath The `?path=` query: the depot subfolder, when any.
	 */
	public function depotOrNested(string $collection, string $id, string $property, string $rawPath, ?string $depotSubpath): PropertyFile
	{
		$sanitized = PathUtils::sanitizeSubpath($rawPath);

		if ($this->fileFetcher->isNestedDirectory($collection, $id, $property, $sanitized)) {
			try {
				$name = $this->fileFetcher->fetchFile($collection, $id, $property, $sanitized)->name;
			} catch (\RuntimeException) {
				// isNestedDirectory() is a heuristic: a plain depot subfolder is
				// also a real directory under the property, and there is no
				// file record behind it. You cannot serve a folder — that is a
				// miss, not a server fault.
				return $this->make(PropertyFileKind::Nested, $collection, $id, $property, nestedSubpath: $sanitized, missing: true);
			}

			return $this->make(PropertyFileKind::Nested, $collection, $id, $property, name: $name, nestedSubpath: $sanitized);
		}

		return $this->make(PropertyFileKind::Depot, $collection, $id, $property, name: self::decodeFilename($rawPath), subpath: $depotSubpath);
	}

	/**
	 * Both `+` and `%20` spellings of a space arrive in practice.
	 */
	public static function decodeFilename(string $filename): string
	{
		return str_replace('+', ' ', urldecode($filename));
	}

	private function make(PropertyFileKind $kind, string $collection, string $id, string $property, string $name = '', ?string $subpath = null, ?string $nestedSubpath = null, bool $missing = false): PropertyFile
	{
		return new PropertyFile($this->fileFetcher, $this->depotFetcher, $this->propertyFetcher, $this->objectUpdater, $kind, $collection, $id, $property, $name, $subpath, $nestedSubpath, $missing);
	}
}
