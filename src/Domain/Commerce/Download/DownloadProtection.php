<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * Whether a paid download can be fetched by anyone. Total CMS serves a file
 * field at an ordinary address, and for a collection with File Access Groups
 * that address works only for their members. A collection the owner made by
 * hand has none until they add one.
 */
final readonly class DownloadProtection
{
	public function __construct(
		private CommerceSettings $settings,
		private CollectionFetcher $collections,
		private DownloadSources $sources,
	) {
	}

	/**
	 * The id of the products collection when its paid download is open to the
	 * public, null otherwise. A link has no file here to protect. A collection
	 * or schema that cannot be read is not reported (DownloadSources logs
	 * that), so this never throws.
	 */
	public function unprotectedCollection(): ?string
	{
		$id = $this->settings->collection('products');

		try {
			$collection = $this->collections->fetchCollection($id);
			if ($collection === null || $collection->groups !== []) {
				return null;
			}

			$type = $this->sources->fieldType($id);
		} catch (\Throwable) {
			return null;
		}

		return $type === '' || $type === 'url' ? null : $id;
	}
}
