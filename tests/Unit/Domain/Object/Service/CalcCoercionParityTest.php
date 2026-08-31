<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\CalcService;

/**
 * Cross-engine parity: the PHP calc engine must coerce field values exactly as
 * declared in tests/fixtures/calc-number-coercion.json. The JS engine asserts
 * the SAME file (tests/js/calcParity.test.mjs), so a coercion changed in one
 * engine but not the other fails this suite on the engine that's missing it.
 *
 * This matters because calc runs twice: live in the browser as the operator
 * types, and again here on save. If the engines disagree, the total on screen is
 * not the total that gets stored.
 */
class CalcCoercionParityTest extends TestCase
{
	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function coercionProvider(): array
	{
		$path     = __DIR__ . '/../../../../fixtures/calc-number-coercion.json';
		$manifest = json_decode((string)file_get_contents($path), true);

		$cases = [];
		if (is_array($manifest) && isset($manifest['cases']) && is_array($manifest['cases'])) {
			foreach ($manifest['cases'] as $case) {
				if (is_array($case)) {
					$cases[(string)($case['name'] ?? '')] = [$case];
				}
			}
		}

		return $cases;
	}

	/**
	 * @param array<string,mixed> $case
	 *
	 */
	#[DataProvider('coercionProvider')]
	public function testEngineMatchesSharedCoercionSet(array $case): void
	{
		$calc = new CalcService();

		// A null case stands for a missing key, which is how the JS engine sees
		// an absent field — bind nothing rather than an explicit null.
		$data = $case['value'] === null ? [] : ['v' => $case['value']];

		$this->assertSame(
			(float)$case['equals'],
			$calc->evaluate('${v}', $data),
			(string)$case['name'],
		);
	}
}
