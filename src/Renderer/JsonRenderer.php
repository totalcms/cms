<?php

namespace TotalCMS\Renderer;

use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Resource\Item as FractalItem;
use League\Fractal\TransformerAbstract;
use Psr\Http\Message\ResponseInterface;

/**
 * A JSON response renderer.
 */
final class JsonRenderer
{
	/**
	 * Write JSON to the response body.
	 *
	 * This method prepares the response object to return an HTTP JSON
	 * response to the client.
	 *
	 * @param ResponseInterface $response The response
	 * @param mixed|null $data The data
	 * @param int $options Json encoding options
	 *
	 * @return ResponseInterface The response
	 */
	public function json(
		ResponseInterface $response,
		mixed $data = null,
		int $options = 0
	): ResponseInterface {
		$response = $response->withHeader('Content-Type', 'application/json');
		$response->getBody()->write((string)json_encode($data, $options));

		return $response;
	}

	/**
	 * Write JSON to the response body.
	 *
	 * This method prepares the response object to return an HTTP JSON
	 * response to the client.
	 *
	 * @param ResponseInterface $response The response
	 * @param array<mixed> $collection The data
	 * @param TransformerAbstract $transformer The data transformer
	 *
	 * @return ResponseInterface The response
	 */
	public function jsonCollection(
		ResponseInterface $response,
		array $collection,
		TransformerAbstract $transformer
	): ResponseInterface {
		$resource = new FractalCollection($collection, $transformer);

		return $this->json($response, (new FractalManager())->createData($resource)->toArray());
	}

	/**
	 * Write JSON to the response body.
	 *
	 * This method prepares the response object to return an HTTP JSON
	 * response to the client.
	 *
	 * @param ResponseInterface $response The response
	 * @param object $item The data
	 * @param TransformerAbstract $transformer The data transformer
	 *
	 * @return ResponseInterface The response
	 */
	public function jsonItem(
		ResponseInterface $response,
		object $item,
		TransformerAbstract $transformer
	): ResponseInterface {
		$resource = new FractalItem($item, $transformer);

		return $this->json($response, (new FractalManager())->createData($resource)->toArray());
	}
}
