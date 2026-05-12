<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Sanitization;

/**
 * Configuration constants for HTML sanitization.
 */
class HTMLSanitizerConfig
{
	public const RICH_CONTENT_ALLOWED_TAGS = [
		'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'mark', 'del', 'ins', 'sub', 'sup',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a', 'img', 'figure', 'figcaption',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
		'blockquote', 'cite', 'q', 'code', 'pre', 'kbd', 'samp', 'var',
		'div', 'span', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav',
		'audio', 'video', 'source', 'track', 'fieldset', 'legend', 'label',
		'abbr', 'acronym', 'address', 'time', 'details', 'summary',
	];

	public const STRICT_CONTENT_ALLOWED_TAGS = [
		'p', 'br', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li', 'code',
	];

	public const ALLOWED_CSS_PROPERTIES = [
		'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration',
		'text-align', 'text-transform', 'line-height', 'letter-spacing',
		'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
		'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
		'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height',
		'display', 'float', 'clear', 'position', 'top', 'right', 'bottom', 'left',
		'z-index', 'overflow', 'overflow-x', 'overflow-y',
		'background', 'background-color', 'background-image', 'background-repeat',
		'background-position', 'background-size',
		'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
		'border-width', 'border-style', 'border-color', 'border-radius',
		'list-style', 'list-style-type', 'list-style-position', 'list-style-image',
		'border-collapse', 'border-spacing', 'caption-side', 'empty-cells', 'table-layout',
		'opacity', 'visibility', 'white-space', 'word-wrap', 'word-break',
	];

	public const ALLOWED_IFRAME_DOMAINS = [
		'www.youtube.com', 'youtube.com', 'player.vimeo.com', 'vimeo.com',
		'www.dailymotion.com', 'dailymotion.com', 'embed.ted.com', 'www.ted.com',
		'codepen.io', 'jsfiddle.net', 'github.com', 'gist.github.com',
	];

	public const DANGEROUS_MIME_TYPES = [
		'application/x-php', 'application/x-httpd-php', 'application/php', 'application/x-sh',
		'application/x-csh', 'text/x-php', 'text/x-shellscript', 'application/x-executable',
		'application/x-msdownload', 'application/x-msdos-program', 'application/x-ms-dos-executable',
		'application/x-winexe', 'application/x-javascript', 'text/javascript',
		'application/javascript', 'text/vbscript', 'application/x-vbscript',
	];
}
