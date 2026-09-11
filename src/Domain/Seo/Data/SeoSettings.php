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
	 * @param list<string> $sameAs
	 * @param array{google:string,bing:string,pinterest:string} $verification
	 */
	public function __construct(
		public string $siteName,
		public string $baseUrl,
		public string $titleTemplate,
		public string $titleSeparator,
		public string $defaultDescription,
		public string $defaultImage,
		public string $defaultImageAlt,
		public string $twitterHandle,
		public string $organizationName,
		public string $organizationLogo,
		public array $sameAs,
		public string $contactEmail,
		public string $contactUrl,
		public array $verification,
		public bool $emitJsonLd,
		public bool $emitSocial,
	) {
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
		$str = static fn (string $key): string => trim((string)($data[$key] ?? ''));
		$baseUrl = rtrim($str('baseUrl') !== '' ? $str('baseUrl') : $fallbackDomain, '/');
		if (!preg_match('#^https?://#i', $baseUrl)) {
			$baseUrl = 'https://' . $baseUrl;
		}
		$handle = ltrim($str('twitterHandle'), '@');
		$sameAs = array_values(array_filter(array_map('trim', preg_split('/\R/', $str('sameAs')) ?: []), static fn (string $u): bool => $u !== ''));

		return new self(
			siteName: $str('siteName'),
			baseUrl: $baseUrl,
			titleTemplate: $str('titleTemplate') !== '' ? $str('titleTemplate') : '{title} | {site}',
			titleSeparator: $str('titleSeparator') !== '' ? $str('titleSeparator') : '|',
			defaultDescription: $str('defaultDescription'),
			defaultImage: $str('defaultImage'),
			defaultImageAlt: $str('defaultImageAlt'),
			twitterHandle: $handle === '' ? '' : '@' . $handle,
			organizationName: $str('organizationName'),
			organizationLogo: $str('organizationLogo'),
			sameAs: $sameAs,
			contactEmail: $str('contactEmail'),
			contactUrl: $str('contactUrl'),
			verification: ['google' => $str('googleVerification'), 'bing' => $str('bingVerification'), 'pinterest' => $str('pinterestVerification')],
			emitJsonLd: !array_key_exists('emitJsonLd', $data) || filter_var($data['emitJsonLd'], FILTER_VALIDATE_BOOL),
			emitSocial: !array_key_exists('emitSocial', $data) || filter_var($data['emitSocial'], FILTER_VALIDATE_BOOL),
		);
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
			titleSeparator: $this->titleSeparator,
			defaultDescription: $this->defaultDescription,
			defaultImage: $this->defaultImage,
			defaultImageAlt: $this->defaultImageAlt,
			twitterHandle: $this->twitterHandle,
			organizationName: $this->organizationName,
			organizationLogo: $this->organizationLogo,
			sameAs: $this->sameAs,
			contactEmail: $this->contactEmail,
			contactUrl: $this->contactUrl,
			verification: $this->verification,
			emitJsonLd: $this->emitJsonLd,
			emitSocial: $this->emitSocial,
		);
	}
}
