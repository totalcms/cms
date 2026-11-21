<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Access;

use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;

/**
 * Who is asking, and what they may do with a collection.
 *
 * One place for it, because of one rule every check must share: a site with
 * authentication switched off (`auth.enable = false`) has no users, and core
 * lets every request through there. A check that asks "is somebody signed in?"
 * on its own refuses everything on such a site.
 */
final readonly class S3Access
{
	/**
	 * @param \Closure(): bool               $authEnabled
	 * @param \Closure(string, string): bool $ownsObject  (collection, id): whether the collection's ownership rule lets this user change the object
	 * @param \Closure(string, array<int|string,mixed>, string): bool $fileAccess (user id, groups, the user's auth collection): core's rule for who may have a file in a collection with those groups
	 */
	public function __construct(
		private \Closure $authEnabled,
		private AccessManager $accessManager,
		private AccessControlService $accessControl,
		private \Closure $ownsObject,
		private \Closure $fileAccess,
	) {
	}

	/** Whether the site runs without authentication, so nobody is refused. */
	public function open(): bool
	{
		return !($this->authEnabled)();
	}

	/** The signed-in user's id, or '' when nobody is signed in, as on an open site. */
	public function userId(): string
	{
		$id = $this->accessManager->userData()['id'] ?? '';

		return is_scalar($id) ? (string)$id : '';
	}

	/** Whether there is somebody to answer to: a signed-in user, or an open site. */
	public function identified(): bool
	{
		return $this->open() || $this->userId() !== '';
	}

	/** @param string $operation read, update, … as the access groups name them */
	public function can(string $collection, string $operation): bool
	{
		if ($this->open()) {
			return true;
		}

		$userId = $this->userId();

		return $userId !== '' && $this->accessControl->canAccessCollection($userId, $collection, $operation);
	}

	/** Whether the collection's ownership rule lets this user change the object. */
	public function owns(string $collection, string $id): bool
	{
		return $this->open() || ($this->ownsObject)($collection, $id);
	}

	/**
	 * Whether the signed-in user may have a file from a collection with these
	 * groups: the rule core's own file links use. A super admin always may, and
	 * the `default` group means any signed-in member. Always true on an open site.
	 *
	 * @param array<int|string,mixed> $groups
	 */
	public function inGroups(array $groups): bool
	{
		if ($this->open()) {
			return true;
		}

		$user = $this->user();
		if ($user === null) {
			return false;
		}

		try {
			return $user['superAdmin'] || ($this->fileAccess)($user['id'], $groups, $user['collection']);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The signed-in user, for the protected-download log. Null when nobody is signed in.
	 *
	 * @return array{id:string, collection:string, superAdmin:bool}|null
	 */
	public function user(): ?array
	{
		$id = $this->userId();
		if ($id === '') {
			return null;
		}

		$collection = $this->accessManager->userData()['collection'] ?? '';

		return ['id' => $id, 'collection' => is_scalar($collection) ? (string)$collection : '', 'superAdmin' => $this->accessManager->sessionIsSuperAdmin()];
	}

	public function signedIn(): bool
	{
		return $this->accessManager->sessionHasUser();
	}
}
