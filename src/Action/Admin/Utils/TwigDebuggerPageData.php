<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\Service\TwigLintService;

/**
 * Twig Debugger: lint a file under the document root.
 */
final readonly class TwigDebuggerPageData implements UtilsPageData
{
	public function __construct(
		private TwigLintService $twigLintService,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		return [
			'lintResults' => $this->lintResults($request),
		];
	}

	/** @return array<string,mixed>|null */
	private function lintResults(ServerRequestInterface $request): ?array
	{
		// Check POST first, then query params
		if ($request->getMethod() === 'POST') {
			$post     = (array)$request->getParsedBody();
			$filepath = isset($post['filepath']) && $post['filepath'] !== '' ? (string)$post['filepath'] : null;
		} else {
			$query    = $request->getQueryParams();
			$filepath = isset($query['filepath']) && $query['filepath'] !== '' ? (string)$query['filepath'] : null;
		}

		return $filepath !== null ? $this->lintTwigFile($filepath) : null;
	}

	/**
	 * Lint a Twig file for syntax errors.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed>
	 */
	private function lintTwigFile(string $relativePath): array
	{
		// Construct full path from document root
		$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

		// Clean the path - remove leading slashes for consistency
		$relativePath = ltrim($relativePath, '/');

		// Build absolute path
		$absolutePath = $documentRoot . '/' . $relativePath;

		// Security check: ensure the path is within document root
		$realPath = realpath($absolutePath);

		if ($realPath === false) {
			return [
				'success' => false,
				'error'   => [
					'message' => "File not found: {$relativePath}",
					'line'    => 0,
					'context' => '',
				],
				'file'    => $relativePath,
			];
		}

		if (!str_starts_with($realPath, (string)$documentRoot)) {
			return [
				'success' => false,
				'error'   => [
					'message' => 'Access denied: path outside document root',
					'line'    => 0,
					'context' => '',
				],
				'file'    => $relativePath,
			];
		}

		return $this->twigLintService->lintFile($realPath)->toArray();
	}
}
