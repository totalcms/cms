<?php

declare(strict_types=1);

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Rendering\Service\FragmentRenderer;
use TotalCMS\Renderer\RawRenderer;

/**
 * The HTMX branch of the object write actions.
 *
 * A save, update or patch keeps returning JSON unless the request is an htmx
 * request that also names a `template`; then the saved object is rendered
 * through that template and returned as HTML, so a `<form hx-post>` can swap
 * in a thank-you block or the fresh display fragment with no JavaScript of
 * its own. Validation failures never reach here — they throw, and
 * DefaultErrorHandler renders them as an error fragment for htmx callers.
 */
final readonly class FragmentResponder
{
	public function __construct(
		private FragmentRenderer $fragments,
		private RawRenderer $rawRenderer,
	) {
	}

	public function wants(ServerRequestInterface $request): bool
	{
		return $request->getHeaderLine('HX-Request') === 'true' && FragmentRenderer::templateFrom($request) !== '';
	}

	public function respond(ServerRequestInterface $request, ResponseInterface $response, ObjectData $object, string $collection): ResponseInterface
	{
		$html = $this->fragments->render($request, FragmentRenderer::templateFrom($request), [
			'object'     => $object->toArray(),
			'collection' => $collection,
		]);

		return $this->rawRenderer->render($response->withHeader('Content-Type', 'text/html'), $html);
	}
}
