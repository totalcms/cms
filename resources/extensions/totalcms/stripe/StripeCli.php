<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

/**
 * Stripe's own command line program, as `tcms stripe:listen` uses it.
 *
 * The secret key is handed to it in its environment and never as an
 * argument: arguments are shown to every user of the machine by `ps`.
 *
 * Not final, so a test can put one in its place that runs nothing.
 */
class StripeCli
{
	private const KEY = 'STRIPE_API_KEY';

	/** @param string|null $path the folders to look for the program in; this process's own PATH when null */
	public function __construct(private readonly ?string $path = null)
	{
	}

	public function installed(): bool
	{
		return $this->binary() !== null;
	}

	/**
	 * The secret the Stripe CLI signs forwarded events with, for the account
	 * the key belongs to. It is the same every time for one account on one
	 * machine. '' when the program is missing, fails, or says nothing.
	 */
	public function signingSecret(string $apiKey): string
	{
		$binary = $this->binary();
		if ($binary === null) {
			return '';
		}

		$nowhere = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
		$process = proc_open(
			[$binary, 'listen', '--print-secret'],
			[0 => ['file', $nowhere, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nowhere, 'w']],
			$pipes,
			null,
			$this->environment($apiKey),
		);
		if (!is_resource($process)) {
			return '';
		}

		$printed = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		return proc_close($process) === 0 ? trim((string)$printed) : '';
	}

	/**
	 * Forward events to $forwardTo until the program is stopped, handing
	 * each line it prints to $print as it happens.
	 *
	 * The lines go through here, and not straight to the terminal, because
	 * the program announces its signing secret when it starts: that secret
	 * is hidden on the way.
	 *
	 * @param \Closure(string): void $print
	 *
	 * @return int how the program ended
	 */
	public function listen(string $apiKey, string $forwardTo, bool $skipVerify, \Closure $print): int
	{
		$binary = $this->binary();
		if ($binary === null) {
			return 127;
		}

		$nowhere = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
		$process = proc_open(
			[$binary, ...$this->listenArguments($forwardTo, $skipVerify)],
			// Both of its outputs down one pipe, read a line at a time: its
			// lines stay in the order it wrote them, a secret is never split
			// across two reads, and it works the same on Windows, where two
			// pipes cannot be watched at once.
			// PHPStan's signature for proc_open does not know `redirect`, which takes a number (PHP 7.4+).
			// @phpstan-ignore argument.type
			[0 => ['file', $nowhere, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
			$pipes,
			null,
			$this->environment($apiKey),
		);
		if (!is_resource($process)) {
			return 1;
		}

		while (($line = fgets($pipes[1])) !== false) {
			$print(self::hideSecrets($line));
		}
		fclose($pipes[1]);

		return proc_close($process);
	}

	/** $text with every Stripe secret in it replaced: a signing secret, or a secret or restricted key. */
	public static function hideSecrets(string $text): string
	{
		return (string)preg_replace('/\b(whsec|sk_test|sk_live|rk_test|rk_live)_[A-Za-z0-9]+/', '$1_… (hidden)', $text);
	}

	/**
	 * What the program is told.
	 *
	 * It must be told which events to forward: every snapshot event is the
	 * kind the store reads, and the ones the store has no use for are
	 * answered and ignored.
	 *
	 * `--latest` asks for each event in Stripe's current API version. An
	 * event is formatted in the version of whoever receives it, and the
	 * program's default is the account's own, which on an old account is
	 * years behind the version the store is written for.
	 *
	 * @return list<string>
	 */
	public function listenArguments(string $forwardTo, bool $skipVerify): array
	{
		return ['listen', '--all-snapshot', '--latest', '--forward-to', $forwardTo, ...($skipVerify ? ['--skip-verify'] : [])];
	}

	/**
	 * This process's environment, with the key the program is to use. With
	 * the key given this way the program listens to that key's account, and
	 * not to whichever one `stripe login` last chose.
	 *
	 * @return array<string,string>
	 */
	private function environment(string $apiKey): array
	{
		return [self::KEY => $apiKey] + getenv();
	}

	private function binary(): ?string
	{
		$name = PHP_OS_FAMILY === 'Windows' ? 'stripe.exe' : 'stripe';

		foreach (explode(PATH_SEPARATOR, $this->path ?? (string)getenv('PATH')) as $folder) {
			$file = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $name;
			if ($folder !== '' && is_file($file) && is_executable($file)) {
				return $file;
			}
		}

		return null;
	}
}
