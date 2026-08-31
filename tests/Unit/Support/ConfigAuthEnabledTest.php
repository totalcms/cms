<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\Config;

/**
 * Twenty call sites gate on whether authentication is switched on. They used to
 * read `$config->auth['enable'] === false` directly, which is fine on a Config
 * built from defaults.php — the key is always there — and not fine on one
 * assembled programmatically from a partial settings array.
 */
final class ConfigAuthEnabledTest extends TestCase
{
	/** @param array<string,mixed> $auth */
	private function config(array $auth): Config
	{
		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = $auth;

		return $config;
	}

	public function testAuthIsOnWhenTheFlagIsMissingEntirely(): void
	{
		// Absent means enabled, matching the shipped default. Reading it as
		// "disabled" would take the guard off every admin route.
		$this->assertTrue($this->config([])->authEnabled());
	}

	public function testDoesNotWarnWhenTheFlagIsMissing(): void
	{
		// The bare read emitted "Undefined array key". It failed secure, but on
		// installs that promote warnings to exceptions it threw instead — from
		// AuthMiddleware that is a 500 on every authenticated request.
		$raised = [];
		set_error_handler(static function (int $number, string $message) use (&$raised): bool {
			$raised[] = $message;

			return true;
		});

		try {
			$this->config([])->authEnabled();
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $raised);
	}

	public function testOnlyAnExplicitFalseDisablesAuth(): void
	{
		$this->assertFalse($this->config(['enable' => false])->authEnabled());
		$this->assertTrue($this->config(['enable' => true])->authEnabled());
	}

	/** @dataProvider notFalse */
	public function testValuesThatAreNotExactlyFalseLeaveAuthOn(mixed $value): void
	{
		// The original checks were all `=== false`, so a settings file with
		// 'enable' => 0 or 'false' left auth ON. Preserved deliberately: this
		// is a security gate, and a sloppy value must not switch it off.
		$this->assertTrue($this->config(['enable' => $value])->authEnabled());
	}

	/** @return array<string,array{mixed}> */
	public static function notFalse(): array
	{
		return [
			'zero'          => [0],
			'empty string'  => [''],
			'string false'  => ['false'],
			'null'          => [null],
		];
	}
}
