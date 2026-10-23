<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectCloneAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ObjectCloner $service Object service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectCloner $service,
		private BotSubmissionGate $botGate,
	) {
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		$from = [
			'id'         => $args['id'],
			'collection' => $args['collection'],
		];
		$to = [
			'id'         => $data['id'] ?? $from['id'],
			'collection' => $data['collection'] ?? $from['collection'],
		];

		// Clone under a public `create` is a create: it passes the same bot
		// check. A clone body carries no bot fields, so this only bites on a
		// collection with Require Bot Protection, where an anonymous clone
		// gets a decoy and nothing is copied.
		$botReason = '';
		if ($request->getAttribute('publicSubmission') === true) {
			[, $botReason] = $this->botGate->screen($request, $data, $from['collection'], 'create');
			if (BotSubmissionGate::expired($botReason)) {
				return $this->botGate->expiredResponse($request, $response);
			}
		}

		$object = $botReason === ''
			? $this->service->cloneObject($from, $to)
			: $this->botGate->cloneDecoy($from['collection'], $from['id'], (string)$to['id']);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
