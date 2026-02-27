<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

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
		private EmailSender $emailSender,
		private TwigRenderer $twigRenderer,
		private EditionFeatureService $editionFeatureService,
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

		// License settings can only be saved during trial/development
		if ($section === 'license' && !$this->editionFeatureService->canSimulateEdition()) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Edition simulation is only available for Pro edition and above',
			], 403);
		}

		// Check if this is an SMTP test request
		$queryParams = $request->getQueryParams();
		if ($section === 'smtp' && isset($queryParams['test'])) {
			return $this->handleSmtpTest($request, $response);
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

	/**
	 * Handle SMTP test email request.
	 */
	private function handleSmtpTest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$formData = (array)$request->getParsedBody();

		// Get test email address from form
		$testEmail = trim((string)($formData['test_email'] ?? ''));

		if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
			return $this->renderSettingsPage($response, [
				'success' => false,
				'message' => 'Please enter a valid email address to send the test email.',
			]);
		}

		// Send test email with 10 second timeout
		$result = $this->emailSender->send([
			'to'       => $testEmail,
			'subject'  => 'Total CMS SMTP Test',
			'bodyHtml' => '<p>This is a test email from Total CMS to verify your SMTP configuration.</p><p>If you received this email, your SMTP settings are working correctly!</p>',
		], 10);

		if ($result['success']) {
			return $this->renderSettingsPage($response, [
				'success' => true,
				'message' => 'Test email sent successfully to ' . $testEmail . '. Please check your inbox.',
			]);
		}

		// Use detailed error message if available, otherwise use generic message
		$errorMessage = $result['error'] ?? $result['message'];

		return $this->renderSettingsPage($response, [
			'success' => false,
			'message' => 'Failed to send test email: ' . $errorMessage,
		]);
	}

	/**
	 * Render settings page with test result.
	 *
	 * @param array{success:bool,message:string} $testResult
	 */
	private function renderSettingsPage(ResponseInterface $response, array $testResult): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'admin/settings.twig', [
			'url' => [
				'path'    => '/admin/settings/smtp',
				'query'   => '',
				'params'  => ['section' => 'smtp'],
				'page'    => 'settings',
				'section' => 'smtp',
			],
			'currentSection' => 'smtp',
			'testResult'     => $testResult,
		]);
	}
}
