<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminCollectionAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private \TotalCMS\Renderer\RedirectRenderer $redirectRenderer,
		private \TotalCMS\Domain\Collection\Service\SingletonCollectionResolver $singletonResolver,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// /collections - index page
		// /collections/new - collection create form
		// /collections/{collection} - object list page
		// /collections/{collection}/edit - edit collection form
		// /collections/{collection}/add - edit collection form
		// /collections/{collection}/{id} - edit object form

		// Handle specific routes by setting expected args based on route name
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		$routeName    = $route?->getName() ?? '';

		if ($routeName === 'admin-collection-new') {
			$args['collection'] = 'new';
		} elseif ($routeName === 'admin-collection-edit') {
			$args['id'] = 'edit';
		}

		// Validate collection exists (skip for index, new, and special routes)
		$collection = $args['collection'] ?? '';
		if ($collection !== '' && $collection !== 'new' && !$this->collectionFetcher->collectionExists($collection)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		// Validate object exists (skip for add/edit keywords)
		$id = $args['id'] ?? '';
		if ($collection !== '' && $collection !== 'new'
			&& !in_array($id, ['', 'add', 'edit'], true)
			&& !str_starts_with($id, '-')
			&& !$this->objectFetcher->existsObject($collection, $id)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		// Singleton collections: clicking the collection (list route, no object
		// id) opens its single object directly. ensureObject() lazy-creates or
		// re-keys to the collection id; the redirect target is that id. When
		// dormant (>1 object) isActive() is false and we fall through to the
		// normal list, which shows the dormant notice.
		$collectionData = ($collection !== '' && $collection !== 'new')
			? $this->collectionFetcher->fetchCollection($collection)
			: null;

		if ($collectionData instanceof CollectionData
			&& ($args['id'] ?? '') === ''
			&& $this->singletonResolver->isActive($collectionData)
		) {
			// Existing object → edit it (re-keyed to the collection id);
			// empty → the new-object form ('add'). The first save forces the
			// id to the collection id via ObjectSaver.
			$target = $this->singletonResolver->resolveTarget($collectionData);

			return $this->redirectRenderer->redirectFor(
				$response,
				'admin-collection',
				['collection' => $collection, 'id' => $target ?? 'add'],
			);
		}

		$templateData = [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'params'     => $args,
				'page'       => 'collections',
				'collection' => $args['collection'] ?? '',
				'id'         => $args['id'] ?? '',
			],
			'singleton' => $collectionData instanceof CollectionData && $collectionData->singleton,
		];

		// Handle POST request for object duplication
		if ($request->getMethod() === 'POST' && $id === 'add') {
			$postData = (array)$request->getParsedBody();

			// Check if this is a duplication request (contains 'duplicate' with object ID)
			if (isset($postData['duplicate']) && is_string($postData['duplicate'])) {
				$duplicateId = $postData['duplicate'];

				// Fetch the object to duplicate and convert to array
				// ObjectForm will handle filtering file properties
				$objectToDuplicate             = $this->objectFetcher->fetchObject($collection, $duplicateId);
				$templateData['duplicateData'] = $objectToDuplicate->toArray();
			}
		}

		return $this->twigRenderer->template($response, 'admin/collection.twig', $templateData);
	}
}
