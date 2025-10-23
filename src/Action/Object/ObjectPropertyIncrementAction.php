<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectPropertyIncrementer;
use TotalCMS\Renderer\JsonRenderer;

readonly class ObjectPropertyIncrementAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPropertyIncrementer $incrementer,
	) {
	}

	/**
	 * Increment a numeric property.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args Route arguments
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$amount = isset($args['amount']) ? (int)$args['amount'] : 1;

		try {
			$result = $this->incrementer->incrementProperty(
				$args['collection'],
				$args['id'],
				$args['property'],
				$amount
			);

			return $this->renderer->json($response, $result);
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		} catch (\OutOfRangeException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}
