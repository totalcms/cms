<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Settings forms: a site settings section, and an extension's permission
 * toggles plus its own settings schema.
 *
 * Both render every field themselves from a settings schema and hand the
 * markup to a plain TotalForm — there is no SchemaData behind them. Reached
 * through the factory (`cms.form.settings()`), which delegates.
 */
final readonly class SettingsForms
{
	public function __construct(
		private TotalFormFactory $forms,
		private SettingsSchemaFetcher $settingsSchemaFetcher,
		private SettingsFetcher $settingsFetcher,
		private TranslationService $translationService,
		private ExtensionDiscovery $extensionDiscovery,
		private ExtensionSettingsManager $extensionSettingsManager,
		private ExtensionManager $extensionManager,
		private Config $config,
		private SettingsRepository $settingsRepository,
	) {
	}

	/**
	 * Generate a settings form for a specific section.
	 *
	 * @param array<string,mixed> $options
	 */
	public function settings(string $section, array $options = []): string
	{
		// Load schema and data using injected services
		$schema      = $this->settingsSchemaFetcher->getSchema($section);
		$sectionData = $this->settingsFetcher->loadSection($section);
		// The EFFECTIVE configuration (defaults -> tcms.php -> settings.json),
		// not the shipped defaults. Requiring defaults.php here skipped the
		// merge, so anything set in tcms.php rendered as its default and was
		// destroyed the first time an operator saved the form.
		$defaults    = $this->config->mergedSettings();
		$timezones   = $options['timezones'] ?? timezone_identifiers_list();

		if ($schema === null || !isset($schema['properties']) || !is_array($schema['properties'])) {
			return '<p class="error">Schema not found for this settings section.</p>';
		}

		$formfields = '';

		foreach ($schema['properties'] as $fieldName => $fieldSchema) {
			// Resolve field type: "field" takes precedence over "type"
			$fieldType = $fieldSchema['field'] ?? $fieldSchema['type'] ?? 'text';

			// Get current value with priority: sectionData > defaults > schema default > empty string
			$currentValue = '';
			if (isset($fieldSchema['default'])) {
				$currentValue = $fieldSchema['default'];
			}
			// General settings are stored at top level in defaults, not under section keys
			if ($section === 'general' && isset($defaults[$fieldName])) {
				$currentValue = $defaults[$fieldName];
			} elseif (isset($defaults[$section][$fieldName])) {
				$currentValue = $defaults[$section][$fieldName];
			}
			if (isset($sectionData[$fieldName])) {
				$currentValue = $sectionData[$fieldName];
			}

			// Special handling for JSON fields - convert arrays to JSON strings for display
			if ($fieldType === 'json' && is_array($currentValue)) {
				$currentValue = json_encode($currentValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			}

			// Build field options
			$fieldSettings = $this->fieldSettingsFor($fieldType, $fieldSchema, $currentValue);

			// Merge schema-reference keys into settings for fields that hydrate from another schema
			if (in_array($fieldType, ['deck', 'deckTable', 'card'], true)) {
				$schemaref = PropertyDefinition::extractSchemaRef($fieldSchema);
				if ($schemaref !== null) {
					$fieldSettings['settings']['schemaref'] = $schemaref;
				}
				if (isset($fieldSchema['deckItemLabel'])) {
					$fieldSettings['settings']['deckItemLabel'] = $fieldSchema['deckItemLabel'];
				}
			}

			// Special handling for timezone field
			if (isset($fieldSchema['settings']['timezoneOptions']) && $fieldSchema['settings']['timezoneOptions']) {
				$timezoneOptions = [];
				foreach ($timezones as $tz) {
					$timezoneOptions[] = ['value' => $tz, 'label' => $tz];
				}
				$fieldSettings['options'] = $timezoneOptions;
			}

			$formfields .= $this->forms->field($fieldType, $fieldName, $fieldSettings);
		}

		// Which file a save lands in, stated on the page. Only meaningful when
		// this install shares its data folder with others — a single-site
		// install renders nothing here, which is what keeps every settings
		// golden snapshot unchanged.
		$scope = '';
		if ($this->settingsRepository->hasOverlay()) {
			$scope = $this->settingsRepository->ownsSection($section)
				? $this->translationService->trans('settings.scope_site', [
					'%file%' => $this->settingsRepository->overlayFilename(),
				])
				: $this->translationService->trans('settings.scope_shared');
			$scope = '<p class="settings-scope">' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '</p>';
		}

		return $this->forms->totalform('/admin/settings/' . $section, $scope . $formfields, [
			'method'      => 'POST',
			'save'        => $this->translationService->trans('btn.save_settings'),
			'class'       => 'help-on-hover help-box',
			// Optional per-section layout. Absent from most settings schemas, in
			// which case TotalForm renders one field per row exactly as before.
			'formgrid'    => is_string($schema['formgrid'] ?? null) ? $schema['formgrid'] : '',
		]);
	}

	/**
	 * Generate a settings form for an extension.
	 *
	 * Includes auto-generated permission toggles for each detected capability,
	 * followed by the extension's custom settings (if a settings schema exists).
	 */
	public function extensionSettings(string $extensionId): string
	{
		$formfields  = $this->buildPermissionToggles($extensionId);
		$formfields .= $this->buildExtensionSettingsFields($extensionId, $formfields !== '');

		if ($formfields === '') {
			return '<p>This extension has no configurable settings.</p>';
		}

		return $this->forms->totalform('/admin/extensions/' . $extensionId . '/settings', $formfields, [
			'method' => 'POST',
			'save'   => $this->translationService->trans('btn.save_settings'),
			'class'  => 'help-on-hover help-box',
		]);
	}

	private function buildPermissionToggles(string $extensionId): string
	{
		$permissions      = $this->extensionManager->getPermissions($extensionId);
		$capabilityLabels = ExtensionContext::capabilityLabels();

		if ($permissions === []) {
			return '';
		}

		$toggles = '';
		foreach ($permissions as $capability => $enabled) {
			// Always-on infrastructure (e.g. container defs) isn't toggleable —
			// disabling it would only leave the extension enabled-but-broken — so
			// it's applied unconditionally and omitted from the permissions UI.
			if (in_array($capability, ExtensionContext::ALWAYS_ON_CAPABILITIES, true)) {
				continue;
			}

			$label    = $capabilityLabels[$capability] ?? $capability;
			$toggles .= $this->forms->field('toggle', 'perm_' . str_replace(':', '_', $capability), [
				'field' => 'toggle',
				'label' => $label,
				'value' => $enabled,
			]);
		}

		// Nothing left to toggle (e.g. an extension whose only capability is a
		// container def) — render no Permissions section at all.
		if ($toggles === '') {
			return '';
		}

		return '<fieldset class="ext-permissions">'
			. '<legend>Permissions</legend>'
			. '<div class="ext-permissions-grid">' . $toggles . '</div>'
			. '</fieldset>';
	}

	private function buildExtensionSettingsFields(string $extensionId, bool $hasPermissions): string
	{
		$schema = $this->loadExtensionSettingsSchema($extensionId);
		if ($schema === null) {
			return '';
		}

		$settings   = $this->extensionSettingsManager->getSettings($extensionId);
		$formfields = '';

		if ($hasPermissions) {
			$formfields .= '<h3 style="margin:2rem 0 0.5rem;">Settings</h3>';
		}

		foreach ($schema as $fieldName => $fieldSchema) {
			$fieldType    = $fieldSchema['field'] ?? $fieldSchema['type'] ?? 'text';
			$currentValue = $settings[$fieldName] ?? $fieldSchema['default'] ?? '';

			if ($fieldType === 'json' && is_array($currentValue)) {
				$currentValue = json_encode($currentValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			}

			$fieldSettings = $this->fieldSettingsFor($fieldType, $fieldSchema, $currentValue);

			$formfields .= $this->forms->field($fieldType, $fieldName, $fieldSettings);
		}

		return $formfields;
	}

	/**
	 * Field options from a settings-schema property: the common attributes plus
	 * the select `options` when the schema declares any.
	 *
	 * @param array<string,mixed> $fieldSchema
	 *
	 * @return array<string,mixed>
	 */
	private function fieldSettingsFor(string $fieldType, array $fieldSchema, mixed $currentValue): array
	{
		$fieldSettings = [
			'field'       => $fieldType,
			'label'       => $fieldSchema['label'] ?? '',
			'help'        => $fieldSchema['help'] ?? '',
			'placeholder' => $fieldSchema['placeholder'] ?? '',
			'value'       => $currentValue,
			'required'    => $fieldSchema['required'] ?? false,
			'min'         => $fieldSchema['min'] ?? null,
			'max'         => $fieldSchema['max'] ?? null,
			'settings'    => $fieldSchema['settings'] ?? [],
		];

		if (isset($fieldSchema['options'])) {
			$fieldSettings['options'] = $fieldSchema['options'];
		}

		return $fieldSettings;
	}

	/**
	 * Load and validate the settings schema properties for an extension.
	 *
	 * @return array<string,array<string,mixed>>|null
	 */
	private function loadExtensionSettingsSchema(string $extensionId): ?array
	{
		$manifests = $this->extensionDiscovery->discover();
		$manifest  = $manifests[$extensionId] ?? null;

		if ($manifest === null || $manifest->settingsSchema === null) {
			return null;
		}

		$extPath = $this->extensionDiscovery->getExtensionPath($extensionId);
		if ($extPath === null) {
			return null;
		}

		$schemaFile = $extPath . '/' . $manifest->settingsSchema;
		if (!is_file($schemaFile)) {
			return null;
		}

		$schemaJson = file_get_contents($schemaFile);
		if ($schemaJson === false) {
			return null;
		}

		$schema = json_decode($schemaJson, true);
		if (!is_array($schema) || !isset($schema['properties']) || !is_array($schema['properties'])) {
			return null;
		}

		return $schema['properties'];
	}
}
