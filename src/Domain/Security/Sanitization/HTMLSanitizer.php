<?php

namespace TotalCMS\Domain\Security\Sanitization;

/**
 * HTML Sanitizer for preventing XSS attacks while preserving safe HTML.
 *
 * The security work happens in sanitizeTags(), which reads every tag the way
 * a browser's tokenizer does (quotes, `/` separators, attributes that follow a
 * quoted value with no space) and decides per tag and per attribute. It runs
 * last, so no earlier removal can stitch two fragments into a new tag behind
 * its back. The regex passes before it only tidy up element content (the
 * text inside a removed <script>, a disallowed <iframe>, …); they are not
 * what keeps script out.
 *
 * Attribute values are judged after HTML character references are decoded,
 * because that is what the browser does before it reads a URL scheme:
 * `javas&#99;ript:` is `javascript:` to the browser.
 */
class HTMLSanitizer
{
	/** Href value written in place of a link whose scheme is not allowed. */
	public const SANITIZED_HREF = '#sanitized';

	/** Elements removed outright, whatever their attributes. */
	private const DROPPED_TAGS = [
		'script', 'style', 'object', 'embed', 'applet', 'form', 'input', 'link', 'meta', 'base',
		'frame', 'frameset',
	];

	/** Elements whose content is removed along with the element. */
	private const DROPPED_WITH_CONTENT = ['script', 'style', 'object', 'embed', 'applet', 'form'];

	/** Attributes whose value the browser treats as a URL. */
	private const URL_ATTRIBUTES = [
		'href', 'xlink:href', 'src', 'action', 'formaction', 'poster', 'background', 'cite',
		'longdesc', 'lowsrc', 'dynsrc', 'data', 'codebase', 'classid', 'ping', 'manifest', 'icon',
		'profile', 'archive',
	];

	/** URL attributes that keep the element usable with a placeholder value. */
	private const LINK_ATTRIBUTES = ['href', 'xlink:href'];

	/** URL attributes that may carry an inline `data:image/...` URL. */
	private const MEDIA_ATTRIBUTES = ['src', 'poster'];

	/** Substrings that make any attribute value dangerous once decoded. */
	private const DANGEROUS_VALUES = ['javascript:', 'vbscript:', 'livescript:', 'data:text/html'];

	private const WS = '\t\n\f\r ';

