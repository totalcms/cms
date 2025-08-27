<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class UrlData extends PropertyData
{
	public string $url;

	public function __construct(string $url, public array $settings = [])
	{
		$this->url = $this->cleanUrl($url);
	}

	private function cleanUrl(string $url): string
	{
		if ($url === '') {
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
