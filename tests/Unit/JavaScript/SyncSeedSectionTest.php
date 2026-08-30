<?php

declare(strict_types=1);

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Seed Objects section in
 * `resources/templates/admin/utils/sync.twig`.
 *
 * The inline JS in that template is not exercised by any browser test, and
 * its sibling guard (SyncSelectionSelectorTest) exists because a bare-vs-
 * bracketed selector mismatch once made every "specific" selection silently
 * collapse to "sync nothing" — the form looked right and moved no data. A
 * new section reintroduces exactly that class of bug, so the couplings that
 * cannot be type-checked are pinned here instead.
 */
final class SyncSeedSectionTest extends TestCase
{
	private function syncTemplate(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../resources/templates/admin/utils/sync.twig');
	}

	public function testTheFieldNameMatchesBetweenMarkupAndSelector(): void
	{
		$twig = $this->syncTemplate();

		// The checklist field renders each option with a BARE name, so the
		// collecting selector must be bare too. `seed_objects[]` would match
		// nothing and every seed selection would silently post empty.
		$this->assertStringContainsString('cms.form.field("checklist", "seed_objects"', $twig);
		$this->assertStringContainsString('input[name="seed_objects"]:checked', $twig);
		$this->assertStringNotContainsString('input[name="seed_objects[]"]', $twig);
	}

	public function testThePostedFieldNameIsTheArrayFormTheServerParses(): void
	{
		// SyncAction::parseSeedSelection() reads $post['seed_objects'] as a
		// list, so the POST body must use the bracketed array form even
		// though the DOM selector is bare. The two differ on purpose.
		$this->assertStringContainsString("body.append('seed_objects[]', cid)", $this->syncTemplate());
	}

	public function testSeedingIsPushOnly(): void
	{
		$twig = $this->syncTemplate();

		// The section stays visible on a pull, so the guard has to be on the
		// send, not the render.
		$this->assertMatchesRegularExpression(
			"/if \(action === 'push'\) \{\s*\n\s*readSeedSelection\(\)/",
			$twig,
		);
		$this->assertStringContainsString("action === 'push' ? readSeedSelection() : []", $twig);
	}

	public function testTheSectionIsBuiltFromTheServerSideSeedableList(): void
	{
		$twig = $this->syncTemplate();

		// seedCollections comes from SyncableCollections::seedableInUi(), so
		// the carve-outs (auth, binary-only, playground, the five with their
		// own sections) can never drift between the CLI and this list.
		$this->assertStringContainsString('{% for collection in seedCollections %}', $twig);
		$this->assertStringContainsString('syncData.seedCollections', $twig);
	}

	public function testTheBareVariableIsActuallyAliasedFromSyncData(): void
	{
		// This template aliases its inputs at the top (`{% set schemas =
		// syncData.schemas %}`) and then uses the bare names throughout.
		// Shipping the loop without the alias is silent: Twig resolves the
		// undefined name to nothing, `|length == 0` is trivially true, and
		// the section renders "No collections are available to seed" on a
		// site with plenty of them. That is exactly what happened, and the
		// two assertions above did not catch it — both halves existed, they
		// were just never connected.
		$twig = $this->syncTemplate();

		$this->assertStringContainsString(
			'{% set seedCollections = syncData.seedCollections|default([]) %}',
			$twig,
		);

		// And the alias must come before the loop that reads it.
		$this->assertLessThan(
			strpos($twig, '{% for collection in seedCollections %}'),
			strpos($twig, '{% set seedCollections ='),
			'the alias must be set before the section reads it',
		);
	}

	public function testThePreviewSaysExistingObjectsAreSkipped(): void
	{
		// The likeliest support ticket is "I edited a post, re-seeded, and
		// nothing happened". That is correct behaviour, so the preview has
		// to say it before the operator confirms.
		$twig = $this->syncTemplate();

		$this->assertStringContainsString('will be seeded to production', $twig);
		$this->assertStringContainsString('Objects already on production are skipped', $twig);
	}

	public function testASeedOnlySelectionCanBeConfirmed(): void
	{
		// The confirm button is disabled off the diff counts. Seeding is not
		// in the diff, so without `seeded.length` a seed-only push would
		// render its preview and then refuse to run.
		$this->assertStringContainsString(
			'confirmBtn.disabled    = !overwrite.length && !create.length && !unchanged && !seeded.length;',
			$this->syncTemplate(),
		);
	}

	public function testTheTemplateHasNoFullWidthBrackets(): void
	{
		// A full-width ］ slipped into `seedCollections[cid］` while this
		// section was written. PHP lint does not see inside a Twig template
		// and the JS only breaks at runtime, in the browser, on the preview
		// path — so it is worth one assertion.
		$this->assertDoesNotMatchRegularExpression('/[［］｛｝（）]/u', $this->syncTemplate());
	}

	public function testTheSectionHasNoSelectAllToggle(): void
	{
		// ChoiceField renders a select-all glyph by default. Suppressed here
		// deliberately: the other checklists in the admin list schemas and
		// templates, where "all of them" is cheap and ordinary. This one
		// lists content collections holding thousands of objects each, and a
		// seed sends every object every time. One click should not arm the
		// heaviest operation the Sync Manager can perform.
		$twig = $this->syncTemplate();

		$section = substr(
			$twig,
			strpos($twig, 'sync-section-seed'),
			strpos($twig, '{% endset %}') - strpos($twig, 'sync-section-seed'),
		);

		$this->assertStringContainsString('toggleAll: false', $section);
	}
}
