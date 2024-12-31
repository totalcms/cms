<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class UrlData extends PropertyData
{
	public string $url;

	/** @param array<string,mixed> $settings */
	public function __construct(string $url, array $settings = [])
	{
		$this->url      = self::cleanUrl($url);
		$this->settings = $settings;
	}

	private static function cleanUrl(string $url): string
	{
		if (empty($url)) {
			return $url;
		}

		$url = filter_var($url, FILTER_SANITIZE_URL);

		if ($url === false || !filter_var($url, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('Invalid URL');
		}

		return $url;
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->url;
	}
}
