<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsValidator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action for saving settings for a specific section.
 */
readonly class AdminSettingsSaveSectionAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SettingsSaver $settingsSaver,
		private InstallationSettingsSaver $installationSettingsSaver,
		private SettingsValidator $settingsValidator,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$section = $args['section'] ?? '';

		// Validate section exists
		if (!$this->settingsValidator->isValidSection($section)) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Invalid settings section',
			], 400);
		}

		$formData = (array)$request->getParsedBody();

		// Remove CSRF tokens
		unset($formData['csrf_token'], $formData['csrf_token_name']);

		// Installation settings use a different saver (writes to tcms.php instead of settings.json)
		if ($section === 'installation') {
			$processedData = $this->settingsValidator->processSection($section, $formData);
			$this->installationSettingsSaver->saveSettings($processedData);
		} else {
			// Save the section (validation and processing happens in SettingsSaver)
			$this->settingsSaver->saveSection($section, $formData);
		}

		return $this->renderer->json($response, [
			'success' => true,
			'message' => 'Settings saved successfully',
			'section' => $section,
		]);
	}
}
