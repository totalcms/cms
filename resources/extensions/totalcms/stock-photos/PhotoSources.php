<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Support\Config;

/**
 * The sources the panel shows as tabs, in order: each gallery chosen in the
 * settings, then Pixabay when it has a key. A gallery is only offered to an
 * editor whose access groups let them read the gallery collection.
 */
final readonly class PhotoSources
{
	/**
	 * @param list<PhotoProvider> $providers
	 */
	public function __construct(
		private array $providers,
		private Config $config,
		private AccessManager $accessManager,
		private AccessControlService $accessControl,
	) {
	}

	/** @return list<PhotoProvider> the sources the current editor may use */
	public function available(): array
	{
		return array_values(array_filter($this->providers, fn (PhotoProvider $provider): bool => $this->allows($provider)));
	}

	/** The source with that id, if the current editor may use it. */
	public function get(string $id): ?PhotoProvider
	{
		foreach ($this->available() as $provider) {
			if ($provider->id() === $id) {
				return $provider;
			}
		}

		return null;
	}

	/** The gallery source for that gallery id, if the current editor may use it. */
	public function gallery(string $galleryId): ?GalleryProvider
	{
		$provider = $this->get(GalleryProvider::COLLECTION . ':' . $galleryId);

		return $provider instanceof GalleryProvider ? $provider : null;
	}

	/** The source whose download proxy may fetch this URL. */
	public function servingImage(string $url): ?PhotoProvider
	{
		foreach ($this->available() as $provider) {
			if ($provider->servesImage($url)) {
				return $provider;
			}
		}

		return null;
	}

	private function allows(PhotoProvider $provider): bool
	{
		if (!$provider instanceof GalleryProvider || !$this->config->authEnabled()) {
			return true;
		}

		$user = $this->accessManager->userData();
		if (!isset($user['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollection((string)$user['id'], GalleryProvider::COLLECTION, 'read');
	}
}
