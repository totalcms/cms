<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Cache\CacheManager;

final readonly class SettingsSaver
{
	public function __construct(
		private CacheManager $cacheManager,
	) {
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,string> $settings
	 *
	 * @return array<string,mixed>
	 */
	public function save(array $settings): array
	{
		unset($settings['csrf_token'], $settings['csrf_token_name']);

		$settings           = array_filter($settings, fn ($value) => $value !== '');
		$settings['sentry'] = isset($settings['sentry']);

		$returnSettings = $settings;

		if (isset($settings['presets']) && is_string($settings['presets'])) {
			$presets = json_decode($settings['presets'], true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$settings['presets'] = $presets;
			}
		}

		// Handle dashboard settings
		$dashboardSettings = [];
		if (isset($settings['pagination'])) {
			$dashboardSettings['pagination'] = (int)$settings['pagination'];
			unset($settings['pagination']);
		}
		if (isset($settings['title'])) {
			$dashboardSettings['title'] = $settings['title'];
			unset($settings['title']);
		}
		if (!empty($dashboardSettings)) {
			$settings['dashboard'] = $dashboardSettings;
		}

		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		if (file_exists($configFile)) {
			$existingSettings = include $configFile;
			if (is_array($existingSettings)) {
				$settings = array_replace_recursive($existingSettings, $settings);
			}
		}

		$configContent = "<?php\n\nreturn json_decode(<<<JSON\n";
		$configContent .= json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$configContent .= "\nJSON, true);\n";

		file_put_contents($configFile, $configContent);

		// Clear all caches after settings are saved
		$this->cacheManager->clearAllCaches();

		return $returnSettings;
	}
}

/*

<?php

return [
	"watermarksGallery" => "watermarks",
	"timezone"          => "America/Denver",
	"sentry"            => true,
	"presets" => [
		'small' => [
			'w'   => 300,
			'h'   => 200,
		],
		'small-crop' => [
			'w'   => 300,
			'h'   => 300,
			'fit' => 'crop-focalpoint',
		],
		'medium' => [
			'w'   => 600,
			'h'   => 400,
		],
		'medium-crop' => [
			'w'   => 600,
			'h'   => 600,
			'fit' => 'crop-focalpoint',
		],
	]
];

 */
