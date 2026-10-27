<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

/**
 * The login URL for a signed-out visitor who reached something protected.
 *
 * An `accessCollection` picks the auth collection they sign in to
 * (`/admin/login/{accessCollection}`), so a portal whose users live in their
 * own auth collection is not sent to the default login, which cannot sign
 * them in. Files take it from their collection's setting, Site Builder pages
 * from the page's own. Empty, unreadable or missing: the default login.
 *
 * `?redirect=` brings them back afterwards; AuthLoginSubmitAction only
 * follows it to the same origin.
 */
final readonly class LoginUrl
{
	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/** For a protected file: the login its collection's `accessCollection` names. */
	public function forCollection(string $collectionId, string $returnTo): string
	{
		return $this->forAccessCollection($this->accessCollection($collectionId), $returnTo);
	}

	/** The login for auth collection $accessCollection ('' = the default login). */
	public function forAccessCollection(string $accessCollection, string $returnTo): string
	{
		$url = rtrim($this->config->api, '/') . '/admin/login';

		if ($accessCollection !== '') {
			$url .= '/' . rawurlencode($accessCollection);
		}

		if ($returnTo !== '') {
			$url .= '?' . http_build_query(['redirect' => $returnTo]);
		}

		return $url;
	}

	private function accessCollection(string $collectionId): string
	{
		if ($collectionId === '') {
			return '';
		}

		try {
			$collection = $this->collectionFetcher->fetchCollection($collectionId);
		} catch (\Throwable) {
			return '';
		}

		return $collection instanceof CollectionData ? $collection->accessCollection : '';
	}
}
