<?php

declare(strict_types=1);

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Exception\FilterTooLargeException;
use TotalCMS\Domain\ImageWorks\Exception\InvalidImageParamException;
use TotalCMS\Domain\ImageWorks\Exception\RenderBusyException;

/**
 * How every public ImageWorks route turns a render's failure into a
 * response: a bad parameter or a too-large GD filter is 400, a full render
 * queue is 503 with Retry-After, anything else is 404 as before.
 */
trait ImageWorksResponder
{
	/** @param callable(): ResponseInterface $render */
	private function respond(ServerRequestInterface $request, ResponseInterface $response, callable $render): ResponseInterface
	{
		try {
			return $render();
		} catch (InvalidImageParamException|FilterTooLargeException $e) {
			throw new HttpBadRequestException($request, $e->getMessage());
		} catch (RenderBusyException $e) {
			return $response
				->withStatus(503)
				->withHeader('Retry-After', (string)$e->retryAfter)
				->withHeader('Cache-Control', 'no-store');
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
		}
	}
}
