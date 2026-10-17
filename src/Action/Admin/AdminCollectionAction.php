<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\SingletonCollectionResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Action.
 */
readonly class AdminCollectionAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private RedirectRenderer $redirectRenderer,
		private SingletonCollectionResolver $singletonResolver,
		private Config $config,
		private RegistrationCollections $registrationCollections,
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
			return $this->notFound($request, $response);
		}

		// Validate object exists (skip for add/edit keywords)
		$id = $args['id'] ?? '';
		if ($collection !== '' && $collection !== 'new'
			&& !in_array($id, ['', 'add', 'edit'], true)
			&& !str_starts_with($id, '-')
			&& !$this->objectFetcher->existsObject($collection, $id)) {
			return $this->notFound($request, $response);
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
			// The "Impersonate User" action: the default auth collection and any collection that may register.
			// Reuses the record fetched above rather than fetching it again.
			'canImpersonateUsers' => $collectionData instanceof CollectionData
				&& ($collection === (string)$this->config->auth['collection']
					|| $this->registrationCollections->isEnabledFor($collectionData)),
		];

		$duplicate = $this->duplicateSource($request, $collection, $id);
		if ($duplicate !== null) {
			$templateData['duplicateData'] = $duplicate;
		}

		return $this->twigRenderer->template($response, 'admin/collection.twig', $templateData);
	}

	private function notFound(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
			'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
		]);
	}

	/**
	 * The object to pre-fill the add form with: a POST to .../add carrying a
	 * `duplicate` field names the object to copy. ObjectForm filters the file
	 * properties out of the copy.
	 *
	 * @return array<string,mixed>|null
	 */
	private function duplicateSource(ServerRequestInterface $request, string $collection, string $id): ?array
	{
		if ($request->getMethod() !== 'POST' || $id !== 'add') {
			return null;
		}

		$postData = (array)$request->getParsedBody();
		if (!isset($postData['duplicate']) || !is_string($postData['duplicate'])) {
			return null;
		}

		return $this->objectFetcher->fetchObject($collection, $postData['duplicate'])->toArray();
	}
}
