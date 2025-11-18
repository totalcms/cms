<?php

namespace TotalCMS\Domain\Settings\Services;

/**
 * Validates and transforms settings data for each section.
 */
readonly class SettingsValidator
{
	/**
	 * Valid settings sections.
	 *
	 * @var array<string>
	 */
	private array $validSections;

	public function __construct()
	{
		$this->validSections = [
			'installation',
			'general',
			'dashboard',
			'imageworks',
			'smtp',
			'cache',
			'auth',
			'htmlclean',
			'mailer',
		];
	}

	/**
	 * Check if a section is valid.
	 */
	public function isValidSection(string $section): bool
	{
		return in_array($section, $this->validSections, true);
	}

	/**
	 * Get all valid sections.
	 *
	 * @return array<string>
	 */
	public function getValidSections(): array
	{
		return $this->validSections;
	}

	/**
	 * Process and validate settings data for a specific section.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function processSection(string $section, array $data): array
	{
		// Clean empty values
		$data = $this->cleanFormData($data);

		// Process section-specific transformations
		return match ($section) {
			'installation' => $this->processInstallation($data),
			'general'      => $this->processGeneral($data),
			'dashboard'    => $this->processDashboard($data),
			'imageworks'   => $this->processImageWorks($data),
			'cache'        => $this->processCache($data),
			'auth'         => $this->processAuth($data),
			'htmlclean'    => $this->processHtmlClean($data),
			default        => $data,
		};
	}

	/**
	 * Clean empty values from form data.
	 *
	 * Note: Empty strings are preserved to allow users to intentionally clear field values.
	 * Only null values are filtered out as they typically indicate unset form fields.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function cleanFormData(array $data): array
	{
		return array_filter($data, fn (mixed $value): bool => $value !== null);
	}

	/**
	 * Process installation settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processInstallation(array $data): array
	{
		return $data;
	}

	/**
	 * Process general settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processGeneral(array $data): array
	{
		return $data;
	}

	/**
	 * Process dashboard settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processDashboard(array $data): array
	{
		// Convert pagination to integer
		if (isset($data['pagination'])) {
			$data['pagination'] = (int)$data['pagination'];
		}

		// Normalize accent color to simple hex string
		if (isset($data['accent'])) {
			if (is_array($data['accent']) && isset($data['accent']['hex'])) {
				// If it's a ColorData object, extract just the hex value
				$data['accent'] = $data['accent']['hex'];
			} elseif (is_string($data['accent'])) {
				// Already a string, ensure it has # prefix
				$data['accent'] = str_starts_with($data['accent'], '#') ? $data['accent'] : '#' . $data['accent'];
			}
		}

		return $data;
	}

	/**
	 * Process ImageWorks settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processImageWorks(array $data): array
	{
		// Parse JSON fields
		if (isset($data['presets']) && is_string($data['presets'])) {
			$presets = json_decode($data['presets'], true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($presets)) {
				$data['presets'] = $presets;
			}
		}

		if (isset($data['defaults']) && is_string($data['defaults'])) {
			$defaults = json_decode($data['defaults'], true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($defaults)) {
				$data['defaults'] = $defaults;
			}
		}

		return $data;
	}

	/**
	 * Process cache settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processCache(array $data): array
	{
		// Convert cache backend toggles to booleans
		foreach (['apcu', 'redis', 'memcached', 'filesystem'] as $backend) {
			if (isset($data[$backend])) {
				$data[$backend] = $data[$backend] === 'on' || $data[$backend] === '1' || $data[$backend] === true;
			}
		}

		// Parse JSON configurations for redis and memcached
		if (isset($data['redisConfig']) && is_string($data['redisConfig'])) {
			$config = json_decode($data['redisConfig'], true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($config)) {
				$data['redisConfig'] = $config;
			}
		}

		if (isset($data['memcachedConfig']) && is_string($data['memcachedConfig'])) {
			$config = json_decode($data['memcachedConfig'], true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($config)) {
				$data['memcachedConfig'] = $config;
			}
		}

		return $data;
	}

	/**
	 * Process auth settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processAuth(array $data): array
	{
		// Handle toggle field
		if (isset($data['enable'])) {
			$data['enable'] = $data['enable'] === 'on' || $data['enable'] === '1' || $data['enable'] === true;
		}

		// Convert numeric fields to integers
		$numericFields = [
			'maxAttempts',
			'downloadMaxAttempts',
			'deniedTimeout',
			'persistentLoginDays',
			'resetTokenExpiry',
		];

		foreach ($numericFields as $field) {
			if (isset($data[$field])) {
				$data[$field] = (int)$data[$field];
			}
		}

		return $data;
	}

	/**
	 * Process HTML sanitization settings.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function processHtmlClean(array $data): array
	{
		// Handle toggle field
		if (isset($data['enabled'])) {
			$data['enabled'] = $data['enabled'] === 'on' || $data['enabled'] === '1' || $data['enabled'] === true;
		}

		// Parse JSON arrays
		foreach (['allowed_css_properties', 'allowed_tags', 'allowed_iframe_domains'] as $field) {
			if (isset($data[$field]) && is_string($data[$field])) {
				$parsed = json_decode($data[$field], true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
					$data[$field] = $parsed;
				}
			}
		}

		return $data;
	}
}
