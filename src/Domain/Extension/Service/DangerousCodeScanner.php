<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

/**
 * One-time heuristic scan of an extension's PHP source for high-risk calls.
 *
 * NOT a sandbox and NOT a guarantee — it surfaces patterns worth a human's
 * attention before enabling. Runs once at enable; zero runtime cost.
 *
 * Uses PHP's tokenizer so only REAL code is inspected: comments and the
 * contents of string literals are tokenized as their own tokens and skipped,
 * which avoids false positives (e.g. a backtick or `shell_exec(` inside a
 * comment or a quoted string).
 */
final class DangerousCodeScanner
{
	/**
	 * Function-call patterns: lowercased function name => finding label.
	 * Each is flagged when it appears as a real call (T_STRING + `(`) that is
	 * not a method/static call or a function declaration.
	 *
	 * @var array<string,string>
	 */
	private const FUNCTION_CALLS = [
		'exec'              => 'exec',
		'shell_exec'        => 'shell_exec',
		'system'            => 'system',
		'passthru'          => 'passthru',
		'proc_open'         => 'proc_open',
		'popen'             => 'popen',
		'curl_exec'         => 'curl_exec',
		'fsockopen'         => 'fsockopen',
		'file_put_contents' => 'file_put_contents',
		'base64_decode'     => 'base64_decode',
	];

	/**
	 * Directories skipped during the walk — third-party dependencies and VCS
	 * metadata. We review the extension author's own code, not their libraries.
	 *
	 * @var list<string>
	 */
	private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

	/**
	 * @return list<array{pattern:string,file:string,line:int,snippet:string}>
	 */
	public function scan(string $extensionDir): array
	{
		$findings = [];

		if (!is_dir($extensionDir)) {
			return $findings;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($extensionDir, \FilesystemIterator::SKIP_DOTS),
		);

		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
				continue;
			}

			$relative = str_replace($extensionDir . DIRECTORY_SEPARATOR, '', $file->getPathname());

			// Skip dependency / VCS directories anywhere in the path.
			if (array_intersect(explode(DIRECTORY_SEPARATOR, $relative), self::SKIP_DIRS) !== []) {
				continue;
			}

			$contents = file_get_contents($file->getPathname());
			if ($contents === false) {
				continue;
			}

			foreach ($this->scanContents($contents) as $hit) {
				$findings[] = [
					'pattern' => $hit['pattern'],
					'file'    => $relative,
					'line'    => $hit['line'],
					'snippet' => $hit['snippet'],
				];
			}
		}

		return $findings;
	}

	/**
	 * Tokenize a single file's contents and return its findings.
	 *
	 * @return list<array{pattern:string,line:int,snippet:string}>
	 */
	private function scanContents(string $contents): array
	{
		$hits  = [];
		$lines = explode("\n", $contents);

		/** @var list<array{0:int,1:string,2:int}|string> $tokens */
		$tokens = token_get_all($contents);
		$count  = count($tokens);

		// Operators that mean the T_STRING is NOT a plain function call.
		$prevDisqualifiers = [
			T_OBJECT_OPERATOR,
			T_DOUBLE_COLON,
			T_FUNCTION,
		];
		if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
			$prevDisqualifiers[] = T_NULLSAFE_OBJECT_OPERATOR;
		}

		$prevSignificant = null; // int|string|null : id (int) or literal char (string)

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			// Backtick shell-exec operator: a bare "`" character token.
			if ($token === '`') {
				$line = $this->lineOfCharToken($tokens, $i);
				// Skip until the closing backtick so we record only ONE finding
				// per backtick expression (avoid double-counting the close).
				for ($j = $i + 1; $j < $count; $j++) {
					if ($tokens[$j] === '`') {
						$i = $j;
						break;
					}
				}
				$hits[] = [
					'pattern' => 'backtick',
					'line'    => $line,
					'snippet' => $this->snippet($lines, $line),
				];

				continue;
			}

			// Non-array tokens (single-char strings) carry no payload we need
			// beyond the backtick handled above. Track significance.
			if (!is_array($token)) {
				$prevSignificant = $token;

				continue;
			}

			$id   = $token[0];
			$text = $token[1];
			$line = $token[2];

			// Whitespace and comments are never significant and never code.
			if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
				continue;
			}

			// eval is a language construct, tokenized as T_EVAL.
			if ($id === T_EVAL) {
				$hits[] = [
					'pattern' => 'eval',
					'line'    => $line,
					'snippet' => $this->snippet($lines, $line),
				];
				$prevSignificant = $id;

				continue;
			}

			if ($id === T_STRING) {
				$lower = strtolower($text);
				$call  = isset(self::FUNCTION_CALLS[$lower]) || $lower === 'file_get_contents';

				if ($call) {
					$nextSig = $this->nextSignificantIndex($tokens, $i + 1);
					$isCall  = $nextSig !== null && $tokens[$nextSig] === '(';
					$qualified = is_array($prevSignificant)
						? in_array($prevSignificant[0], $prevDisqualifiers, true)
						: false;

					if ($isCall && !$qualified) {
						if ($lower === 'file_get_contents') {
							if ($this->firstArgIsHttpString($tokens, $nextSig)) {
								$hits[] = [
									'pattern' => 'file_get_contents(http',
									'line'    => $line,
									'snippet' => $this->snippet($lines, $line),
								];
							}
						} else {
							$hits[] = [
								'pattern' => self::FUNCTION_CALLS[$lower],
								'line'    => $line,
								'snippet' => $this->snippet($lines, $line),
							];
						}
					}
				}
			}

			$prevSignificant = $token;
		}

		return $hits;
	}

	/**
	 * Index of the next significant token (skips whitespace + comments),
	 * or null if none remain.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens
	 */
	private function nextSignificantIndex(array $tokens, int $start): ?int
	{
		$count = count($tokens);
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * Given the index of the `(` token after file_get_contents, decide whether
	 * the first argument is an http(s) string literal.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens
	 */
	private function firstArgIsHttpString(array $tokens, int $parenIndex): bool
	{
		$argIndex = $this->nextSignificantIndex($tokens, $parenIndex + 1);
		if ($argIndex === null) {
			return false;
		}
		$arg = $tokens[$argIndex];
		if (!is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING) {
			return false;
		}
		// Strip surrounding quotes.
		$value = substr($arg[1], 1, -1);
		$lower = strtolower($value);

		return str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://');
	}

	/**
	 * Determine the line of a bare single-char token (which carries no line
	 * info) by scanning backward to the nearest array token with a line number.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $tokens
	 */
	private function lineOfCharToken(array $tokens, int $index): int
	{
		for ($i = $index - 1; $i >= 0; $i--) {
			$token = $tokens[$i];
			if (is_array($token)) {
				// Add any newlines contained in the token's text after it.
				return $token[2] + substr_count($token[1], "\n");
			}
		}

		return 1;
	}

	/**
	 * Trimmed source line for a 1-based line number, '' if out of range.
	 *
	 * @param list<string> $lines
	 */
	private function snippet(array $lines, int $line): string
	{
		$index = $line - 1;

		return isset($lines[$index]) ? trim($lines[$index]) : '';
	}
}
