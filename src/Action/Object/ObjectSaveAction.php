<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectSaveAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ObjectSaver $service Object save service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $service,
		private PrivilegedFieldGuard $guard,
		private FragmentResponder $fragments,
		private BotSubmissionGate $botGate,
	) {
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data      = (array)$request->getParsedBody();
		$botReason = '';

		// An anonymous public create passes the form's bot check (honeypot +
		// time trap). A bot gets a decoy in the shape of a real save; nothing
		// is written. An expired token is a real form left open: say so.
		if ($request->getAttribute('publicSubmission') === true) {
			[$data, $botReason] = $this->botGate->screen($request, $data, $args['collection'], 'create');
			if (BotSubmissionGate::expired($botReason)) {
				return $this->botGate->expiredResponse($request, $response);
			}
		}

		$data   = $this->guard->guard($request, $args['collection'], (string)($data['id'] ?? ''), $data);
		$object = $botReason === ''
			? $this->service->saveObject($args['collection'], $data)
			: $this->botGate->decoy($args['collection'], $data);

		if ($this->fragments->wants($request)) {
			return $this->fragments->respond($request, $response, $object, $args['collection']);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