	/**
	 * @param array<string,mixed> $config
	 */
	public static function sanitizeRichContent(string $html, array $config = []): string
	{
		return self::sanitize($html, $config, []);
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function sanitizeStrictContent(string $html, array $config = []): string
	{
		// Strict mode removes all styles and these extra tags on top of rich mode.
		return self::sanitize(
			$html,
			['allowed_css_properties' => []],
			['div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']
		);
	}

	/**
	 * @param array<string,mixed> $config
	 * @param list<string>        $deniedTags
	 */
	private static function sanitize(string $html, array $config, array $deniedTags): string
	{
		if ($html === '') {
			return '';
		}

		$html = self::removeDroppedContent($html);
		$html = self::removeDisallowedIframes($html, $config);

		return self::sanitizeTags($html, $config, $deniedTags);
	}

	/**
	 * Tidy-up only: remove `<script>…</script>` and the like together with
	 * their content, so the code does not linger as visible text. Tags these
	 * patterns miss are still removed by sanitizeTags().
	 */
	private static function removeDroppedContent(string $html): string
	{
		foreach (self::DROPPED_WITH_CONTENT as $tag) {
			$html = (string)preg_replace("/<{$tag}\\b[^>]*>.*?<\\/{$tag}\\b[^>]*>/is", '', $html);
		}

		return $html;
	}

	/**
	 * Tidy-up only: remove a disallowed iframe together with its fallback
	 * content, leaving allowed iframes where they are. sanitizeTags() removes
	 * any disallowed iframe tag this misses (e.g. one with no closing tag).
	 *
	 * @param array<string,mixed> $config
	 */
	private static function removeDisallowedIframes(string $html, array $config): string
	{
		return (string)preg_replace_callback(
			'/<iframe\b[^>]*>.*?<\/iframe\b[^>]*>/is',
			static function (array $m) use ($config): string {
				// Judge the opening tag only, not the fallback content after it.
				preg_match(self::tagPattern(), $m[0], $open);
				$attributes = self::parseTag($open[0] ?? '')['attributes'] ?? [];

				return self::isAllowedIframe($attributes, $config) ? $m[0] : '';
			},
			$html
		);
	}

	/**
	 * Walk every tag and keep only what is safe. Quoted attribute values that
	 * survive are sanitized again as HTML: markup inside a value the browser
	 * might read as real tags (after a comment or <textarea> ends, say) is
	 * then checked too.
	 *
	 * @param array<string,mixed> $config
	 * @param list<string>        $deniedTags
	 */
	private static function sanitizeTags(string $html, array $config, array $deniedTags): string
	{
		// Removing a tag can join what was either side of it into a new one
		// (`<<b>img onerror=…>` loses `<b>` and leaves `<img onerror=…>`), so
		// repeat until a pass changes nothing. Real content settles in one or
		// two passes; if it never settles, show every `<` as text.
		for ($pass = 0; $pass < 10; $pass++) {
			$clean = (string)preg_replace_callback(
				self::tagPattern(),
				static fn (array $m): string => self::sanitizeTag($m[0], $config, $deniedTags),
				$html
			);
			if ($clean === $html) {
				return $clean;
			}
			$html = $clean;
		}

		return str_replace('<', '&lt;', $html);
	}

	/**
	 * @param array<string,mixed> $config
	 * @param list<string>        $deniedTags
	 */
	private static function sanitizeTag(string $raw, array $config, array $deniedTags): string
	{
		$tag = self::parseTag($raw);

		// A tag cut off by the end of the value would swallow whatever the page
		// prints after it. Show it as text instead.
		if ($tag === null || !$tag['closed']) {
			return '&lt;' . substr($raw, 1);
		}

		$name = strtolower($tag['name']);
		if (!self::isAllowedTag($name, $config, $deniedTags)) {
			return '';
		}

		if ($tag['end']) {
			return '</' . $tag['name'] . '>';
		}

		if ($name === 'iframe' && !self::isAllowedIframe($tag['attributes'], $config)) {
			return '';
		}

		$out = '<' . $tag['name'];
		foreach ($tag['attributes'] as $attribute) {
			$value = self::sanitizeAttribute($attribute, $config, $deniedTags);
			if ($value === null) {
				continue;
			}
			// Always keep a separator: dropping the attribute before this one
			// must not glue its name onto the previous value.
			$out .= ($attribute['separator'] === '' ? ' ' : $attribute['separator'])
				. $attribute['name'] . $value;
		}

		return $out . $tag['trailing'] . '>';
	}

	/**
	 * Returns the `="value"` part to keep (empty string for a value-less
	 * attribute), or null to drop the attribute.
	 *
	 * @param array{separator:string,name:string,equals:string,value:string} $attribute
	 * @param array<string,mixed>                                            $config
	 * @param list<string>                                                   $deniedTags
	 */
	private static function sanitizeAttribute(array $attribute, array $config, array $deniedTags): ?string
	{
		$name = strtolower($attribute['name']);

		if ((strlen($name) > 2 && str_starts_with($name, 'on')) || $name === 'srcdoc') {
			return null;
		}

		if ($attribute['equals'] === '') {
			return '';
		}

		$raw     = $attribute['value'];
		$quote   = ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) ? $raw[0] : '';
		$inner   = $quote === '' ? $raw : substr($raw, 1, -1);
		$decoded = self::decodeEntities($inner);

		if (in_array($name, self::URL_ATTRIBUTES, true)) {
			// The scheme decides for a URL: `https://…?q=javascript:` is fine.
			if (!self::isAllowedUrl($decoded, $name, $config)) {
				return in_array($name, self::LINK_ATTRIBUTES, true)
					? $attribute['equals'] . '"' . self::SANITIZED_HREF . '"'
					: null;
			}
		} elseif (!self::isSafeValue($name, $decoded, $config)) {
			return null;
		}

		if ($quote !== '') {
			$inner = self::sanitizeTags($inner, $config, $deniedTags);
		}

		return $attribute['equals'] . $quote . $inner . $quote;
	}

