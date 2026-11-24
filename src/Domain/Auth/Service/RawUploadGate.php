<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Session\SessionUser;

/**
 * Whether a raw upload URL — `/download/upload/…`, `/stream/upload/…`,
 * `/imageworks/upload/…` — may serve the file it names.
 *
 * Those routes exist for loose uploads (images and files dropped into a
 * styled text field), but they address storage directly, and a file field,
 * a depot and a card/deck child file keep their files in the same folders.
 * So a file the canonical `/download/{collection}/{id}/{property}` route
 * guards with access groups or a password was served to anyone through the
 * raw routes (security advisory GHSA-4852-hjgf-c7q5, 2026-11-03).
 *
 * The decision is made for the file the URL resolves to. When the folder
 * belongs to a protected file, depot or nested file child, its own rules
 * apply: group protection needs a member of the collection's groups, and a
 * password-protected file is refused outright — these routes have no
 * password form, the canonical download route does. Anything else is a
 * loose upload and keeps the per-property {@see UploadAccessPolicy} rule.
 *
 * Not `final`, so action unit tests can stub it.
 */
readonly class RawUploadGate
{
	public function __construct(
		private PropertyFetcher $propertyFetcher,
		private FileFetcher $fileFetcher,
		private FileAccessManager $fileAccess,
		private UploadAccessPolicy $uploadPolicy,
		private SessionInterface $session,
		private UserValidationService $userValidator,
	) {
	}

	/**
	 * @param string|null $settingKey the loose-upload opt-in setting the route honors
	 *                                (`fileProtectedByCollection`, `videoProtectedByCollection`), or null for none
	 *
	 * @return string|null the reason the request is refused, or null when it may proceed
	 */
	public function denialFor(string $collection, string $id, string $property, ?string $subpath, ?string $settingKey): ?string
	{
		if ($this->loadManagedFile($collection, $id, $property, $subpath)) {
			$denial = $this->managedFileDenial();
			if ($denial !== null) {
				return $denial;
			}
		}

		return $settingKey === null ? null : $this->uploadPolicy->denialFor($collection, $property, $settingKey);
	}

	/**
	 * Load the protection of the file field, depot or nested file child that
	 * owns `{property}/{subpath}`, when one does.
	 *
	 * The whole folder counts, not only the file the record names: an older
	 * upload left beside the current one is the same protected material.
	 */
	private function loadManagedFile(string $collection, string $id, string $property, ?string $subpath): bool
	{
		try {
			$data = $this->propertyFetcher->fetchProperty($collection, $id, $property);
		} catch (\Throwable) {
			// No such object or property: nothing guards these bytes beyond the loose-upload rule.
			return false;
		}

		if ($data instanceof DepotData) {
			$this->fileAccess->loadDepotFile($collection, $id, $property);

			return true;
		}

		if ($data instanceof FileData) {
			$this->fileAccess->loadFile($collection, $id, $property);

			return true;
		}

		if (($data instanceof CardData || $data instanceof DeckData) && $subpath !== null && $subpath !== '') {
			try {
				$this->fileFetcher->fetchFile($collection, $id, $property, $subpath);
			} catch (\Throwable) {
				// The child is not a file (a styled text child's uploads, say).
				return false;
			}
			$this->fileAccess->loadFile($collection, $id, $property, $subpath);

			return true;
		}

		return false;
	}

	private function managedFileDenial(): ?string
	{
		if ($this->fileAccess->isProtectedByGroups()) {
			if (!$this->fileAccess->sessionHasUser()) {
				return 'Authentication required';
			}
			if (!$this->fileAccess->userHasAccess()) {
				return 'Access denied';
			}
		}

		if ($this->fileAccess->isPasswordProtected() && !$this->isSuperAdmin()) {
			return 'This file is password protected; use its download link';
		}

		return null;
	}

	private function isSuperAdmin(): bool
	{
		$user = SessionUser::fromSession($this->session);

		return $user instanceof SessionUser && $this->userValidator->isSuperAdmin($user->id, $user->collection);
	}
}
