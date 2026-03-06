<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
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
		private PhpSession $session,
		private AccessManager $accessManager,
		private FileAccessManager $fileAccessManager,
		private AccessControlService $accessControl,
		private CollectionLister $collectionLister,
		private TranslationService $translator,
		private EditionFeatureService $editionFeatures,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function logout(string $redirect = ''): string
	{
		$url = $this->config->api . '/logout';
		if ($redirect !== '') {
			$url .= '?redirect=' . urlencode($redirect);
		}

		return $url;
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function login(string $collection = '', ?string $redirect = null): string
	{
		$loginUrl = $collection === ''
			? sprintf('%s/%s', $this->config->api, 'login')
			: sprintf('%s/%s/%s', $this->config->api, 'login', $collection);

		if ($redirect === null) {
			$redirect = $_SERVER['REQUEST_URI'] ?? '';
		}

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
		if ($name !== null) {
			$this->fileAccessManager->loadDepotFile($collection, $id, $property);
		} else {
			$this->fileAccessManager->loadFile($collection, $id, $property);
		}

		return $this->fileAccessManager->verfiyPasswordOnly($password);
	}

	/**
	 * Check if user is in admin group (bypasses all access controls).
	 */
	public function isAdmin(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->isAdmin($userData['id']);
	}

	/**
	 * Check if current user can perform a CRUD operation on a collection.
	 */
	public function canAccessCollection(string $collection, string $operation = 'read'): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollection($userData['id'], $collection, $operation);
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
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionsOperation($userData['id'], $operation);
	}

	/**
	 * Check if current user can perform an action on a collection's metadata.
	 */
	public function canAccessCollectionMeta(string $collection, string $operation = 'read'): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionMeta($userData['id'], $collection, $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on collection metadata in general.
	 */
	public function canAccessCollectionsMetaOperation(string $operation = 'read'): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionsMetaOperation($userData['id'], $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on a schema.
	 */
	public function canAccessSchema(string $schema, string $operation = 'read'): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessSchema($userData['id'], $schema, $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on schemas in general.
	 */
	public function canAccessSchemasOperation(string $operation = 'read'): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessSchemasOperation($userData['id'], $operation);
	}

	public function canAccessTemplates(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessTemplates($userData['id']);
	}

	public function canAccessUtil(string $page): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessUtils($userData['id'], $page);
	}

	public function canAccessUtils(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessAnyUtils($userData['id']);
	}

	public function canAccessMailer(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessMailer($userData['id']);
	}

	public function canAccessPlayground(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessPlayground($userData['id']);
	}

	public function canAccessDataViews(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessDataViews($userData['id']);
	}

	public function canAccessDocs(): bool
	{
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessDocs($userData['id']);
	}

	/**
	 * Render the passkey manager UI for registering and managing passkeys.
	 *
	 * Usage in Twig: {{ cms.auth.passkeyManager()|raw }}
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
		$listUrl = $this->config->api . '/passkeys/list/html';
		$list    = HTMLUtils::element('div', '', [
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
			'data-api' => $this->config->api,
		]);
	}
}
