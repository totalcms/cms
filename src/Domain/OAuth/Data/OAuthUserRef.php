<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Data;

final readonly class OAuthUserRef implements \Stringable
{
	private function __construct(
		public string $collection,
		public string $userId,
	) {
	}

	/** Split on the FIRST colon; a non-slug prefix means the whole value is a bare legacy id. */
	public static function parse(string $sub, string $defaultCollection): self
	{
		$pos = strpos($sub, ':');
		if ($pos !== false) {
			$prefix = substr($sub, 0, $pos);
			if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $prefix) === 1) {
				return new self($prefix, substr($sub, $pos + 1));
			}
		}

		return new self($defaultCollection, $sub);
	}

	public static function compose(string $collection, string $userId): string
	{
		return $collection . ':' . $userId;
	}

	public function __toString(): string
	{
		return self::compose($this->collection, $this->userId);
	}
}
