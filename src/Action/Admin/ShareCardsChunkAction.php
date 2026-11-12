<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Seo\Data\ShareCard;
use TotalCMS\Domain\Seo\Service\ShareCardPreviewer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * `GET /admin/utils/share-cards/cards?section=&offset=` — one chunk of a
 * Share Cards section as HTML. `X-Share-Cards-Next` carries the next
 * offset ('' after the last chunk) and `X-Share-Cards-Problems` how many
 * cards in this chunk need a look, so the page can chain the requests and
 * keep its counts without parsing the markup. A page that is not an HTML
 * document (a feed, robots.txt) has no card, so it is left out.
 */
readonly class ShareCardsChunkAction
{
	public function __construct(
		private ShareCardPreviewer $previewer,
		private TwigRenderer $twigRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$query   = $request->getQueryParams();
		$section = is_string($query['section'] ?? null) ? $query['section'] : '';
		$offset  = is_numeric($query['offset'] ?? null) ? (int)$query['offset'] : 0;

		try {
			$chunk = $this->previewer->chunk($section, $offset);
		} catch (\DomainException $e) {
			throw new HttpNotFoundException($request, $e->getMessage());
		}

		$cards    = array_values(array_filter($chunk['cards'], static fn (ShareCard $card): bool => $card->head->document));
		$problems = count(array_filter($cards, static fn (ShareCard $card): bool => $card->isProblem()));

		return $this->twigRenderer->template($response, 'admin/utils/share-cards/cards.twig', ['cards' => $cards])
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withHeader('Cache-Control', 'no-store')
			->withHeader('X-Share-Cards-Next', $chunk['next'] === null ? '' : (string)$chunk['next'])
			->withHeader('X-Share-Cards-Problems', (string)$problems);
	}
}
