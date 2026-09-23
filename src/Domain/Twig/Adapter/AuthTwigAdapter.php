<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Session\SessionUser;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for authentication and access control.
 *
 * Accessed in Twig as `cms.auth.*`.
 */
readonly class AuthTwigAdapter
{
	public function __construct(
		private Config $config,
		private SessionInterface $session,
		private AccessManager $accessManager,
		private FileAccessManager $fileAccessManager,
		private AccessControlService $accessControl,
		private CollectionLister $collectionLister,
		private TranslationService $translator,
		private EditionFeatureService $editionFeatures,
		private UserValidationService $userValidation,
		private ImpersonationServiceInterface $impersonation,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function logout(string $redirect = ''): string
	{
		$url = $this->config->api . '/admin/logout';
		if ($redirect !== '') {
			$url .= '?redirect=' . urlencode($redirect);
		}

		return $url;
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function login(string $collection = '', ?string $redirect = null): string
	{
		$loginUrl = $collection === ''
			? sprintf('%s/%s', $this->config->api, 'admin/login')
			: sprintf('%s/%s/%s', $this->config->api, 'admin/login', $collection);

		$redirect ??= $_SERVER['REQUEST_URI'] ?? '';

		if ($redirect !== '') {
			$loginUrl .= '?' . http_build_query(['redirect' => $redirect]);
		}

		return $loginUrl;
	}

	/** @return array<string,mixed> */
	public function userData(): array
	{
		return $this->accessManager->userData();
	}

	public function userLoggedIn(string $collection = ''): bool
	{
		return $this->accessManager->userLoggedIn($collection);
	}

	/** @param string|array<string> $groups */
	public function userHasAccess(array|string $groups, string $collection = ''): bool
	{
		return $this->accessManager->userHasAccess($groups, $collection);
	}

	public function sessionData(string $key): ?string
	{
		if ($this->session->has($key)) {
			return $this->session->get($key);
		}

		return null;
	}

	public function verifyFilePassword(string $password, string $collection, string $id, string $property, ?string $name = null): bool
	{
		// Dotted property (`mycard.file`) means a nested file — split into the
		// root property + subpath the access manager can walk.
		if (str_contains($property, '.')) {
			$parts    = explode('.', $property);
			$root     = array_shift($parts);
			$subpath  = implode('/', $parts);
			$this->fileAccessManager->loadFile($collection, $id, $root, $subpath);
		} elseif ($name !== null) {
			$this->fileAccessManager->loadDepotFile($collection, $id, $property);
		} else {
			$this->fileAccessManager->loadFile($collection, $id, $property);
		}

		return $this->fileAccessManager->verfiyPasswordOnly($password);
	}

	/**
	 * The policy every access check shares: with auth off everything is
	 * allowed, with no session user nothing is, otherwise ask the access
	 * control service about the current user. It used to be written out in
	 * each of the fifteen methods below.
	 *
	 * @param \Closure(string): bool $check
	 */
	private function withUser(\Closure $check): bool
	{
		if (!$this->config->authEnabled()) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $check((string)$userData['id']);
	}

	/**
	 * Check if user is in admin group (bypasses all access controls).
	 */
	public function isAdmin(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->isAdmin($userId));
	}

	/**
	 * Check if current user can perform a CRUD operation on a collection.
	 */
	public function canAccessCollection(string $collection, string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessCollection($userId, $collection, $operation));
	}

	/**
	 * Get collections the current user can access with a given CRUD operation.
	 *
	 * @return array<string>
	 */
	public function accessibleCollections(string $operation = 'read'): array
	{
		$allCollections = $this->collectionLister->listAllCollections();
		$accessible     = [];

		foreach ($allCollections as $collection) {
			if ($this->canAccessCollection($collection->id, $operation)) {
				$accessible[] = $collection->id;
			}
		}

		return $accessible;
	}

	/**
	 * Check if current user can perform a CRUD operation on collections in general.
	 */
	public function canAccessCollectionsOperation(string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessCollectionsOperation($userId, $operation));
	}

	/**
	 * Check if current user can perform an action on a collection's metadata.
	 */
	public function canAccessCollectionMeta(string $collection, string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessCollectionMeta($userId, $collection, $operation));
	}

	/**
	 * Check if current user can perform a CRUD operation on collection metadata in general.
	 */
	public function canAccessCollectionsMetaOperation(string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessCollectionsMetaOperation($userId, $operation));
	}

	/**
	 * Check if current user can perform a CRUD operation on a schema.
	 */
	public function canAccessSchema(string $schema, string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessSchema($userId, $schema, $operation));
	}

	/**
	 * Check if current user can perform a CRUD operation on schemas in general.
	 */
	public function canAccessSchemasOperation(string $operation = 'read'): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessSchemasOperation($userId, $operation));
	}

	public function canAccessUtil(string $page): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessUtils($userId, $page));
	}

	public function canAccessUtils(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessAnyUtils($userId));
	}

	public function canAccessMailer(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessMailer($userId));
	}

	public function canAccessPlayground(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessPlayground($userId));
	}

	public function canAccessDataViews(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessDataViews($userId));
	}

	public function canAccessBuilder(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessBuilder($userId));
	}

	public function canAccessExtension(string $extensionId): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessExtension($userId, $extensionId));
	}

	public function canAccessDocs(): bool
	{
		return $this->withUser(fn (string $userId): bool => $this->accessControl->canAccessDocs($userId));
	}

	/**
	 * Render the passkey manager UI for registering and managing passkeys.
	 *
	 * Usage in Twig: {{ cms.auth.passkeyManager() }}
	 */
	public function passkeyManager(): string
	{
		if (!$this->accessManager->userLoggedIn()) {
			return '';
		}

		if (!$this->editionFeatures->can(EditionFeature::PASSKEYS)) {
			return '';
		}

		if (!($this->config->auth['usePasskeys'] ?? true)) {
			return '';
		}

		$heading     = HTMLUtils::element('h2', $this->translator->trans('passkey.title'));
		$description = HTMLUtils::element('p', $this->translator->trans('passkey.description'));
		$listUrl     = $this->config->api . '/api/passkeys/list/html';
		$list        = HTMLUtils::element('div', '', [
			'id'         => 'passkeys-list',
			'hx-get'     => $listUrl,
			'hx-trigger' => 'load, passkey-changed from:body',
			'hx-swap'    => 'innerHTML',
		]);
		$button      = HTMLUtils::button($this->translator->trans('passkey.register'), [
			'type'  => 'button',
			'class' => 'dash-button',
			'id'    => 'passkey-register-btn',
		]);
		$status = HTMLUtils::element('div', '', [
			'id'    => 'passkey-status',
			'class' => 'cms-hide',
			'role'  => 'status',
		]);

		return HTMLUtils::element('section', $heading . $description . $list . $button . $status, [
			'class'    => 'passkeys-manager',
			'id'       => 'passkeys-manager',
			'data-api' => $this->config->api . '/api',
		]);
	}

	/**
	 * Check whether a user is a super-admin (in the `admin` group of the
	 * primary auth collection).
	 *
	 * When `$userId` is empty the current session user is resolved automatically,
	 * making the method useful both as an entry-point gate
	 * (`cms.auth.isSuperAdmin()`) and as a per-target check
	 * (`cms.auth.isSuperAdmin(page)`).
	 */
	public function isSuperAdmin(string $userId = ''): bool
	{
		// Only the session branch can know which auth collection the id belongs
		// to. Passing it matters: super admins exist only in the default auth
		// collection, so without it a secondary-collection user whose id
		// collides with an admin's would pass this gate. An explicitly supplied
		// $userId carries no collection, so it keeps the historical
		// assume-the-default behavior.
		if ($userId !== '') {
			return $this->userValidation->isSuperAdmin($userId, '');
		}

		$user = SessionUser::fromSession($this->session);

		return $user !== null && $this->userValidation->isSuperAdmin($user->id, $user->collection);
	}

	/**
	 * Return whether a super-admin is currently impersonating another user.
	 */
	public function isImpersonating(): bool
	{
		return $this->impersonation->isImpersonating();
	}

	/**
	 * Return the id of the currently-impersonated user, or an empty string
	 * when no impersonation is active.
	 *
	 * During impersonation `SessionKeys::AUTH_USER` holds the target's id
	 * (because `ImpersonationService::start()` calls `SessionLogin::establish()`
	 * which swaps the session user).
	 */
	public function impersonatedUserId(): string
	{
		if (!$this->impersonation->isImpersonating()) {
			return '';
		}

		return (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
	}
}
