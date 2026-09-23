<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionUser;

/**
 * Whether a raw upload may be served to the current session.
 *
 * A collection with access groups can ask that a property's uploads be
 * served only to members of those groups — `fileProtectedByCollection` on a
 * file field, `videoProtectedByCollection` on a video field. The download
 * and stream upload actions each inlined this rule.
 */
final readonly class UploadAccessPolicy
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private SessionInterface $session,
		private UserValidationService $userValidator,
	) {
	}

	/**
	 * @param string $settingKey the property setting that switches protection on
	 *
	 * @return string|null the reason the request is refused, or null when it may proceed
	 */
	public function denialFor(string $collection, string $property, string $settingKey): ?string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData || $collectionData->groups === []) {
			return null;
		}

		$schema   = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$settings = $schema->properties[$property]['settings'] ?? [];
		if (empty($settings[$settingKey])) {
			return null;
		}

		$user = SessionUser::fromSession($this->session);
		if (!$user instanceof SessionUser) {
			return 'Authentication required';
		}

		if ($this->userValidator->isSuperAdmin($user->id, $user->collection)) {
			return null;
		}

		return $this->userValidator->validateFileAccess($user->id, $collectionData->groups, $user->collection)
			? null
			: 'Access denied';
	}
}
