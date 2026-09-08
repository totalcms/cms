<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Visualizer\Service\VisualizerService;

/**
 * Collection Visualizer (Mermaid ERD), Object Visualizer (one record's
 * inbound/outbound references) and the access-group Permission Matrix.
 */
final readonly class VisualizerPageData implements UtilsPageData
{
	public function __construct(
		private VisualizerService $visualizerService,
		private CollectionLister $collectionLister,
		private SessionInterface $session,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$query         = $request->getQueryParams();
		$currentUserId = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		$userId        = $currentUserId !== '' ? $currentUserId : null;

		$visualizerData = null;
		if ($page === 'collection-visualizer') {
			$visualizerData = $this->visualizerService->collectionGraph($this->collectionLister->listAllCollections(), $query, $userId);
		}

		$objectVisualizerData = null;
		if ($page === 'object-visualizer') {
			$objectVisualizerData = $this->visualizerService->objectGraph($this->collectionLister->listAllCollections(), $query, $userId);
		}

		$permissionMatrixData = null;
		if ($page === 'permission-matrix') {
			$group                = isset($query['group']) ? trim((string)$query['group']) : '';
			$permissionMatrixData = [
				'matrix' => $this->visualizerService->accessGroupMatrix(),
				'group'  => $group,
			];
		}

		return [
			'visualizerData'       => $visualizerData,
			'objectVisualizerData' => $objectVisualizerData,
			'permissionMatrixData' => $permissionMatrixData,
		];
	}
}
