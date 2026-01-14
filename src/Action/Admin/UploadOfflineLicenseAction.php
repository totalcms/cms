<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Service\OfflineLicenseValidator;
use TotalCMS\Support\Config;

/**
 * Handles offline license file uploads.
 */
readonly class UploadOfflineLicenseAction
{
	private const LICENSE_FILE = 'tcms-data/offline-license.key';

	public function __construct(
		private OfflineLicenseValidator $offlineValidator,
		private Config $config,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$uploadedFiles = $request->getUploadedFiles();

		if (!isset($uploadedFiles['license_file'])) {
			return $this->redirectWithError($response, 'No file uploaded');
		}

		$file = $uploadedFiles['license_file'];

		if ($file->getError() !== UPLOAD_ERR_OK) {
			return $this->redirectWithError($response, 'File upload failed');
		}

		// Read the file content
		$content = (string) $file->getStream();

		if (empty(trim($content))) {
			return $this->redirectWithError($response, 'Uploaded file is empty');
		}

		// Validate the token before saving
		try {
			$this->offlineValidator->validateToken(trim($content));
		} catch (\Exception $e) {
			return $this->redirectWithError($response, 'Invalid license file: ' . $e->getMessage());
		}

		// Save the file
		$targetPath = $this->getLicenseFilePath();
		$targetDir = dirname($targetPath);

		if (!is_dir($targetDir)) {
			if (!mkdir($targetDir, 0755, true)) {
				return $this->redirectWithError($response, 'Failed to create license directory');
			}
		}

		if (file_put_contents($targetPath, trim($content)) === false) {
			return $this->redirectWithError($response, 'Failed to save license file');
		}

		// Redirect back to license manager with success
		return $response
			->withHeader('Location', '/admin/utils/license-manager')
			->withStatus(302);
	}

	private function getLicenseFilePath(): string
	{
		$basePath = $this->config->basePath ?? '';

		return rtrim($basePath, '/') . '/' . self::LICENSE_FILE;
	}

	private function redirectWithError(ResponseInterface $response, string $error): ResponseInterface
	{
		// For now, just redirect back - we could add flash message support later
		return $response
			->withHeader('Location', '/admin/utils/license-manager?error=' . urlencode($error))
			->withStatus(302);
	}
}
