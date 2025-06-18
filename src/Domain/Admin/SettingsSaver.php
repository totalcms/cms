<?php

namespace TotalCMS\Domain\Admin;

final class SettingsSaver
{
	public function __construct()
	{
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
		$settings           = array_filter($settings, fn ($value) => $value !== '');
		$settings['sentry'] = isset($settings['sentry']);

		$returnSettings = $settings;

		if (isset($settings['presets']) && is_string($settings['presets'])) {
			$presets = json_decode($settings['presets'], true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$settings['presets'] = $presets;
			}
		}

		$configContent = "<?php\n\nreturn [\n";
		foreach ($settings as $key => $value) {
			if (is_array($value)) {
				$arrayString = var_export($value, true);
				$arrayString = str_replace('array (', '[', $arrayString);
				$arrayString = str_replace(')', ']', $arrayString);

				$configContent .= "\"$key\" => $arrayString,\n";
				continue;
			}
			$configContent .= "\"$key\" => " . (is_bool($value) ? ($value ? 'true' : 'false') : "\"$value\"") . ",\n";
		}
		$configContent .= "];\n";

		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		file_put_contents($configFile, $configContent);

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
