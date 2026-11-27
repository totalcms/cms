<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * Whether a paid download can be fetched without buying. Total CMS serves a
 * file field at an ordinary address, and for a collection with File Access
 * Groups that address works only for their members. A collection the owner
 * made by hand has none until they add one, and the group `default` means
 * any signed-in user.
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
	 * The id of the products collection when its paid download can be fetched
	 * without buying (see openDownloads()), null otherwise.
	 */
	public function unprotectedCollection(): ?string
	{
		return $this->openDownloads()?->collection;
	}

	/**
	 * The products collection when its paid download is open: it has no File
	 * Access Groups, so anyone can fetch it, or they include `default`, which
	 * lets any signed-in user fetch it (and a store whose customers sign up
	 * themselves lets anyone sign in). Null otherwise. A link has no file here
	 * to protect. A collection or schema that cannot be read is not reported
	 * (DownloadSources logs that), so this never throws.
	 */
	public function openDownloads(): ?OpenDownloads
	{
		$id = $this->settings->collection('products');

		try {
			$collection = $this->collections->fetchCollection($id);
			if ($collection === null) {
				return null;
			}

			$anySignedIn = in_array(UserValidationService::DEFAULTGROUP, $collection->groups, true);
			if ($collection->groups !== [] && !$anySignedIn) {
				return null;
			}

			$type = $this->sources->fieldType($id);
		} catch (\Throwable) {
			return null;
		}

		return $type === '' || $type === 'url' ? null : new OpenDownloads($id, $anySignedIn);
	}
}