	/**
	 * Any other attribute: a script scheme anywhere in the decoded value drops
	 * it (SVG `<animate values="javascript:…">` sets an href that way).
	 *
	 * @param array<string,mixed> $config
	 */
	private static function isSafeValue(string $name, string $decoded, array $config): bool
	{
		if ($name === 'style' && !self::isAllowedStyle($decoded, $config)) {
			return false;
		}

		$normalized = strtolower(self::stripControls($decoded));
		foreach (self::DANGEROUS_VALUES as $needle) {
			if (str_contains($normalized, $needle)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $config
	 * @param list<string>        $deniedTags
	 */
	private static function isAllowedTag(string $name, array $config, array $deniedTags): bool
	{
		if (in_array($name, self::DROPPED_TAGS, true) || in_array($name, $deniedTags, true)) {
			return false;
		}

		if (isset($config['allowed_tags']) && is_array($config['allowed_tags'])) {
			$allowed = array_map(strtolower(...), array_filter($config['allowed_tags'], is_string(...)));

			return in_array($name, $allowed, true);
		}

		return true;
	}

	/**
	 * An iframe stays only when its src is http(s) on an allowed host, matched
	 * exactly (so `youtube.com.example.net` is not `youtube.com`).
	 *
	 * @param list<array{separator:string,name:string,equals:string,value:string}> $attributes
	 * @param array<string,mixed>                                                  $config
	 */
	private static function isAllowedIframe(array $attributes, array $config): bool
	{
		$domains = $config['allowed_iframe_domains'] ?? [];
		if (!is_array($domains) || $domains === []) {
			$domains = HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS;
		}
		$domains = array_map(strtolower(...), array_filter($domains, is_string(...)));

		foreach ($attributes as $attribute) {
			if (strtolower($attribute['name']) !== 'src') {
				continue;
			}
			$src    = self::stripControls(self::decodeEntities(trim($attribute['value'], '"\'')));
			$scheme = strtolower((string)parse_url($src, PHP_URL_SCHEME));
			$host   = strtolower((string)parse_url($src, PHP_URL_HOST));

			// Only the first src counts — that is the one the browser uses.
			return in_array($scheme, ['http', 'https'], true) && in_array($host, $domains, true);
		}

		return false;
	}

	/**
	 * Relative URLs (no scheme) are allowed; otherwise the scheme must be on
	 * the allowlist. Media attributes may also carry an inline raster image.
	 *
	 * @param array<string,mixed> $config
	 */
	private static function isAllowedUrl(string $decoded, string $attribute, array $config): bool
	{
		$url = self::stripControls($decoded);

		if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
			return true;
		}

		$scheme = strtolower($m[1]);

		if ($scheme === 'data') {
			return in_array($attribute, self::MEDIA_ATTRIBUTES, true)
				&& preg_match('#^data:image/(?:png|gif|jpe?g|webp|avif|bmp)[;,]#i', $url) === 1;
		}

		$schemes = $config['allowed_url_schemes'] ?? [];
		if (!is_array($schemes) || $schemes === []) {
			$schemes = HTMLSanitizerConfig::ALLOWED_URL_SCHEMES;
		}

		return in_array($scheme, array_map(strtolower(...), array_filter($schemes, is_string(...))), true);
	}

	/**
	 * Inline styles are dropped when no CSS properties are allowed, and always
	 * when they hold an IE `expression()`.
	 *
	 * @param array<string,mixed> $config
	 */
	private static function isAllowedStyle(string $decoded, array $config): bool
	{
		if (empty($config['allowed_css_properties'] ?? [])) {
			return false;
		}

		return !preg_match('/expression\s*\(/i', (string)preg_replace('#/\*.*?\*/#s', '', $decoded));
	}

	/**
	 * Decode character references the way a browser does inside an attribute
	 * value: numeric references with or without the trailing semicolon, named
	 * references with it. One pass, so `&amp;#106;` stays `&#106;`.
	 */
	private static function decodeEntities(string $value): string
	{
		if (!str_contains($value, '&')) {
			return $value;
		}

		return (string)preg_replace_callback(
			'/&(?:#[xX]([0-9a-fA-F]+);?|#([0-9]+);?|([a-zA-Z][a-zA-Z0-9]*;))/',
			static function (array $m): string {
				if (($m[3] ?? '') !== '') {
					return html_entity_decode('&' . $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				}

				$digits = $m[1] !== '' ? ltrim($m[1], '0') : ltrim($m[2], '0');
				if (strlen($digits) > 7) {
					return "\u{FFFD}";
				}
				$codepoint = $m[1] !== '' ? (int)hexdec($m[1]) : (int)$m[2];

				return ($codepoint > 0 && $codepoint < 0x80) ? chr($codepoint) : "\u{FFFD}";
			},
			$value
		);
	}

	/**
	 * Remove ASCII control characters and spaces anywhere in the value. The
	 * browser ignores tabs and newlines inside a URL and trims the rest at the
	 * ends; removing them everywhere is stricter and keeps the check simple.
	 */
	private static function stripControls(string $value): string
	{
		return (string)preg_replace('/[\x00-\x20\x7F]+/', '', $value);
	}

	/**
	 * Matches one tag the way the HTML tokenizer reads it: `<` or `</`, a
	 * name, then attributes whose values may be double-quoted, single-quoted
	 * (and may contain `>`) or unquoted. A tag cut off by the end of the
	 * string matches too, so it can be neutralised. Possessive quantifiers
	 * keep it linear.
	 */
	private static function tagPattern(): string
	{
		$ws = self::WS;

		return '~</?[a-zA-Z][^' . $ws . '/>]*+'
			. '(?:[' . $ws . '/]++'
			. '|[^' . $ws . '/>][^' . $ws . '/=>]*+'
			. '(?:[' . $ws . ']*+=[' . $ws . ']*+(?:"[^"]*+(?:"|\z)|\'[^\']*+(?:\'|\z)|[^' . $ws . '>]*+))?+'
			. ')*+(?:>|\z)~';
	}

	/**
	 * Split one tag into its parts. Returns null when the text is not a tag.
	 *
	 * @return array{end:bool,name:string,attributes:list<array{separator:string,name:string,equals:string,value:string}>,trailing:string,closed:bool}|null
	 */
	private static function parseTag(string $raw): ?array
	{
		$ws = self::WS;

		if (!preg_match('~^<(/?)([a-zA-Z][^' . $ws . '/>]*+)~', $raw, $head)) {
			return null;
		}

		$closed = str_ends_with($raw, '>') && self::endsCleanly($raw);
		$body   = substr($raw, strlen($head[0]), $closed ? -1 : null);

		preg_match_all(
			'~([' . $ws . '/]*+)([^' . $ws . '/>][^' . $ws . '/=>]*+)'
			. '(?:([' . $ws . ']*+=[' . $ws . ']*+)("[^"]*+(?:"|\z)|\'[^\']*+(?:\'|\z)|[^' . $ws . '>]*+))?+~',
			$body,
			$matches,
			PREG_SET_ORDER
		);

		$attributes = [];
		$consumed   = 0;
		foreach ($matches as $match) {
			$attributes[] = [
				'separator' => $match[1],
				'name'      => $match[2],
				'equals'    => $match[3] ?? '',
				'value'     => $match[4] ?? '',
			];
			$consumed += strlen($match[0]);
		}

		return [
			'end'        => $head[1] === '/',
			'name'       => $head[2],
			'attributes' => $attributes,
			'trailing'   => substr($body, $consumed),
			'closed'     => $closed,
		];
	}

	/**
	 * A tag that reached the end of the string inside a quoted value ends in
	 * `>` only by accident (`<a title="x>`); it is not closed.
	 */
	private static function endsCleanly(string $raw): bool
	{
		$ws = self::WS;

		return preg_match(
			'~^</?[a-zA-Z][^' . $ws . '/>]*+'
			. '(?:[' . $ws . '/]++'
			. '|[^' . $ws . '/>][^' . $ws . '/=>]*+'
			. '(?:[' . $ws . ']*+=[' . $ws . ']*+(?:"[^"]*+"|\'[^\']*+\'|[^' . $ws . '>]*+))?+'
			. ')*+>\z~',
			$raw
		) === 1;
	}
}
