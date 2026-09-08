<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/** Access Groups (list / new / edit) and API Keys (list / new). */
final readonly class AccessPageData implements UtilsPageData
{
	public function __construct(
		private ApiKeyFetcher $apiKeyFetcher,
		private AccessGroupLister $accessGroupLister,
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private ExtensionManager $extensionManager,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		return [
			'apiKeys'          => $page === 'api-keys' && $action !== 'new' ? $this->apiKeyFetcher->getAllKeys() : null,
			'accessGroupsData' => $page === 'access-groups' ? $this->createAccessGroupData($action) : null,
		];
	}

	/** @return array<string,mixed> */
	private function createAccessGroupData(string $action): array
	{
		// Ensure the default group exists for backwards compatibility
		$this->accessGroupLister->ensureDefaultGroupExists();

		$isEdit = $action !== 'new' && $action !== '';

		return [
			'groups'      => $this->accessGroupLister->listAll(),
			'collections' => $this->collectionLister->listAllCollections(),
			'schemas'     => $this->schemaLister->listAllSchemas(),
			'extensions'  => $this->extensionManager->listExtensionsWithAdminSurface(),
			'group'       => $isEdit ? $this->accessGroupLister->findById($action) : '',
			'isEdit'      => $isEdit,
		];
	}
}
