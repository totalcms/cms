<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectFetchAction
{
	private JsonRenderer $renderer;
	private ObjectFetcher $objectFetcher;

	public function __construct(JsonRenderer $renderer, ObjectFetcher $fetcher)
	{
		$this->renderer      = $renderer;
		$this->objectFetcher = $fetcher;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @throws HttpNotFoundException
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$object = $this->objectFetcher->fetchObject($args['collection'], $args['id']);
		} catch (\UnexpectedValueException $e) {
			throw new HttpNotFoundException($request, $e->getMessage());
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
