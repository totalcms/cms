# Extension Stability — Plan 3: Transparency Quick Wins Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop operators from enabling an extension blind. Before an extension is enabled, show what it *does* (its capabilities, especially unauthenticated public routes) and flag high-risk PHP calls found in its source — informed consent, not enforcement.

**Architecture:** Two cheap, independent additions that hook the existing enable flow. (1) A `DangerousCodeScanner` greps the extension's PHP files once at enable for high-risk function calls and returns flagged matches. (2) Capability disclosure reuses the capabilities already auto-detected during the trial `register()`. Both are surfaced in a pre-enable review step in the admin UI; enabling proceeds only after the operator confirms. No sandboxing, no runtime cost — the scan runs once, at enable.

**Tech Stack:** PHP 8.2+, Slim 4, PHP-DI, Twig 3, Pest, PHPStan Level 8.

**Spec:** `docs/planning/extension-stability-guardrails.md`
**Depends on:** nothing in Plans 1-2 (fully independent — can ship before or after). Reuses existing capability detection in `ExtensionManager`.

---

## Design decisions locked from the spec (read before starting)

- **Informed consent, not enforcement.** We can't stop arbitrary PHP; we ensure nobody enables something blind. The operator can always "Enable anyway."
- **Scan runs once, at enable.** Zero runtime cost.
- **High-risk patterns:** `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `eval`, backtick operator, `curl_exec`, `fsockopen`, `file_get_contents('http`, `file_put_contents`, `base64_decode`.
- **Capability disclosure foregrounds the scary ones:** public (unauthenticated) routes, event listeners on all object data, container definitions.
- **Integrity hashing (QW3) is OUT** — deferred to the registry/signing phase.

## File Structure

**Create:**
- `src/Domain/Extension/Service/DangerousCodeScanner.php` — regex sweep of an extension's PHP files.
- `src/Action/Admin/AdminExtensionReviewAction.php` — renders the pre-enable review page.
- `resources/templates/admin/extension-review.twig` — the review/confirm UI.
- `tests/Unit/Domain/Extension/DangerousCodeScannerTest.php`
- `tests/fixtures/extensions/test-vendor/dangerous-ext/` — a fixture with a flagged call (committed to git).

**Modify:**
- `src/Action/Admin/` route definitions — add a `GET /admin/extensions/{id}/review` route.
- `resources/templates/admin/extensions.twig` — point the Enable control at the review page instead of POSTing enable directly (for not-yet-enabled extensions).
- `src/Domain/Extension/Service/ExtensionManager.php` — expose a `getEnableReview($id)` method returning `{capabilities, scanFindings}`.

---

## Phase 1 — Dangerous-code scanner (QW1)

### Task 1: `DangerousCodeScanner`

**Files:**
- Create: `src/Domain/Extension/Service/DangerousCodeScanner.php`
- Test: `tests/Unit/Domain/Extension/DangerousCodeScannerTest.php`
- Fixture: `tests/fixtures/extensions/test-vendor/dangerous-ext/Extension.php`

- [ ] **Step 1: Create the fixture**

Create `tests/fixtures/extensions/test-vendor/dangerous-ext/Extension.php` containing at least one flagged call, e.g.:

```php
<?php
// fixture only — used to test the scanner
function dangerous_demo(): string
{
	$out = shell_exec('ls -la');           // flagged: shell_exec
	$data = file_get_contents('http://evil.test/x'); // flagged: network fetch
	return base64_decode($out . $data);    // flagged: base64_decode
}
```

(This is a fixture — it's never executed. Commit it to git per the project convention for `tests/fixtures/extensions/`.)

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;

describe('DangerousCodeScanner', function () {
	$fixtureDir = __DIR__ . '/../../../fixtures/extensions/test-vendor/dangerous-ext';

	test('flags shell_exec', function () use ($fixtureDir) {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('shell_exec');
	});

	test('flags network fetches and base64_decode', function () use ($fixtureDir) {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('file_get_contents(http');
		expect($patterns)->toContain('base64_decode');
	});

	test('each finding has file, line, and snippet', function () use ($fixtureDir) {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		expect($findings[0])->toHaveKeys(['pattern', 'file', 'line', 'snippet']);
	});

	test('a clean directory returns no findings', function () {
		$tmp = sys_get_temp_dir() . '/clean-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp);
		file_put_contents($tmp . '/Extension.php', "<?php\nfunction ok() { return 1 + 1; }\n");
		$findings = (new DangerousCodeScanner())->scan($tmp);
		expect($findings)->toBe([]);
	});
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension/DangerousCodeScannerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

/**
 * One-time heuristic scan of an extension's PHP source for high-risk calls.
 *
 * NOT a sandbox and NOT a guarantee — it surfaces patterns worth a human's
 * attention before enabling. Runs once at enable; zero runtime cost.
 */
final class DangerousCodeScanner
{
	/** @var array<string,string> label => regex (without delimiters) */
	private const PATTERNS = [
		'exec'                    => '\bexec\s*\(',
		'shell_exec'              => '\bshell_exec\s*\(',
		'system'                  => '\bsystem\s*\(',
		'passthru'                => '\bpassthru\s*\(',
		'proc_open'               => '\bproc_open\s*\(',
		'popen'                   => '\bpopen\s*\(',
		'eval'                    => '\beval\s*\(',
		'backtick'               => '`[^`]+`',
		'curl_exec'               => '\bcurl_exec\s*\(',
		'fsockopen'               => '\bfsockopen\s*\(',
		'file_get_contents(http'  => 'file_get_contents\s*\(\s*[\'"]https?:',
		'file_put_contents'       => '\bfile_put_contents\s*\(',
		'base64_decode'           => '\bbase64_decode\s*\(',
	];

	/**
	 * @return list<array{pattern:string,file:string,line:int,snippet:string}>
	 */
	public function scan(string $extensionDir): array
	{
		$findings = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($extensionDir, \FilesystemIterator::SKIP_DOTS),
		);

		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
				continue;
			}
			$contents = file_get_contents($file->getPathname());
			if ($contents === false) {
				continue;
			}
			$lines = explode("\n", $contents);
			foreach ($lines as $i => $line) {
				foreach (self::PATTERNS as $label => $regex) {
					if (preg_match('/' . $regex . '/i', $line) === 1) {
						$findings[] = [
							'pattern' => $label,
							'file'    => str_replace($extensionDir . '/', '', $file->getPathname()),
							'line'    => $i + 1,
							'snippet' => trim($line),
						];
					}
				}
			}
		}

		return $findings;
	}
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `composer run test -- tests/Unit/Domain/Extension/DangerousCodeScannerTest.php`
Expected: PASS (all 4).

- [ ] **Step 6: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/DangerousCodeScanner.php tests/Unit/Domain/Extension/DangerousCodeScannerTest.php tests/fixtures/extensions/test-vendor/dangerous-ext/
git commit -m "feat(extensions): add DangerousCodeScanner for pre-enable source review"
```

---

## Phase 2 — Enable review data (QW1 + QW2)

### Task 2: `ExtensionManager::getEnableReview()`

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php`
- Test: extend the extension manager tests.

This returns everything the review page needs: the detected capabilities (reuse `detectCapabilities()` from the trial register, ~line 1023) and the scanner findings.

- [ ] **Step 1: Write the failing test**

```php
test('getEnableReview returns capabilities and scan findings', function () {
	// Point the manager's discovery at the dangerous-ext fixture, then:
	$review = $manager->getEnableReview('test-vendor/dangerous-ext');
	expect($review)->toHaveKeys(['capabilities', 'findings']);
	expect(array_column($review['findings'], 'pattern'))->toContain('shell_exec');
});
```

Wire up the manager construction to discover the fixture extension (match the existing extension-manager test setup, which already references `tests/fixtures/extensions/`).

- [ ] **Step 2: Run to verify it fails**

Run the filtered test. Expected: FAIL — `getEnableReview` undefined.

- [ ] **Step 3: Implement the method**

Inject `DangerousCodeScanner` into `ExtensionManager` (constructor + DI). Add:

```php
/**
 * Pre-enable review data: what the extension registers + risky source patterns.
 *
 * @return array{capabilities:array<string,bool>,findings:list<array{pattern:string,file:string,line:int,snippet:string}>}
 */
public function getEnableReview(string $extensionId): array
{
	$manifest = $this->discoveredManifests[$extensionId] ?? null;
	if ($manifest === null) {
		return ['capabilities' => [], 'findings' => []];
	}

	$capabilities = $this->detectCapabilities($extensionId); // existing trial-register detection
	$findings     = $this->dangerousCodeScanner->scan($manifest->path); // confirm the manifest exposes its dir

	return ['capabilities' => $capabilities, 'findings' => $findings];
}
```

Confirm how to get the extension's directory from the manifest (the discovery already tracks paths — check `ExtensionManifest`/`ExtensionDiscovery` for the path accessor; if `detectCapabilities` is `private`, it's fine since this method is on the same class).

- [ ] **Step 4: Run to verify it passes**

Run the filtered test. Expected: PASS.

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php config/ tests/
git commit -m "feat(extensions): add getEnableReview (capabilities + source scan)"
```

---

## Phase 3 — Review UI + confirm-to-enable

### Task 3: Review action + route

**Files:**
- Create: `src/Action/Admin/AdminExtensionReviewAction.php`
- Modify: the admin route definitions (find via `grep -rn "extensions/{id}/disable\|AdminExtensionsAction" config/`)

- [ ] **Step 1: Write the action**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\TwigRenderer; // confirm the real renderer type used by sibling actions

final readonly class AdminExtensionReviewAction
{
	public function __construct(
		private ExtensionManager $manager,
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id     = $args['vendor'] . '/' . $args['name']; // match how sibling routes parse {id}
		$review = $this->manager->getEnableReview($id);

		return $this->twigRenderer->template($response, 'admin/extension-review.twig', [
			'extensionId'  => $id,
			'capabilities' => $review['capabilities'],
			'findings'     => $review['findings'],
		]);
	}
}
```

Mirror the exact constructor/renderer pattern of `AdminExtensionsAction` (the research showed it uses a Twig renderer + the manager). Match how the existing disable/enable routes capture the `{id}` (whether as one `{id}` param or `{vendor}/{name}`).

- [ ] **Step 2: Register the route**

Add `GET /admin/extensions/{id}/review` (mirroring the disable route's path style) pointing at `AdminExtensionReviewAction`.

- [ ] **Step 3: Manually verify**

Visit `/admin/extensions/test-vendor/dangerous-ext/review` (with the fixture discoverable) → the action resolves. (Template lands in Task 4.)

- [ ] **Step 4: Commit**

```bash
git add src/Action/Admin/AdminExtensionReviewAction.php config/
git commit -m "feat(extensions): add pre-enable review action + route"
```

### Task 4: Review template

**Files:**
- Create: `resources/templates/admin/extension-review.twig`

- [ ] **Step 1: Write the template**

```twig
{% extends 'admin/layout.twig' %}  {# confirm the real admin layout name #}

{% block content %}
<article class="dash-card">
	<h1>Review before enabling: {{ extensionId }}</h1>

	<h2>What this extension can do</h2>
	<ul class="ext-capabilities">
		{% for cap, enabled in capabilities %}
		<li{% if cap in ['routes:public', 'events:listen', 'container'] %} class="risk"{% endif %}>
			{{ cap }}
			{% if cap == 'routes:public' %}<strong>— exposes public, unauthenticated endpoints</strong>{% endif %}
			{% if cap == 'events:listen' %}<strong>— can observe all content changes</strong>{% endif %}
			{% if cap == 'container' %}<strong>— registers services in the app container</strong>{% endif %}
		</li>
		{% endfor %}
	</ul>

	{% if findings is not empty %}
	<h2>⚠️ High-risk code patterns found</h2>
	<p>The following calls were found in this extension's source. They aren't proof of anything malicious, but review them before enabling.</p>
	<table class="dash-table">
		<thead><tr><th>Pattern</th><th>File</th><th>Line</th><th>Code</th></tr></thead>
		<tbody>
		{% for f in findings %}
			<tr><td>{{ f.pattern }}</td><td>{{ f.file }}</td><td>{{ f.line }}</td><td><code>{{ f.snippet }}</code></td></tr>
		{% endfor %}
		</tbody>
	</table>
	{% else %}
	<p>No high-risk code patterns were found in this extension's source.</p>
	{% endif %}

	<footer style="display:flex; gap:0.5rem; margin-top:1rem;">
		<form method="post" action="{{ cms.dashboard }}/extensions/{{ extensionId }}/enable">
			{{ csrf_field() }}
			<button type="submit" class="btn">Enable anyway</button>
		</form>
		<a href="{{ cms.dashboard }}/extensions" class="btn btn-secondary">Cancel</a>
	</footer>
</article>
{% endblock %}
```

Confirm the real layout name, the dashboard URL helper, and `csrf_field()` usage against `extensions.twig`.

- [ ] **Step 2: Manually verify**

Load the review page for the fixture → capabilities list with public-route/events/container highlighted, the findings table with `shell_exec`/network/`base64_decode` rows, and Enable-anyway / Cancel buttons.

- [ ] **Step 3: Commit**

```bash
git add resources/templates/admin/extension-review.twig
git commit -m "feat(extensions): add pre-enable review template (capabilities + risk findings)"
```

### Task 5: Route the Enable control through the review page

**Files:**
- Modify: `resources/templates/admin/extensions.twig` (the disabled-extension branch of `extensionCard`)

- [ ] **Step 1: Change the Enable control**

For a not-yet-enabled, compatible extension, replace the direct enable POST with a link to the review page:

```twig
<a href="{{ cms.dashboard }}/extensions/{{ ext.id }}/review" class="btn btn-sm">Review &amp; enable</a>
```

Leave the existing `POST .../enable` route intact — it's the target of the review page's "Enable anyway" button, and (from Plan 1) the re-enable-from-quarantine action. So enabling always funnels through the same endpoint; only the *entry point* changes to a review gate for first enables.

- [ ] **Step 2: Manually verify the full flow**

From `/admin/extensions`, click "Review & enable" on the fixture → review page → "Enable anyway" → extension enables and returns to the list as enabled.

- [ ] **Step 3: Commit**

```bash
git add resources/templates/admin/extensions.twig
git commit -m "feat(extensions): gate first enable behind the review page"
```

---

## Phase 4 — Verification

### Task 6: Quality gate

- [ ] **Step 1: Full suite**

```bash
composer run stan
composer run test
```

Expected: PHPStan Level 8 clean; tests green.

- [ ] **Step 2: Manual end-to-end**

With the dangerous-ext fixture: list → Review & enable → see capabilities + findings → Enable anyway → enabled. With a clean extension: review page shows "No high-risk patterns" and an unalarming capability list.

- [ ] **Step 3: Commit any test additions**

```bash
git add tests/
git commit -m "test(extensions): transparency quick-wins coverage"
```

---

## Self-review notes (for the implementer)

- **Spec coverage:** QW1 source scan (Tasks 1-2), QW2 capability disclosure (Tasks 2-4), surfaced in a pre-enable review gate (Tasks 3-5). QW3 integrity hashing intentionally excluded (deferred to registry phase) — matches spec.
- **Confirm before trusting copy-paste:** how the `{id}` route param is captured (one `{id}` vs `{vendor}/{name}`), the admin layout/template names, the `TwigRenderer` type and `cms.dashboard` helper, and the manifest's directory-path accessor for the scanner (Task 2 Step 3).
- **No enforcement:** the operator can always "Enable anyway." This is consent and visibility, not a gate that blocks. Keep it that way — blocking belongs to the future registry/signing phase, not here.
- **Independence:** this plan touches `ExtensionManager` (constructor gets `DangerousCodeScanner`) and `extensions.twig` (Enable control) — the same files Plans 1-2 touch. If executed after them, expect to merge into those constructors/templates rather than replace.
