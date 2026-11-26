<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Contract\DownloadSource;

/**
 * A download kept somewhere else: the product's Download Link. The store
 * counts the download and sends the buyer on to the link.
 *
 * The store's limits end at its own link. The address the buyer is sent to
 * is as open as wherever it is hosted makes it, and a buyer who has been
 * there once has it.
 */
final readonly class UrlDownloadSource implements DownloadSource
{
	public const PROPERTY = 'downloadUrl';

	public function id(): string
	{
		return 'url';
	}

	public function find(string $collection, array $product): ?Deliverable
	{
		$url = self::url($product);

		return $url === '' ? null : new Deliverable(self::name($url), 0, self::PROPERTY);
	}

	public function deliver(string $collection, array $product): ?Delivery
	{
		$url = self::url($product);

		return $url === '' ? null : Delivery::redirect(self::name($url), $url);
	}

	/**
	 * The product's link, when it is a web address: http or https, with a
	 * host. Anything else is no download at all.
	 *
	 * @param array<string,mixed> $product
	 */
	private static function url(array $product): string
	{
		$url = is_string($product[self::PROPERTY] ?? null) ? trim($product[self::PROPERTY]) : '';
		if ($url === '' || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
			return '';
		}

		$parts = parse_url($url);
		if (!is_array($parts)) {
			return '';
		}

		return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '' ? $url : '';
	}

	/** What to call it: the file the link ends in, or the host when it ends in none. */
	private static function name(string $url): string
	{
		$name = rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));

		return $name !== '' ? $name : (string)parse_url($url, PHP_URL_HOST);
	}
}
