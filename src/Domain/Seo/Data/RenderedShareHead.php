<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * What one page's real template put in its head, or, when the template did
 * not render, what its record works out to (`$fromRecord`, with `$note`
 * saying why). ShareCardRenderer builds it; ShareCardPreviewer turns it into
 * a ShareCard. `$failed` is a template that errored (a decline such as
 * cms.notFound() is not), and `$document` is false for a page that is not
 * an HTML document with a head (a feed, robots.txt): shares don't apply.
 */
final readonly class RenderedShareHead
{
	public function __construct(
		public string $ogImage,
		public string $ogTitle,
		public string $ogDescription,
		public ?SocialCardReason $reason,
		public string $detail = '',
		public ?int $layer = null,
		public bool $fromRecord = false,
		public string $note = '',
		public bool $failed = false,
		public bool $document = true,
	) {
	}
}
