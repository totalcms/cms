<?php

namespace TotalCMS\Domain\Twig\Service;

use Twig\Environment as TwigEnvironment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/**
 * Twig syntax linting service.
 *
 * Provides syntax checking for Twig templates with detailed error context.
 */
readonly class TwigLintService
{
	private TwigEnvironment $twig;

	public function __construct()
	{
		// Create a minimal Twig environment for linting
		$loader     = new ArrayLoader([]);
		$this->twig = new TwigEnvironment($loader, [
			'cache'            => false,
			'debug'            => false,
			'autoescape'       => false,
			'strict_variables' => false,
		]);
	}

	/**
	 * Lint a file and return results.
	 *
	 * @param string $filePath The absolute path to the file to lint
	 * @param int    $contextLines Number of lines to show before/after error
	 *
	 * @return array{success: bool, error?: array{message: string, line: int, context: string}, file: string}
	 */
	public function lintFile(string $filePath, int $contextLines = 5): array
	{
		if (!file_exists($filePath)) {
			return [
				'success' => false,
				'error'   => [
					'message' => "File not found: {$filePath}",
					'line'    => 0,
					'context' => '',
				],
				'file'    => $filePath,
			];
		}

		$content = file_get_contents($filePath);

		if ($content === false) {
			return [
				'success' => false,
				'error'   => [
					'message' => "Unable to read file: {$filePath}",
					'line'    => 0,
					'context' => '',
				],
				'file'    => $filePath,
			];
		}

		return $this->lintContent($content, $filePath, $contextLines);
	}

	/**
	 * Lint Twig content and return results.
	 *
	 * @param string $content       The Twig content to lint
	 * @param string $filename      The filename for error reporting
	 * @param int    $contextLines  Number of lines to show before/after error
	 *
	 * @return array{success: bool, error?: array{message: string, line: int, context: string}, file: string}
	 */
	public function lintContent(string $content, string $filename = 'input', int $contextLines = 5): array
	{
		try {
			$source = new Source($content, $filename);

			// Tokenize the source
			$tokenStream = $this->twig->tokenize($source);

			// Parse the tokens into an AST
			$this->twig->parse($tokenStream);

			return [
				'success' => true,
				'file'    => $filename,
			];
		} catch (SyntaxError $e) {
			$errorLine  = $e->getTemplateLine();
			$context    = $this->getErrorContext($content, $errorLine, $contextLines);
			$totalLines = count(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));

			return [
				'success' => false,
				'error'   => [
					'message' => $this->cleanErrorMessage($e->getMessage()),
					'line'    => $errorLine,
					'context' => $context,
				],
				'file'       => $filename,
				'totalLines' => $totalLines,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'error'   => [
					'message' => $e->getMessage(),
					'line'    => 0,
					'context' => '',
				],
				'file'    => $filename,
			];
		}
	}

	/**
	 * Get context lines around an error.
	 */
	private function getErrorContext(string $content, int $errorLine, int $contextLines): string
	{
		// Normalize line endings and split
		$content    = str_replace(["\r\n", "\r"], "\n", $content);
		$lines      = explode("\n", $content);
		$totalLines = count($lines);

		// Calculate start and end lines
		$startLine = max(1, $errorLine - $contextLines);
		$endLine   = min($totalLines, $errorLine + $contextLines);

		$context     = [];
		$maxLineNum  = strlen((string)$endLine);

		for ($i = $startLine; $i <= $endLine; $i++) {
			$lineNum     = str_pad((string)$i, $maxLineNum, ' ', STR_PAD_LEFT);
			$lineContent = $lines[$i - 1] ?? '';

			if ($i === $errorLine) {
				$context[] = sprintf('>>> %s | %s', $lineNum, $lineContent);
			} else {
				$context[] = sprintf('    %s | %s', $lineNum, $lineContent);
			}
		}

		return implode("\n", $context);
	}

	/**
	 * Clean up error message for display.
	 */
	private function cleanErrorMessage(string $message): string
	{
		// Remove the "in ... at line X" suffix since we display that separately
		$message = (string)preg_replace('/ in "[^"]*" at line \d+\.?$/', '', $message);
		$message = (string)preg_replace('/ at line \d+\.?$/', '', $message);

		return trim($message);
	}
}
