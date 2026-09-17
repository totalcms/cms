<?php

declare(strict_types=1);

namespace TotalCMS\Action\Seo;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\IcoEncoder;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

/**
 * `/favicon.ico`, `/favicon.svg` and `/apple-touch-icon.png`, all answered
 * from the Site SEO record.
 *
 * Browsers and crawlers request `/favicon.ico` on their own, whatever the head
 * says, so it is served rather than left as a 404 in every log: the Icon at
 * 32px, as ImageWorks already renders it for the head, wrapped as an ICO. iOS
 * asks for `/apple-touch-icon.png` on any page whose head has no touch-icon
 * link — a page that never calls the head helper — so that root path serves
 * the same 180px image the head would have linked. The SVG has a route of its
 * own because the download route hands files over as attachments, and the
 * address should not bake in the record's collection, id and property.
 *
 * With no Icon on the record all three are 404s — the same answer a site
 * gave before the record existed.
 */
final readonly class FaviconAction
{
	private const RECORD = 'seo-site';

	public function __construct(
		private SeoSettingsLoader $settings,
		private ImageGenerator $images,
		private FileFetcher $files,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$settings = $this->settings->load();
		if ($settings->icon32 === '') {
			throw new HttpNotFoundException($request, 'No icon on the Site SEO record');
		}

		$response = $response->withHeader('Cache-Control', 'public, max-age=86400');

		$format = $args['format'] ?? 'ico';
		if ($format === 'svg') {
			return $this->svg($request, $response, $settings);
		}
		if ($format === 'png') {
			return $this->touchIcon($request, $response, $settings);
		}

		try {
			$png = (string)$this->images->generateImage(self::RECORD, self::RECORD, 'icon', SeoSettings::ICON_32, $request)->getBody();
			$ico = IcoEncoder::fromPng($png);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Icon not found: ' . $e->getMessage());
		}

		return $response
			->withHeader('Content-Type', 'image/x-icon')
			->withHeader('Content-Length', (string)strlen($ico))
			->withBody(Stream::create($ico));
	}

	/** The 180px touch icon, from the Touch Icon upload or the Icon, as the head links it. */
	private function touchIcon(ServerRequestInterface $request, ResponseInterface $response, SeoSettings $settings): ResponseInterface
	{
		if ($settings->touchIconProperty === '') {
			throw new HttpNotFoundException($request, 'No touch icon on the Site SEO record');
		}

		try {
			$image = $this->images->generateImage(self::RECORD, self::RECORD, $settings->touchIconProperty, SeoSettings::TOUCH_ICON, $request);
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'Touch icon not found: ' . $e->getMessage());
		}

		return $response
			->withHeader('Content-Type', 'image/png')
			->withBody($image->getBody());
	}

	private function svg(ServerRequestInterface $request, ResponseInterface $response, SeoSettings $settings): ResponseInterface
	{
		if ($settings->iconSvg === '') {
			throw new HttpNotFoundException($request, 'No SVG icon on the Site SEO record');
		}

		try {
			$stream = $this->files->streamFile(self::RECORD, self::RECORD, 'iconSvg');
		} catch (\Exception $e) {
			throw new HttpNotFoundException($request, 'SVG icon not found: ' . $e->getMessage());
		}

		return $response
			->withHeader('Content-Type', 'image/svg+xml')
			->withHeader('Content-Disposition', 'inline; filename="favicon.svg"')
			->withBody(Stream::create($stream));
	}
}
