<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Seo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Admin\MediaToolAccess;
use TotalCMS\Domain\Seo\Service\OverlayPreviewer;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * `POST /admin/seo/overlay-preview` — the overlay designer's preview: a
 * draft overlay drawn on a record, without saving anything.
 *
 * Body: `{collection, id, overlay, size}`. The answer is always 200 for a
 * request it understood: `{reason, message, layer, asIs, image}`, where
 * `reason` and `message` are the ones Share Cards shows and `image` is a
 * data URI (null when there is no image to draw). The user must be able to
 * read the record, as for the ImageWorks tool.
 */
readonly class OverlayPreviewAction
{
	public function __construct(
		private OverlayPreviewer $previewer,
		private MediaToolAccess $access,
		private TranslationService $translator,
		private JsonRenderer $jsonRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = $request->getParsedBody();
		if (!is_array($body)) {
			$body = json_decode((string)$request->getBody(), true);
		}

		$collection = is_array($body) && is_string($body['collection'] ?? null) ? $body['collection'] : '';
		$id         = is_array($body) && is_string($body['id'] ?? null) ? $body['id'] : '';
		$overlay    = is_array($body) ? ($body['overlay'] ?? null) : null;
		$size       = is_array($body) && is_string($body['size'] ?? null) ? $body['size'] : 'full';

		if ($collection === '' || $id === '' || !is_array($overlay)) {
			throw new HttpBadRequestException($request, 'collection, id and overlay are required');
		}

		if (!$this->access->canRead($collection, $id)) {
			throw new HttpForbiddenException($request, 'Access denied');
		}

		$preview = $this->previewer->preview($collection, $id, $overlay, $size);

		return $this->jsonRenderer->json($response, [
			'reason'  => $preview->reason->value,
			'message' => $this->message($preview->messageKey(), $preview->detail, $preview->layer),
			'layer'   => $preview->layer,
			'asIs'    => $preview->asIs,
			'image'   => $preview->image,
		])->withHeader('Cache-Control', 'no-store');
	}

	/**
	 * The preview's own wording where Share Cards' would mislead (a draft
	 * with no layers, a record that can't be read, drawn, below Pro), else
	 * the Share Cards message, so the two say the same thing.
	 */
	private function message(string $key, string $detail, ?int $layer): string
	{
		$params = ['detail' => $detail, 'layer' => $layer === null ? '' : (string)($layer + 1)];
		$own    = 'overlay_preview.reason.' . $key;
		$text   = $this->translator->trans($own, $params);

		return $text !== $own ? $text : $this->translator->trans('share_cards.reason.' . $key, $params);
	}
}
