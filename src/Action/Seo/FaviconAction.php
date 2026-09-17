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
 * `/favicon.ico` and `/favicon.svg`, both answered from the Site SEO record.
 *
 * Browsers and crawlers request `/favicon.ico` on their own, whatever the head
 * says, so it is served rather than left as a 404 in every log: the Icon at
 * 32px, as ImageWorks already renders it for the head, wrapped as an ICO. The
 * SVG has a route of its own because the download route hands files over as
 * attachments, and an icon has to arrive inline.
 *
 * With no Icon on the record both are 404s — the same answer a site gave
 * before the record existed.
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

		if (($args['format'] ?? 'ico') === 'svg') {
			return $this->svg($request, $response, $settings);
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
