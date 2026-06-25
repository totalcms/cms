<?php

namespace Tests\Domain\Object\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Service\AutogenService;

/**
 * Cross-engine parity: the PHP autogen engine must satisfy the shared feature
 * set declared in tests/fixtures/autogen-feature-set.json. The JS engine asserts
 * the SAME file (tests/js/autogenParity.test.mjs), so a token added to one engine
 * but not the other fails this suite on the engine that's missing it.
 */
class AutogenFeatureSetTest extends TestCase
{
	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function featureSetProvider(): array
	{
		$path     = __DIR__ . '/../../../fixtures/autogen-feature-set.json';
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
	 * @dataProvider featureSetProvider
	 */
	public function testEngineMatchesSharedFeatureSet(array $case): void
	{
		/** @var array<string,mixed> $fields */
		$fields = is_array($case['fields'] ?? null) ? $case['fields'] : [];

		$out = AutogenService::generateWithOidCount(
			(string)$case['pattern'],
			$fields,
			(int)$case['oid'],
		);

		if (array_key_exists('equals', $case)) {
			$this->assertSame($case['equals'], $out, (string)$case['name']);
		} else {
			$this->assertMatchesRegularExpression('/' . $case['matches'] . '/', $out, (string)$case['name']);
		}
	}
}
