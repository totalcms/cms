<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Support\Config;

/**
 * Twig Debugger: lint a file under the document root.
 */
final readonly class TwigDebuggerPageData implements UtilsPageData
{
	public function __construct(
		private TwigLintService $twigLintService,
		private Config $config,
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
	 * Lint a Twig file for syntax errors. The file must live under the
	 * document root, resolved through Config (which always has one — it falls
	 * back to <root>/public) rather than the raw $_SERVER value: an empty root
	 * would make every path "inside" it, since every string starts with "".
	 *
	 * @return array<string,mixed>
	 */
	private function lintTwigFile(string $relativePath): array
	{
		$relativePath = ltrim($relativePath, '/');

		// realpath('') is the working directory, so the empty case is checked first.
		$documentRoot = $this->config->docroot === '' ? false : realpath($this->config->docroot);

		if ($documentRoot === false) {
			return $this->lintError($relativePath, 'Access denied: no document root');
		}

		$realPath = realpath($documentRoot . '/' . $relativePath);

		if ($realPath === false) {
			return $this->lintError($relativePath, "File not found: {$relativePath}");
		}

		// The separator matters: /var/www-old must not pass for a root of /var/www.
		if (!str_starts_with($realPath, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
			return $this->lintError($relativePath, 'Access denied: path outside document root');
		}

		return $this->twigLintService->lintFile($realPath)->toArray();
	}

	/** @return array<string,mixed> */
	private function lintError(string $relativePath, string $message): array
	{
		return [
			'success' => false,
			'error'   => [
				'message' => $message,
				'line'    => 0,
				'context' => '',
			],
			'file'    => $relativePath,
		];
	}
}
