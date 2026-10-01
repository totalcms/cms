<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private PrivilegedFieldGuard $guard,
		private FragmentResponder $fragments,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$data   = $this->guard->guard($request, $args['collection'], $args['id'], $data);
		$data   = $this->keepStoredPasswords($args['collection'], $args['id'], $data);
		$object = $this->objectUpdater->updateObject($args['collection'], $args['id'], $data);

		if ($this->fragments->wants($request)) {
			return $this->fragments->respond($request, $response, $object, $args['collection']);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}

	/**
	 * PUT replaces the whole object, and GET never returns password hashes —
	 * so a client that fetches a record and sends it back omits the password,
	 * and the replace would blank it. Carry the stored hash forward for any
	 * password key the body leaves out. PATCH is the right call for partial
	 * edits; this keeps a GET→PUT round trip from locking a user out.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function keepStoredPasswords(string $collection, string $id, array $data): array
	{
		try {
			return $this->objectFetcher->fetchObject($collection, $id)->carryPasswordsInto($data);
		} catch (\UnexpectedValueException) {
			// No stored object — nothing to carry. updateObject reports it.
			return $data;
		}
	}
}
