<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Support\Config;

/**
 * The loader is the single reader of the `seo-site` singleton record. It has to
 * survive a site that has never provisioned the collection, one that has the
 * collection but no record yet, and it has to hand SeoSettings absolute image
 * URLs rather than the raw image objects the record stores.
 */
final class SeoSettingsLoaderTest extends TestCase
{
	/**
	 * @param array<string,mixed>|null $record        null = the record cannot be fetched
	 * @param array<string,mixed>      $general       the General settings section
	 * @param bool                     $resolveImages whether the media adapter returns a path
	 */
	private function loader(bool $collectionExists, ?array $record, array $general = [], bool $resolveImages = false): SeoSettingsLoader
	{
		$collections = $this->createMock(CollectionFetcher::class);
		$collections->method('collectionExists')->with('seo-site')->willReturn($collectionExists);

		$objects = $this->createMock(ObjectFetcher::class);
		if ($record === null) {
			$objects->method('fetchObject')->willThrowException(new \UnexpectedValueException('Unable to fetch object seo-site/seo-site'));
		} else {
			$object = $this->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($record);
			$objects->method('fetchObject')->with('seo-site', 'seo-site')->willReturn($object);
		}

		$media = $this->createMock(MediaTwigAdapter::class);
		// The transform is per-property: a share image is cropped to Open Graph's
		// 1.91:1, a logo is only bounded in width. Return a path that names the
		// transform it was handed so the assertions can tell them apart.
		$media->method('imagePath')->willReturnCallback(
			static function (string|array|null $record, array $transform, array $options) use ($resolveImages): string {
				if (!$resolveImages) {
					return '';
				}
				$suffix = match ($transform) {
					SeoSettings::OG_IMAGE   => '?w=1200&h=630&fit=crop-focalpoint',
					SeoSettings::LOGO_IMAGE => '?w=600&fit=max',
					default                 => '?unexpected',
				};

				return '/imageworks/seo-site/seo-site/' . (string)($options['property'] ?? '') . '.jpg' . $suffix;
			},
		);

		$settings = $this->createMock(SettingsFetcher::class);
		$settings->method('loadSection')->with('general')->willReturn($general);

		// Config has a heavy constructor — build the shell and inject.
		/** @var Config $config */
		$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->domain = 'example.com';

		return new SeoSettingsLoader($collections, $objects, $media, $settings, $config);
	}

	public function testDefaultsWhenTheCollectionDoesNotExist(): void
	{
		$settings = $this->loader(false, null, ['siteName' => 'General Name'])->load();

		$this->assertSame('General Name', $settings->siteName);
		$this->assertSame('https://example.com', $settings->baseUrl);
		$this->assertSame('', $settings->defaultImage);
	}

	public function testDefaultsWhenTheRecordHasNeverBeenSaved(): void
	{
		// The collection exists but the operator has not saved the record yet —
		// fetchObject throws rather than returning null.
		$settings = $this->loader(true, null, ['siteName' => 'General Name'])->load();

		$this->assertSame('General Name', $settings->siteName);
		$this->assertSame('https://example.com', $settings->baseUrl);
	}

	public function testFallsBackToTheDomainWhenNothingNamesTheSite(): void
	{
		$this->assertSame('example.com', $this->loader(false, null)->load()->siteName);
	}

	public function testReadsTheSingletonRecordAndResolvesImagesToAbsoluteUrls(): void
	{
		$settings = $this->loader(
			true,
			[
				'siteName'         => 'Bistro',
				'baseUrl'          => 'https://www.bistro.test/',
				'defaultImage'     => ['name' => 'share.jpg', 'size' => 10],
				'organizationLogo' => ['name' => '', 'size' => 0],
			],
			[],
			true,
		)->load();

		$this->assertSame('Bistro', $settings->siteName);
		$this->assertSame('https://www.bistro.test', $settings->baseUrl);
		$this->assertSame('https://www.bistro.test/imageworks/seo-site/seo-site/defaultImage.jpg?w=1200&h=630&fit=crop-focalpoint', $settings->defaultImage);
		$this->assertSame('', $settings->organizationLogo);
	}

	public function testTheDefaultImageAltComesOffTheRawImageObject(): void
	{
		// The alt is stored on the image object the path resolution replaces
		// with a URL string, so the loader has to capture it first.
		$settings = $this->loader(
			true,
			['defaultImage' => ['name' => 'a.jpg', 'size' => 1, 'alt' => 'Site alt']],
			[],
			true,
		)->load();

		$this->assertSame('Site alt', $settings->defaultImageAlt);
	}

	public function testTheDefaultImageAltIsEmptyWhenTheImageCarriesNone(): void
	{
		$settings = $this->loader(true, ['defaultImage' => ['name' => 'a.jpg', 'size' => 1]], [], true)->load();

		$this->assertSame('', $settings->defaultImageAlt);
		$this->assertSame('', $this->loader(false, null)->load()->defaultImageAlt);
	}

	public function testTheLogoGetsItsOwnUncroppedTransform(): void
	{
		// A logo cropped to 1.91:1 is a mangled logo — Google's Organization
		// guidance wants the mark as-is, so it only gets a width bound.
		$settings = $this->loader(
			true,
			[
				'baseUrl'          => 'https://www.bistro.test',
				'defaultImage'     => ['name' => 'share.jpg', 'size' => 10],
				'organizationLogo' => ['name' => 'logo.png', 'size' => 10],
			],
			[],
			true,
		)->load();

		$this->assertSame('https://www.bistro.test/imageworks/seo-site/seo-site/defaultImage.jpg?w=1200&h=630&fit=crop-focalpoint', $settings->defaultImage);
		$this->assertSame('https://www.bistro.test/imageworks/seo-site/seo-site/organizationLogo.jpg?w=600&fit=max', $settings->organizationLogo);
		$this->assertNotSame($settings->defaultImage, $settings->organizationLogo);
	}

	public function testTheLogoTransformKeepsTheAspectRatio(): void
	{
		// The constant is the contract the docs describe: a width bound and a
		// fit that preserves the aspect ratio, with no height to crop toward.
		$this->assertSame(['w' => 600, 'fit' => 'max'], SeoSettings::LOGO_IMAGE);
		$this->assertArrayNotHasKey('h', SeoSettings::LOGO_IMAGE);
	}

	public function testTheRecordSiteNameWinsOverTheGeneralSettings(): void
	{
		$settings = $this->loader(true, ['siteName' => 'Bistro'], ['siteName' => 'General Name'])->load();

		$this->assertSame('Bistro', $settings->siteName);
	}

	public function testMemoisesWithinARequest(): void
	{
		$loader = $this->loader(true, ['siteName' => 'Once']);

		$this->assertSame($loader->load(), $loader->load());
	}
}
