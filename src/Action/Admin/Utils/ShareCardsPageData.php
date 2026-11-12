<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Seo\Service\ShareCardPreviewer;

/**
 * The Share Cards utility's frame: the sections and their counts, the
 * collections skipped, and a note when shares point at another host. The
 * cards themselves load a chunk at a time (ShareCardsChunkAction).
 */
final readonly class ShareCardsPageData implements UtilsPageData
{
	public function __construct(
		private ShareCardPreviewer $previewer,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$result = $this->previewer->sections();

		return ['shareCards' => [
			'builder'  => $this->previewer->hasBuilder(),
			'sections' => $result['sections'],
			'skipped'  => $result['skipped'],
			'chunk'    => ShareCardPreviewer::CHUNK,
		]];
	}
}
