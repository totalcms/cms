<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;

/**
 * Decides which discovered extensions get registered on this request.
 *
 * Two passes, both moved verbatim from ExtensionManager::discoverAndRegister():
 * enrol() writes state for first-seen extensions (bundled default_enabled
 * ship pre-approved, everything else lands disabled) and revokes consent
 * that was auto-written for a bundled manifest when a non-bundled one now
 * shadows the same id; selectForRegister() filters to enabled, sorts by
 * dependency, and applies the quarantine, incompatibility and
 * update-re-consent gates, recording state and errors as it goes.
 */
final readonly class ExtensionEnrollment
{
	public function __construct(
		private ExtensionStateRepository $stateRepository,
		private ExtensionDiscovery $discovery,
		private ExtensionDependencySorter $sorter,
		private ManifestValidator $manifestValidator,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string,ExtensionManifest> $discovered */
	public function enrol(array $discovered): void
	{
		$states = $this->stateRepository->loadAll();

		// Auto-register state for newly discovered extensions
		foreach ($discovered as $id => $manifest) {
			$existingState = $states[$id] ?? null;

			if (!$existingState instanceof ExtensionState) {
				// Bundled extensions that declare default_enabled ship pre-approved
				// (reviewed in the package, versioned with core) - register them
				// fresh as ENABLED. Everything else, including a sideloaded/
				// third-party extension that declares default_enabled, is untrusted
				// code an operator hasn't consented to yet, so it stays DISABLED.
				$initiallyEnabled = $manifest->bundled && $manifest->defaultEnabled;

				if ($initiallyEnabled) {
					$this->logger->info(sprintf(
						"Extension '%s' has no stored state, registering fresh as ENABLED (bundled, default_enabled, version '%s').",
						$id,
						$manifest->version,
					));
				} else {
					// DIAGNOSTIC: a discovered extension with no stored state is
					// registered fresh as DISABLED. For a genuinely new extension
					// this is correct; for one that was previously enabled it means
					// its entry in extensions.json was lost (state-file reset /
					// relocated on update) — which presents as "had to re-enable".
					$this->logger->warning(sprintf(
						"Extension '%s' has no stored state — registering fresh as DISABLED (version '%s'). "
						. 'If it was previously enabled, its extensions.json state was lost.',
						$id,
						$manifest->version,
					));
				}
				$this->stateRepository->saveState($id, new ExtensionState(
					enabled: $initiallyEnabled,
					installedAt: date('c'),
					version: $manifest->version,
					autoEnrolledBundled: $initiallyEnabled,
				));

				continue;
			}

			// Shadow-consent gap: a saved 'enabled' record was auto-written for a
			// BUNDLED default_enabled manifest of this id (autoEnrolledBundled),
			// but THIS discovery resolves the id to a NON-bundled manifest instead
			// (a copy dropped into tcms-data/extensions/ or the project extensions
			// dir shadows the bundled one - later roots win, see discover()). That
			// auto-written consent belongs to the bundled code that was reviewed
			// and shipped in the package; it must not silently transfer to whatever
			// non-bundled code now resolves to the same id. Force it back to
			// disabled and clear the marker so this only fires once per occurrence
			// - from here it behaves like any other never-consented extension until
			// an operator explicitly enables it (which is either the deliberate
			// local override the docs describe, or an id-squat they now know about).
			if ($existingState->autoEnrolledBundled && !$manifest->bundled) {
				$this->logger->warning(sprintf(
					"Extension '%s' resolved to a non-bundled manifest, but its saved 'enabled' state was auto-written for the bundled default_enabled extension of the same id. Not transferring that consent: disabling until an operator explicitly re-enables it. Verify this is an override you intended and not another extension using the same id.",
					$id,
				));

				$existingState->enabled             = false;
				$existingState->autoEnrolledBundled = false;
				$this->stateRepository->saveState($id, $existingState);
			}
		}
	}

	/**
	 * @param  array<string,ExtensionManifest> $discovered
	 *
	 * @return array<string,ExtensionManifest> manifests to register, in dependency order
	 */
	public function selectForRegister(array $discovered): array
	{
		// Sort enabled extensions by dependencies
		$enabledManifests = array_filter(
			$discovered,
			fn (ExtensionManifest $m): bool => $this->stateRepository->isEnabled($m->id, $m),
		);

		// DIAGNOSTIC: snapshot the enabled/disabled split at boot. Comparing
		// this line across an upgrade shows at a glance whether the enabled set
		// shrank wholesale (state loss) versus an individual extension being
		// disabled by the re-consent gate below.
		$skippedDisabled = array_values(
			array_diff(array_keys($discovered), array_keys($enabledManifests))
		);
		$this->logger->info(sprintf(
			'Extension load: discovered [%s]; enabled [%s]; skipped-disabled [%s].',
			implode(', ', array_keys($discovered)),
			implode(', ', array_keys($enabledManifests)),
			implode(', ', $skippedDisabled),
		));

		try {
			$sortedIds = $this->sorter->sort($enabledManifests);
		} catch (\RuntimeException $e) {
			$this->logger->error('Extension dependency error: ' . $e->getMessage());
			$sortedIds = array_keys($enabledManifests);
		}

		$selected = [];
		foreach ($sortedIds as $id) {
			$manifest = $enabledManifests[$id] ?? null;
			if ($manifest === null) {
				continue;
			}

			// Auto-quarantined extensions stay enabled (the operator didn't disable
			// them — the SYSTEM held them back after repeated crashes), so they pass
			// the isEnabled() filter above. Skip loading them here until the operator
			// re-enables (which clears the quarantine) so a crash-looping extension
			// can't take the request down again.
			$state = $this->stateRepository->getState($id);
			if ($state instanceof ExtensionState && $state->isQuarantined()) {
				$this->logger->warning("Extension '{$id}' is quarantined, skipping load.");

				continue;
			}

			$reasons = $this->manifestValidator->getIncompatibilityReasons($manifest);
			if ($reasons !== []) {
				$this->logger->info("Extension '{$id}' is incompatible, skipping: " . implode('; ', $reasons));
				$this->stateRepository->recordError($id, implode('; ', $reasons));

				continue;
			}

			// Update re-consent: when a SIDELOADED extension's on-disk version no
			// longer matches the version it was enabled at, the operator did not
			// review this code. Statically scan the NEW code before it can register:
			// risky patterns => disable it (and don't load it); clean => let it load
			// but record the new version so any newly-registered capability lands
			// OFF (see updateStoredCapabilities). Built-ins are exempt — they version
			// with core and ship reviewed in the package.
			if (
				$state instanceof ExtensionState
				&& !$manifest->bundled
				&& $state->version !== ''
				&& $manifest->version !== $state->version
			) {
				// DIAGNOSTIC: re-consent only triggers when the extension's OWN
				// on-disk version differs from the version it was enabled at. If
				// this fires after a Total-CMS-only update where the operator
				// did not touch the extension, the manifest version is changing
				// unexpectedly — log both values so we can see exactly what moved.
				$this->logger->warning(sprintf(
					"Extension '%s' update re-consent triggered: on-disk version '%s' != enabled-at version '%s'. Scanning new code.",
					$id,
					$manifest->version,
					$state->version,
				));

				$extPath  = $this->discovery->getExtensionPath($id);
				$findings = $extPath !== null ? (new DangerousCodeScanner())->scan($extPath) : [];

				if ($extPath === null) {
					// No path to scan — treat as clean (nothing to load anyway;
					// registerExtension will report the missing directory) but log
					// so a vanished extension dir doesn't silently skip the gate.
					$this->logger->warning("Extension '{$id}' updated but its directory could not be resolved; skipping update scan.");
				}

				if ($findings !== []) {
					// Risky update — disable, record why, bump the stored version so
					// the gate doesn't re-trigger every request, and do NOT load it.
					// The new (risky) code never registers or runs.
					$state->enabled        = false;
					$state->updateDisabled = [
						'reason'    => 'A new version of this extension added risky code patterns.',
						'findings'  => count($findings),
						'updatedAt' => gmdate('c'),
					];
					$state->version = $manifest->version;
					$this->stateRepository->saveState($id, $state);
					$this->logger->warning("Extension '{$id}' disabled: an update added risky code (" . count($findings) . ' findings).');

					continue;
				}

				// Clean update — record the new version and let it load normally.
				// Newly-registered capabilities land OFF via updateStoredCapabilities.
				$this->logger->info(sprintf(
					"Extension '%s' update re-consent: new version '%s' scanned clean; kept enabled (any new capabilities default off).",
					$id,
					$manifest->version,
				));
				$state->version = $manifest->version;
				$this->stateRepository->saveState($id, $state);
			}

			$selected[$id] = $manifest;
		}

		return $selected;
	}
}
