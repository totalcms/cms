<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * The Site SEO record — the `seo-site` singleton — typed and normalised once so
 * MetaBuilder and the JSON-LD providers never look at the raw record.
 */
final readonly class SeoSettings
{
	/**
	 * Open Graph's recommended 1.91:1 share image. Every share image the site
	 * emits — the site default and each object's — is built to this geometry.
	 */
	public const OG_IMAGE = ['w' => 1200, 'h' => 630, 'fit' => 'crop-focalpoint'];

	/**
	 * The organization logo. Unlike a share image a logo must never be cropped —
	 * Google's Organization guidance asks for the mark as-is (any aspect ratio,
	 * at least 112×112), so this only bounds the width and lets ImageWorks keep
	 * the aspect ratio. `max` rather than `contain`: same bounding, but a logo
	 * narrower than 600px is served at its own size instead of upscaled.
	 */
	public const LOGO_IMAGE = ['w' => 600, 'fit' => 'max'];

	/**
	 * The icon set, all cut from the one square Icon upload: the classic 32px
	 * tab icon (also wrapped as /favicon.ico), the 192 and 512 sizes Android
	 * and Google's result pages read, and the 180px Apple touch icon — which
	 * comes from the Touch Icon upload when there is one, since iOS paints
	 * transparent pixels black and a tab icon is usually transparent. PNG is
	 * forced: a favicon is one of the few places WebP is still not universal.
	 */
	public const ICON_32    = ['w' => 32, 'h' => 32, 'fit' => 'crop-focalpoint', 'fm' => 'png'];
	public const ICON_192   = ['w' => 192, 'h' => 192, 'fit' => 'crop-focalpoint', 'fm' => 'png'];
	public const ICON_512   = ['w' => 512, 'h' => 512, 'fit' => 'crop-focalpoint', 'fm' => 'png'];
	public const TOUCH_ICON = ['w' => 180, 'h' => 180, 'fit' => 'crop-focalpoint', 'fm' => 'png'];

	/**
	 * The touch icon cut from the Icon when no Touch Icon was uploaded. iOS
	 * paints transparent pixels black, so the Theme Color is painted behind
	 * the icon first — the home-screen tile then matches the site's chrome —
	 * and black is used when there is no Theme Color, which is what iOS would
	 * have shown anyway, only now on purpose.
	 *
	 * @return array<string,int|string>
	 */
	public static function touchIconFromIcon(mixed $themeColor): array
	{
		$hex = self::hex($themeColor);
		$hex = $hex === '' ? '000000' : ltrim($hex, '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return self::TOUCH_ICON + ['bg' => $hex];
	}

	/**
	 * @param list<string> $sameAs
	 * @param string $metaTags Raw markup for the head, emitted as written after the SEO tags
	 * @param string $iconSvg Absolute URL of the SVG icon (`/favicon.svg`), or `''`
	 * @param string $icon32 Absolute ImageWorks URLs of the PNG icon set, or `''` when there is no Icon
	 * @param string $touchIcon The 180px Apple touch icon: the Touch Icon upload, else the Icon, else `''`
	 * @param string $themeColor Lower-case hex, or `''`
	 * @param string $manifest Absolute URL of the web app manifest, when a Site Builder page routes `/manifest.webmanifest`; else `''`
	 * @param string $touchIconProperty The record property the touch icon is cut from: `touchIcon` when uploaded, else `icon`, else `''`
	 */
	public function __construct(
		public string $siteName,
		public string $baseUrl,
		public string $titleTemplate,
		public string $socialTitleTemplate,
		public string $defaultDescription,
		public string $defaultImage,
		public string $defaultImageAlt,
		public string $twitterHandle,
		public string $organizationName,
		public string $organizationLogo,
		public array $sameAs,
		public string $contactEmail,
		public string $contactUrl,
		public string $metaTags,
		public bool $emitJsonLd,
		public bool $emitSocial,
		public string $iconSvg = '',
		public string $icon32 = '',
		public string $icon192 = '',
		public string $icon512 = '',
		public string $touchIcon = '',
		public string $themeColor = '',
		public string $manifest = '',
		public string $touchIconProperty = '',
	) {
	}

	/** Whether the head has any icon tag to print. The Icon is what makes that true. */
	public function hasIcons(): bool
	{
		return $this->icon32 !== '' || $this->iconSvg !== '';
	}

	/** Whether the icons slice has anything at all to print. */
	public function hasIconSlice(): bool
	{
		return $this->hasIcons() || $this->manifest !== '' || $this->themeColor !== '';
	}

	/**
	 * Absolutize a path against the site's base URL. An address that is already
	 * absolute (a CDN, another host) is passed through untouched rather than
	 * prefixed a second time, and an empty value stays empty.
	 *
	 * The one place a relative path becomes an absolute one: canonical URLs,
	 * the site's default share image and every per-object image go through it,
	 * so they cannot disagree about the origin.
	 */
	public function absolute(string $pathOrUrl): string
	{
		if ($pathOrUrl === '' || preg_match('#^https?://#i', $pathOrUrl) === 1) {
			return $pathOrUrl;
		}

		return $this->baseUrl . '/' . ltrim($pathOrUrl, '/');
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data, string $fallbackDomain): self
	{
		$str     = static fn (string $key): string => trim((string)($data[$key] ?? ''));
		$baseUrl = rtrim($str('baseUrl') !== '' ? $str('baseUrl') : $fallbackDomain, '/');
		if (!preg_match('#^https?://#i', $baseUrl)) {
			$baseUrl = 'https://' . $baseUrl;
		}
		$handle = ltrim($str('twitterHandle'), '@');
		$sameAs = array_values(array_filter(array_map(trim(...), preg_split('/\R/', $str('sameAs')) ?: []), static fn (string $u): bool => $u !== ''));

		return new self(
			siteName: $str('siteName'),
			baseUrl: $baseUrl,
			titleTemplate: $str('titleTemplate') !== '' ? $str('titleTemplate') : '${title} | ${site}',
			socialTitleTemplate: $str('socialTitleTemplate') !== '' ? $str('socialTitleTemplate') : '${title}',
			defaultDescription: $str('defaultDescription'),
			defaultImage: $str('defaultImage'),
			defaultImageAlt: $str('defaultImageAlt'),
			twitterHandle: $handle === '' ? '' : '@' . $handle,
			organizationName: $str('organizationName'),
			organizationLogo: $str('organizationLogo'),
			sameAs: $sameAs,
			contactEmail: $str('contactEmail'),
			contactUrl: $str('contactUrl'),
			metaTags: $str('metaTags'),
			emitJsonLd: !array_key_exists('emitJsonLd', $data) || filter_var($data['emitJsonLd'], FILTER_VALIDATE_BOOL),
			emitSocial: !array_key_exists('emitSocial', $data) || filter_var($data['emitSocial'], FILTER_VALIDATE_BOOL),
			// The loader resolves the record's image and file properties to URLs
			// under these keys; the raw record never carries them.
			iconSvg: $str('iconSvgUrl'),
			icon32: $str('icon32'),
			icon192: $str('icon192'),
			icon512: $str('icon512'),
			touchIcon: $str('touchIcon180'),
			themeColor: self::hex($data['themeColor'] ?? null),
			manifest: $str('manifestUrl'),
			touchIconProperty: $str('touchIconProperty'),
		);
	}

	/**
	 * A hex color, lower-cased, from the color field's `{hex, oklch}` object
	 * or a plain string. The field is clearable, so '' means no tag. Anything
	 * that is not a hex color is dropped rather than printed.
	 */
	private static function hex(mixed $color): string
	{
		$hex = is_array($color) ? ($color['hex'] ?? '') : $color;
		$hex = strtolower(trim((string)$hex));

		return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $hex) === 1 ? $hex : '';
	}

	/**
	 * The same settings with a different site name — the loader's General
	 * settings / domain fallback, applied after the record is normalised.
	 */
	public function withSiteName(string $siteName): self
	{
		return new self(
			siteName: $siteName,
			baseUrl: $this->baseUrl,
			titleTemplate: $this->titleTemplate,
			socialTitleTemplate: $this->socialTitleTemplate,
			defaultDescription: $this->defaultDescription,
			defaultImage: $this->defaultImage,
			defaultImageAlt: $this->defaultImageAlt,
			twitterHandle: $this->twitterHandle,
			organizationName: $this->organizationName,
			organizationLogo: $this->organizationLogo,
			sameAs: $this->sameAs,
			contactEmail: $this->contactEmail,
			contactUrl: $this->contactUrl,
			metaTags: $this->metaTags,
			emitJsonLd: $this->emitJsonLd,
			emitSocial: $this->emitSocial,
			iconSvg: $this->iconSvg,
			icon32: $this->icon32,
			icon192: $this->icon192,
			icon512: $this->icon512,
			touchIcon: $this->touchIcon,
			themeColor: $this->themeColor,
			manifest: $this->manifest,
			touchIconProperty: $this->touchIconProperty,
		);
	}
}
