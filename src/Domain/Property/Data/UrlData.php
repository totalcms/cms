<?php

namespace App\Domain\Property\Data;

use InvalidArgumentException;

/**
 * String type property data.
 */
class UrlData extends PropertyData
{
    public string $url;

    public function __construct(string $id, string $url)
    {
        $this->id    = $id;
        $this->url   = self::cleanUrl($url);
    }

    private static function cleanUrl(string $url): string
    {
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if ($url === false || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL');
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
