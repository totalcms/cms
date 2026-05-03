<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

use TotalCMS\Domain\Property\Data\ImageData;

readonly class PageData
{
	public string $id;
	public string $title;
	public string $route;
	public string $template;
	public string $description;
	public ImageData $image;
	public bool $draft;
	public bool $nav;
	public bool $sitemap;
	public string $changeFrequency;
	public float $priority;
	public int $status;
	public string $redirectTo;

	/** @var array<string,mixed> */
	public array $data;

	/**
	 * Page hierarchy and ordering live in the order file (.order.json) — not
	 * here. PageData carries page CONTENT only.
	 *
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->id              = (string)($data['id'] ?? '');
		$this->title           = (string)($data['title'] ?? '');
		$this->route           = (string)($data['route'] ?? '');
		$this->template        = (string)($data['template'] ?? '');
		$this->description     = (string)($data['description'] ?? '');
		$this->image           = new ImageData(is_array($data['image'] ?? null) ? $data['image'] : []);
		$this->draft           = (bool)($data['draft'] ?? false);
		$this->nav             = (bool)($data['nav'] ?? true);
		$this->sitemap         = (bool)($data['sitemap'] ?? true);
		$this->changeFrequency = (string)($data['changeFrequency'] ?? '');
		$this->priority        = (float)($data['priority'] ?? 0);
		$this->status          = self::parseStatus($data['status'] ?? null);
		$this->redirectTo      = (string)($data['redirectTo'] ?? '');
		$this->data            = self::parseData($data['data'] ?? null);
	}

	/**
	 * Coerce the page-level HTTP status code, defaulting to 200. Out-of-range
	 * (non-3xx-or-larger) values are clamped to 200 so a malformed record
	 * cannot make a page silently disappear behind an unknown status.
	 */
	private static function parseStatus(mixed $raw): int
	{
		$status = (int)($raw ?? 200);

		return ($status >= 100 && $status <= 599) ? $status : 200;
	}

	/**
	 * Decode the page-level `data` field. Accepts a JSON string (from storage)
	 * or an already-decoded array (from tests / programmatic construction).
	 * Invalid or non-object JSON falls back to an empty array.
	 *
	 * @return array<string,mixed>
	 */
	private static function parseData(mixed $raw): array
	{
		if (is_array($raw)) {
			/** @var array<string,mixed> $raw */
			return $raw;
		}

		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	public function isPublished(): bool
	{
		return !$this->draft;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'id'              => $this->id,
			'title'           => $this->title,
			'route'           => $this->route,
			'template'        => $this->template,
			'description'     => $this->description,
			'image'           => $this->image->transform(),
			'draft'           => $this->draft,
			'nav'             => $this->nav,
			'sitemap'         => $this->sitemap,
			'changeFrequency' => $this->changeFrequency,
			'priority'        => $this->priority,
			'status'          => $this->status,
			'redirectTo'      => $this->redirectTo,
			'data'            => $this->data,
		];
	}
}
