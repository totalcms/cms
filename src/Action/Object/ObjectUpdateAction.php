<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Media\Service\GrantedDepotMerger;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private FragmentResponder $fragments,
		private ObjectFetcher $objectFetcher,
		private GrantedDepotMerger $depots,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$data   = $this->keepStoredData($args['collection'], $args['id'], $data);
		$object = $this->objectUpdater->updateObject($args['collection'], $args['id'], $data);

		if ($this->fragments->wants($request)) {
			return $this->fragments->respond($request, $response, $object, $args['collection']);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}

	/**
	 * PUT replaces the whole object, but some of what is stored must survive a
	 * body that leaves it out or holds an old copy of it:
	 *
	 * - Passwords: GET never returns password hashes, so a client that fetches
	 *   a record and sends it back omits the password, and the replace would
	 *   blank it. The stored hash is carried forward for any password key the
	 *   body leaves out, so a GET→PUT round trip cannot lock a user out.
	 * - Files in a depot that takes public uploads: a visitor may have sent one
	 *   after the admin page loaded. See GrantedDepotMerger.
	 *
	 * PATCH is the right call for partial edits.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function keepStoredData(string $collection, string $id, array $data): array
	{
		try {
			$stored = $this->objectFetcher->fetchObject($collection, $id);
		} catch (\UnexpectedValueException) {
			// No stored object — nothing to carry. updateObject reports it.
			return $data;
		}

		return $this->depots->keepStoredFiles($collection, $stored, $stored->carryPasswordsInto($data));
	}
}
