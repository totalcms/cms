<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Domain\Extension\Service\Install\ExtensionInstaller;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\Install\UploadLimit;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * POST /api/extensions/install — multipart field `extension` holding a zip.
 *
 * Admin-only (route middleware). Answers JSON for the drop zone on the
 * Extensions page: `redirect` is the review page for a fresh install
 * (`?installed=1`; the review page sends it on to the Extensions page,
 * highlighting it, when there is nothing to review) and the Extensions
 * page for an update.
 * InstallException → 400 with its code; anything else bubbles to the
 * error handler as a 500.
 */
readonly class ExtensionInstallAction
{
	public function __construct(
		private ExtensionInstaller $installer,
		private JsonRenderer $renderer,
		private Config $config,
		private RouteParserInterface $routeParser,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$file = $request->getUploadedFiles()['extension'] ?? null;
		if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
			return $this->renderer->json($response, ['error' => 'No file was uploaded. Choose an extension zip and try again.', 'code' => 'no_file'], 400);
		}
		if ($file->getError() !== UPLOAD_ERR_OK) {
			return $this->renderer->json($response, $this->uploadError($file->getError()), 400);
		}

		$dir = rtrim($this->config->tmpdir, '/') . '/extension-install';
		FileUtils::ensureDirectory($dir, 0700);
		$zipPath = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';

		try {
			$file->moveTo($zipPath);
			$result = $this->installer->install($zipPath);
		} catch (InstallException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage(), 'code' => $e->codeName], 400);
		} finally {
			if (is_file($zipPath)) {
				unlink($zipPath);
			}
		}

		$redirect = $result->isUpdate
			? $this->routeParser->urlFor('admin-extensions')
			: $this->routeParser->urlFor('admin-extension-review', ['extension' => $result->id], ['installed' => '1']);

		return $this->renderer->json($response, $result->toArray() + ['redirect' => $redirect]);
	}

	/**
	 * PHP rejected the upload before this action ran. Name the size limit
	 * when that was the reason — it is the one the operator can act on.
	 *
	 * @return array{error: string, code: string}
	 */
	private function uploadError(int $code): array
	{
		if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
			$limit = UploadLimit::describe();

			return [
				'error' => $limit !== ''
					? "The zip exceeds this server's upload limit of {$limit}."
					: "The zip exceeds this server's upload limit.",
				'code' => 'too_large',
			];
		}

		return ['error' => "The upload failed (PHP upload error {$code}). Try again, or install with `tcms extension:install`.", 'code' => 'upload_failed'];
	}
}
