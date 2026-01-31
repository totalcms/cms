<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminMailerAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// Ensure mailer collection exists
		$this->collectionFetcher->fetchOrCreateReserved('mailer');

		$id           = $args['id'] ?? '';
		$templateData = [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'page'       => 'mailer',
				'id'         => $id,
				'collection' => 'mailer',
			],
		];

		// Handle POST request for object duplication
		if ($request->getMethod() === 'POST' && $id === 'new') {
			$postData = (array) $request->getParsedBody();

			if (isset($postData['duplicate']) && is_string($postData['duplicate'])) {
				$duplicateId                   = $postData['duplicate'];
				$objectToDuplicate             = $this->objectFetcher->fetchObject('mailer', $duplicateId);
				$templateData['duplicateData'] = $objectToDuplicate->toArray();
			}
		}

		return $this->twigRenderer->template($response, 'admin/mailer.twig', $templateData);
	}
}
