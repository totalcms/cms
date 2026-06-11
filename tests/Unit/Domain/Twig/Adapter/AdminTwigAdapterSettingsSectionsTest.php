<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;

/**
 * settingsSections() is the single source of truth for both the settings page
 * sidebar and the quick-nav index. The headline test is the drift guard: every
 * settings schema under resources/schemas/settings/ must be represented as a
 * section (and vice versa), so a newly-added settings page can never silently
 * go missing from quick-nav again — the bug that started this (MCP missing).
 *
 * AdminTwigAdapter is a large readonly adapter, so we build it without the
 * constructor and inject only the three collaborators this method touches.
 */
final class AdminTwigAdapterSettingsSectionsTest extends TestCase
{
	/**
	 * @param array<string,bool> $opts editionOauth, simulateEdition
	 */
	private function makeAdapter(bool $editionOauth = true, bool $simulateEdition = true): AdminTwigAdapter
	{
		// Translator echoes the key back so assertions stay deterministic and
		// independent of locale strings.
		$translator = $this->createMock(TranslationService::class);
		$translator->method('trans')->willReturnArgument(0);

		$edition = $this->createMock(EditionFeatureService::class);
		$edition->method('can')->willReturn($editionOauth);

		$license = $this->createMock(LicenseStatus::class);
		$license->method('canSimulateEdition')->willReturn($simulateEdition);

		$adapter    = (new \ReflectionClass(AdminTwigAdapter::class))->newInstanceWithoutConstructor();
		$reflection = new \ReflectionClass($adapter);
		$reflection->getProperty('translator')->setValue($adapter, $translator);
		$reflection->getProperty('editionFeatures')->setValue($adapter, $edition);
		$reflection->getProperty('licenseStatus')->setValue($adapter, $license);

		return $adapter;
	}

	/**
	 * @return list<string> setting schema ids (filename without .json)
	 */
	private function settingsSchemaIds(): array
	{
		$dir   = dirname(__DIR__, 5) . '/resources/schemas/settings';
		$files = glob($dir . '/*.json');
		$this->assertNotEmpty($files, 'Expected settings schema files to exist.');

		return array_map(static fn (string $f): string => basename($f, '.json'), $files);
	}

	public function testSettingsSectionsCoverEverySettingsSchemaAndViceVersa(): void
	{
		// Gating fully on so oauth + license are present — this is the complete
		// surface that must match the settings-schema directory one-to-one.
		$sectionIds = array_keys($this->makeAdapter(editionOauth: true, simulateEdition: true)->settingsSections());
		$schemaIds  = $this->settingsSchemaIds();

		sort($sectionIds);
		sort($schemaIds);

		// Equal sets in both directions: no settings page missing from nav, and
		// no nav entry pointing at a non-existent settings page.
		$this->assertSame(
			$schemaIds,
			$sectionIds,
			'settingsSections() must match resources/schemas/settings/ exactly. '
			. 'Missing from nav: ' . implode(', ', array_diff($schemaIds, $sectionIds)) . '. '
			. 'Phantom nav entries: ' . implode(', ', array_diff($sectionIds, $schemaIds)) . '.',
		);
	}

	public function testMcpSectionIsPresent(): void
	{
		// The regression that prompted this work: MCP settings were absent from
		// quick-nav. Pin it explicitly.
		$sections = $this->makeAdapter()->settingsSections();
		$this->assertArrayHasKey('mcp', $sections);
		$this->assertSame('settings.mcp', $sections['mcp']['label']);
	}

	public function testOauthSectionGatedByEditionFeature(): void
	{
		$this->assertArrayNotHasKey('oauth', $this->makeAdapter(editionOauth: false)->settingsSections());
		$this->assertArrayHasKey('oauth', $this->makeAdapter(editionOauth: true)->settingsSections());
	}

	public function testLicenseSectionGatedBySimulation(): void
	{
		$this->assertArrayNotHasKey('license', $this->makeAdapter(simulateEdition: false)->settingsSections());
		$this->assertArrayHasKey('license', $this->makeAdapter(simulateEdition: true)->settingsSections());
	}

	public function testSectionsAreSortedByLabelCaseInsensitive(): void
	{
		$labels = array_map(
			static fn (array $s): string => $s['label'],
			array_values($this->makeAdapter()->settingsSections()),
		);

		$sorted = $labels;
		usort($sorted, 'strcasecmp');

		$this->assertSame($sorted, $labels, 'Sections must be ordered by label (case-insensitive).');
	}

	public function testEverySectionHasLabelAndDescription(): void
	{
		foreach ($this->makeAdapter()->settingsSections() as $id => $section) {
			$this->assertArrayHasKey('label', $section, "Section {$id} missing label.");
			$this->assertArrayHasKey('description', $section, "Section {$id} missing description.");
			$this->assertNotSame('', $section['label'], "Section {$id} has empty label.");
		}
	}
}
