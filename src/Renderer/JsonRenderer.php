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
class JsonRenderer
{
	/**
	 * Write JSON to the response body and (optionally) set the HTTP status.
	 *
	 * The third argument is an HTTP status code, not a `json_encode()` flag
	 * bitmask. Callers across the codebase had been passing values like
	 * `400` / `500` here expecting the response status to update — that's
	 * the intent this signature honours. Pass `0` (the default) to leave the
	 * incoming response's status unchanged.
	 *
	 * @param ResponseInterface $response The response
	 * @param mixed|null        $data     Payload to encode
	 * @param int               $status   HTTP status code to apply (0 = leave unchanged)
	 *
	 * @return ResponseInterface The response
	 */
	public function json(
		ResponseInterface $response,
		mixed $data = null,
		int $status = 0,
	): ResponseInterface {
		if ($status > 0) {
			$response = $response->withStatus($status);
		}

		$json = json_encode($data);

		$response = $response->withHeader('Content-Type', 'application/json');
		$body     = $response->getBody();
		$body->rewind();
		$body->write((string)$json);
		$body->rewind();

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
		TransformerAbstract $transformer,
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
	 * @param array<string,mixed> $meta Optional top-level meta data to include alongside `data`
	 *
	 * @return ResponseInterface The response
	 */
	public function jsonItem(
		ResponseInterface $response,
		object $item,
		TransformerAbstract $transformer,
		array $meta = [],
	): ResponseInterface {
		$resource = new FractalItem($item, $transformer);
		if ($meta !== []) {
			$resource->setMeta($meta);
		}
		$data = (new FractalManager())->createData($resource)->toArray();

		return $this->json($response, $data);
	}
}
