<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

readonly class IndexBuildAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private IndexBuilder $service,
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$built      = $this->service->buildIndex($collection);

		// A hand-edited object file only becomes visible through a rebuild,
		// but a request handler may already have warmed this object's
		// per-object cache from the stale contents. Markdown collections are
		// the ones the operator edits by hand (that is what the format is
		// for), so this explicit rebuild also drops their cached objects.
		// JSON collections keep their cache: they can be large, evicting
		// every object is not free (on Memcached it flushes everything), and
		// a hand-dropped JSON file was never refreshed this way before.
		// `tcms repair:index` clears for every format; it is an explicit
		// operator command rather than a button on every collection.
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData instanceof CollectionData && $collectionData->isMarkdown()) {
			$this->objectFetcher->clearCollectionCache($collection);
		}

		// Read back rather than returning the build's value: above the
		// streaming threshold buildIndex() writes the file and hands back an
		// empty IndexData, so this endpoint reported an empty index after a
		// perfectly successful rebuild of a large collection.
		$index = $this->storage->fetchIndex($collection) ?? $built;

		return $this->renderer->jsonItem($response, $index, new IndexTransformer());
	}
}
