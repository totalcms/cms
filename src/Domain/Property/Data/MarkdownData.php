<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * The value of a `markdown` or `styledmarkdown` field (the two edit the same
 * value: one as source, one visually): a Markdown string, stored as written
 * unless the field sets `htmlclean: true`. The same default as CodeData —
 * both hold source that is rendered later.
 *
 * The HTML sanitizer that StringData runs would rewrite it — it strips
 * <script> and event attributes wherever they appear, including inside a
 * code fence, and trims the string. Markdown is made safe when it is
 * rendered instead: the |markdown filter runs Parsedown in safe mode, which
 * escapes every tag.
 */
class MarkdownData extends StringData
{
	/** Field names whose value is Markdown source. */
	public const FIELDS = ['markdown', 'styledmarkdown'];

	protected function normalize(): void
	{
		// Off unless the field asks for it: "settings": {"htmlclean": true}.
		if (($this->settings['htmlclean'] ?? false) === true) {
			parent::normalize();
		}
	}
}
