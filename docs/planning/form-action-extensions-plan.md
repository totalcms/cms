# Form Action Extensions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make form actions extensible via `addFormAction()` and extract Pushover from core into a bundled extension.

**Architecture:** A `FormActionRegistry` collects extension-registered form actions. `TotalForm` serializes the registry into a `data-extension-actions` JSON attribute. The JS form processor dispatches unknown action types to extension-registered routes via a generic POST. Pushover becomes the first consumer.

**Tech Stack:** PHP 8.2+, Slim 4, PHP-DI, ES6+ (totalform.js), Pest tests

---

### Task 1: FormAction data class + FormActionRegistry

**Files:**
- Create: `src/Domain/Extension/Data/FormAction.php`
- Create: `src/Domain/Extension/Service/FormActionRegistry.php`
- Test: `tests/Unit/Domain/Extension/Service/FormActionRegistryTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;

final class FormActionRegistryTest extends TestCase
{
	public function testRegisterAndGet(): void
	{
		$registry = new FormActionRegistry();
		$action = new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover Notification');

		$registry->register($action);

		$this->assertSame($action, $registry->get('pushover'));
	}

	public function testGetReturnsNullForUnknown(): void
	{
		$registry = new FormActionRegistry();

		$this->assertNull($registry->get('nonexistent'));
	}

	public function testAllReturnsAllRegistered(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover'));
		$registry->register(new FormAction('slack', '/api/ext/acme/slack/send', 'Slack'));

		$all = $registry->all();

		$this->assertCount(2, $all);
		$this->assertArrayHasKey('pushover', $all);
		$this->assertArrayHasKey('slack', $all);
	}

	public function testToJsonMapReturnsNameToRouteMapping(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/api/ext/totalcms/pushover/send', 'Pushover'));
		$registry->register(new FormAction('slack', '/api/ext/acme/slack/send', 'Slack'));

		$json = $registry->toJsonMap();

		$this->assertSame(
			'{"pushover":"/api/ext/totalcms/pushover/send","slack":"/api/ext/acme/slack/send"}',
			$json,
		);
	}

	public function testToJsonMapReturnsEmptyObjectWhenEmpty(): void
	{
		$registry = new FormActionRegistry();

		$this->assertSame('{}', $registry->toJsonMap());
	}

	public function testLaterRegistrationOverridesEarlier(): void
	{
		$registry = new FormActionRegistry();
		$registry->register(new FormAction('pushover', '/old/route', 'Old'));
		$registry->register(new FormAction('pushover', '/new/route', 'New'));

		$this->assertSame('/new/route', $registry->get('pushover')?->route);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- --filter='FormActionRegistryTest'`
Expected: FAIL — classes don't exist yet

- [ ] **Step 3: Create FormAction data class**

Create `src/Domain/Extension/Data/FormAction.php`:

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

final readonly class FormAction
{
	public function __construct(
		public string $name,
		public string $route,
		public string $label,
	) {
	}
}
```

- [ ] **Step 4: Create FormActionRegistry**

Create `src/Domain/Extension/Service/FormActionRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\FormAction;

class FormActionRegistry
{
	/** @var array<string, FormAction> */
	private array $actions = [];

	public function register(FormAction $action): void
	{
		$this->actions[$action->name] = $action;
	}

	public function get(string $name): ?FormAction
	{
		return $this->actions[$name] ?? null;
	}

	/** @return array<string, FormAction> */
	public function all(): array
	{
		return $this->actions;
	}

	public function toJsonMap(): string
	{
		$map = [];
		foreach ($this->actions as $name => $action) {
			$map[$name] = $action->route;
		}

		return json_encode((object)$map, JSON_THROW_ON_ERROR);
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer run test -- --filter='FormActionRegistryTest'`
Expected: All 6 tests PASS

- [ ] **Step 6: Run PHPStan**

Run: `composer run stan`
Expected: No errors

---

### Task 2: Wire addFormAction() into ExtensionContext

**Files:**
- Modify: `src/Domain/Extension/ExtensionContext.php`

- [ ] **Step 1: Add formActions property**

In `ExtensionContext.php`, after the `pageMiddleware` property (line 77), add:

```php
	/** @var array<string, \TotalCMS\Domain\Extension\Data\FormAction> */
	private array $formActions = [];
```

- [ ] **Step 2: Add addFormAction() method**

After the `addPageMiddleware()` method (line 571), add:

```php
	/**
	 * Register a form action type that the JS form processor can dispatch
	 * to an extension-owned API route. The route must be registered
	 * separately via addRoutes().
	 */
	public function addFormAction(string $name, \TotalCMS\Domain\Extension\Data\FormAction $action): void
	{
		$this->formActions[$name] = $action;
	}
```

- [ ] **Step 3: Add getter for ExtensionManager**

After the `getRegisteredPageMiddleware()` method (around line 689), add:

```php
	/** @return array<string, \TotalCMS\Domain\Extension\Data\FormAction> */
	public function getRegisteredFormActions(): array
	{
		return $this->formActions;
	}
```

- [ ] **Step 4: Add capability label**

In `capabilityLabels()` (around line 722), add after `'page-middleware'`:

```php
			'form-actions'    => 'Form Actions',
```

- [ ] **Step 5: Add capability detection**

In `getCapabilities()`, after the `page-middleware` check (around line 781), add:

```php
		if ($this->formActions !== []) {
			$caps['form-actions'] = true;
		}
```

- [ ] **Step 6: Run PHPStan**

Run: `composer run stan`
Expected: No errors

---

### Task 3: Wire FormActionRegistry into ExtensionManager + DI container

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php`
- Modify: `config/container.php` (or wherever FormActionRegistry is registered as a singleton)

- [ ] **Step 1: Find where ExtensionManager collects registrations**

In `ExtensionManager.php`, find the method that collects form actions from contexts and registers them in the `FormActionRegistry`. Look for where `getRegisteredPageMiddleware()` is called — the form actions follow the same pattern.

- [ ] **Step 2: Register FormActionRegistry as a container singleton**

Search for where `PageMiddlewareRegistry` is registered in the DI container and add `FormActionRegistry` alongside it:

```php
FormActionRegistry::class => fn () => new FormActionRegistry(),
```

- [ ] **Step 3: Collect form actions from extension contexts**

In `ExtensionManager`, find where page middleware registrations are collected from the context (look for `getRegisteredPageMiddleware()`). Add an equivalent block for form actions:

```php
foreach ($context->getRegisteredFormActions() as $name => $formAction) {
	$this->formActionRegistry->register($formAction);
}
```

The `FormActionRegistry` must be injected into `ExtensionManager`'s constructor.

- [ ] **Step 4: Run PHPStan**

Run: `composer run stan`
Expected: No errors

---

### Task 4: Wire TotalForm to emit data-extension-actions

**Files:**
- Modify: `src/Domain/Admin/TotalForm.php`
- Modify: `src/Domain/Admin/TotalFormFactory.php`

- [ ] **Step 1: Add FormActionRegistry to TotalForm constructor**

In `src/Domain/Admin/TotalForm.php`, add a new parameter at the end of the constructor (around line 334):

```php
		protected ?FormActionRegistry $formActionRegistry = null,
```

Add the import at the top:

```php
use TotalCMS\Domain\Extension\Service\FormActionRegistry;
```

- [ ] **Step 2: Emit data-extension-actions on the form element**

In `TotalForm.php`, after the action attributes loop (around line 479), add:

```php
		if ($this->formActionRegistry !== null) {
			$jsonMap = $this->formActionRegistry->toJsonMap();
			if ($jsonMap !== '{}') {
				$attributes['data-extension-actions'] = $jsonMap;
			}
		}
```

- [ ] **Step 3: Remove 'pushover' from filterActionsByEdition()**

In `filterActionsByEdition()` (around line 398), change the match:

```php
			return match ($actionType) {
				'mailer'   => $this->editionFeatures->can(EditionFeature::MAILER_ACTIONS),
				'webhook'  => $this->editionFeatures->can(EditionFeature::WEBHOOK_ACTIONS),
				default    => true,
			};
```

Remove the `'pushover'` case and the `EditionFeature::PUSHOVER_ACTIONS` import if no longer used.

- [ ] **Step 4: Pass FormActionRegistry through TotalFormFactory**

In `src/Domain/Admin/TotalFormFactory.php`, add `FormActionRegistry` to the constructor (around line 87):

```php
		private FormActionRegistry $formActionRegistry,
```

Add the import:

```php
use TotalCMS\Domain\Extension\Service\FormActionRegistry;
```

In the `totalform()` method (around line 137), add to the options array:

```php
				'formActionRegistry'       => $this->formActionRegistry,
```

- [ ] **Step 5: Run PHPStan**

Run: `composer run stan`
Expected: No errors

---

### Task 5: Update JavaScript form processor

**Files:**
- Modify: `javascript/totalform/totalform.js`

- [ ] **Step 1: Parse extension actions on form init**

In `totalform.js`, after the `deleteActions` parsing (around line 81), add:

```javascript
		this.extensionActions = {};
		if (this.form.dataset.extensionActions) {
			this.extensionActions = JSON.parse(this.form.dataset.extensionActions);
		}
```

- [ ] **Step 2: Remove the pushover case and update default**

In `runAction()` (around line 709), remove the entire `case "pushover":` block (lines 740-752). Then replace the existing `default:` block:

```javascript
			default:
				if (this.extensionActions[action.action]) {
					const { action: _type, ...config } = action;
					await this.api.postAPI(this.extensionActions[action.action], {
						data: this.generateData(),
						...config,
					});
				} else {
					console.warn(`Unknown action type: ${action.action}`);
				}
```

- [ ] **Step 3: Verify no other JS files reference the pushover case**

Run: `grep -rn 'pushover' javascript/`
Expected: No matches (the only reference was in totalform.js)

---

### Task 6: Create the Pushover bundled extension

**Files:**
- Create: `resources/extensions/totalcms/pushover/Extension.php`
- Create: `resources/extensions/totalcms/pushover/PushoverService.php`
- Create: `resources/extensions/totalcms/pushover/SendPushoverAction.php`
- Create: `resources/extensions/totalcms/pushover/PushoverTestAction.php`
- Create: `resources/extensions/totalcms/pushover/extension.json`
- Create: `resources/extensions/totalcms/pushover/settings.json`
- Copy: `resources/extensions/totalcms/pushover/icon.png` (from ab-split)

- [ ] **Step 1: Create extension.json**

```json
{
	"id"              : "totalcms/pushover",
	"name"            : "Pushover Notifications",
	"description"     : "Send push notifications via Pushover when forms are submitted. Supports Twig templates in messages, image attachments, priority levels, and delivery groups.",
	"icon"            : "icon.png",
	"requires"        : {
		"totalcms" : ">=3.5.0-beta",
		"php"      : ">=8.2"
	},
	"entrypoint"       : "Extension.php",
	"settings_schema"  : "settings.json",
	"author"     : {
		"name" : "Total CMS",
		"url"  : "https://totalcms.co"
	},
	"license" : "proprietary",
	"links"   : [
		{
			"label" : "Documentation",
			"url"   : "docs/extensions/bundled/pushover"
		}
	]
}
```

- [ ] **Step 2: Create settings.json**

```json
{
	"$schema": "https://json-schema.org/draft/2020-12/schema",
	"title": "Pushover Notifications",
	"description": "Configure your Pushover account credentials. Find these at pushover.net.",
	"type": "object",
	"properties": {
		"appToken": {
			"type": "string",
			"field": "secret",
			"label": "Application Token",
			"help": "Your Pushover Application Token — create one at <a href='https://pushover.net/apps/build' target='_blank'>pushover.net/apps/build</a>.",
			"default": ""
		},
		"userKey": {
			"type": "string",
			"field": "secret",
			"label": "User Key",
			"help": "Your Pushover User Key — shown on your Pushover dashboard.",
			"default": ""
		},
		"groupKey": {
			"type": "string",
			"field": "secret",
			"label": "Group Key (optional)",
			"help": "For sending to a delivery group instead of a single user. Leave empty if not using groups.",
			"default": ""
		}
	}
}
```

- [ ] **Step 3: Create PushoverService.php**

Move from `src/Domain/Notification/Service/PushoverService.php`. Changes from the original:
- Namespace: `TotalCMS\Bundled\Pushover`
- Constructor takes settings values directly (`string $appToken, string $userKey, string $groupKey`) instead of `Config`
- Remove `Config` import and usage — replace `$this->config->pushnotif['pushoverAppToken']` etc. with `$this->appToken` etc.
- Keep `EditionFeatureService`, `TwigEngine`, `ImageGenerator`, `LoggerFactory` deps
- All other logic identical

- [ ] **Step 4: Create SendPushoverAction.php**

Move from `src/Action/Notification/SendPushoverAction.php`. Changes:
- Namespace: `TotalCMS\Bundled\Pushover`
- Otherwise identical — it already only depends on `PushoverService`, `JsonRenderer`, `AccessManager`

- [ ] **Step 5: Create PushoverTestAction.php**

New action for the test notification UI on the extension settings page:

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Pushover;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;

readonly class PushoverTestAction
{
	public function __construct(
		private PushoverService $pushoverService,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();
		$message = trim((string)($data['message'] ?? ''));

		if ($message === '') {
			$message = 'This is a test notification from Total CMS.';
		}

		$result = $this->pushoverService->send(
			message: $message,
			title: 'Total CMS Test',
		);

		$status = $result->success ? 200 : 500;

		return $this->renderer->json($response->withStatus($status), $result->toArray());
	}
}
```

- [ ] **Step 6: Create Extension.php**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Pushover;

use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;

require_once __DIR__ . '/PushoverService.php';
require_once __DIR__ . '/SendPushoverAction.php';
require_once __DIR__ . '/PushoverTestAction.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$appToken = (string)$context->setting('appToken', '');
		$userKey  = (string)$context->setting('userKey', '');
		$groupKey = (string)$context->setting('groupKey', '');

		$context->addContainerDefinition(
			PushoverService::class,
			fn ($c) => new PushoverService(
				$c->get(TwigEngine::class),
				$appToken,
				$userKey,
				$groupKey,
				$c->get(EditionFeatureService::class),
				$c->get(ImageGenerator::class),
				$c->get(LoggerFactory::class),
			),
		);

		$context->addContainerDefinition(
			SendPushoverAction::class,
			fn ($c) => new SendPushoverAction(
				$c->get(PushoverService::class),
				$c->get(JsonRenderer::class),
				$c->get(AccessManager::class),
			),
		);

		$context->addContainerDefinition(
			PushoverTestAction::class,
			fn ($c) => new PushoverTestAction(
				$c->get(PushoverService::class),
				$c->get(JsonRenderer::class),
			),
		);

		$context->addFormAction('pushover', new FormAction(
			name: 'pushover',
			route: '/ext/totalcms/pushover/send',
			label: 'Pushover Notification',
		));

		$context->addRoutes(function ($group): void {
			$group->post('/send', SendPushoverAction::class);
		});

		$context->addAdminRoutes(function ($group): void {
			$group->post('/test', PushoverTestAction::class);
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
```

- [ ] **Step 7: Copy icon.png**

```bash
cp resources/extensions/totalcms/ab-split/icon.png resources/extensions/totalcms/pushover/icon.png
```

---

### Task 7: Remove Pushover from core

**Files:**
- Delete: `src/Action/Notification/SendPushoverAction.php`
- Delete: `src/Domain/Notification/Service/PushoverService.php`
- Delete: `src/Middleware/License/PushoverEditionMiddleware.php`
- Delete: `resources/schemas/settings/pushnotif.json`
- Modify: `config/routes/api/action.php`
- Modify: `config/defaults.php`
- Modify: `src/Domain/License/Data/EditionFeature.php`
- Modify: `src/Support/Config.php`
- Modify: `src/Action/Admin/AdminSettingsSaveSectionAction.php`
- Modify: `src/Domain/Settings/Services/SettingsValidator.php`
- Modify: `resources/templates/admin/settings.twig`
- Modify: `resources/templates/admin/quick-nav-index.twig`
- Modify: `resources/translations/admin.en_US.php` (and de_DE, en_GB, es_ES, it_IT, nl_NL)

- [ ] **Step 1: Delete the three core Pushover files**

```bash
rm src/Action/Notification/SendPushoverAction.php
rm src/Domain/Notification/Service/PushoverService.php
rm src/Middleware/License/PushoverEditionMiddleware.php
rm resources/schemas/settings/pushnotif.json
```

- [ ] **Step 2: Remove Pushover route from action.php**

In `config/routes/api/action.php`, remove line 22:
```php
		$group->post('/pushover', SendPushoverAction::class)->setName('action-send-pushover')->add(PushoverEditionMiddleware::class);
```
Also remove the `use` imports for `SendPushoverAction` and `PushoverEditionMiddleware`.

- [ ] **Step 3: Remove pushnotif defaults from defaults.php**

In `config/defaults.php`, remove lines 242-246:
```php
$settings['pushnotif'] = [
	'pushoverAppToken'  => '',
	'pushoverUserKey'   => '',
	'pushoverGroupKey'  => '',
];
```

- [ ] **Step 4: Remove PUSHOVER_ACTIONS from EditionFeature enum**

In `src/Domain/License/Data/EditionFeature.php`:
- Remove `case PUSHOVER_ACTIONS = 'pushover_actions';` (line 24)
- Remove `self::PUSHOVER_ACTIONS     => 'Pushover Form Actions',` from `label()` (line 70)
- Remove `self::PUSHOVER_ACTIONS,` from `requiredEdition()` Pro section (line 114)

- [ ] **Step 5: Remove pushnotif from Config class**

In `src/Support/Config.php`:
- Remove `public array $pushnotif = [];` (line 55)
- Remove `$this->pushnotif = is_array(...)` line (line 110)

- [ ] **Step 6: Remove Pushover from AdminSettingsSaveSectionAction**

In `src/Action/Admin/AdminSettingsSaveSectionAction.php`:
- Remove `use TotalCMS\Domain\Notification\Service\PushoverService;` import
- Remove `private PushoverService $pushoverService,` from constructor
- Remove the `pushnotif` test check (lines 64-66)
- Remove the entire `handlePushoverTest()` method (lines 132-157)

- [ ] **Step 7: Remove pushnotif from SettingsValidator fallback**

In `src/Domain/Settings/Services/SettingsValidator.php`, remove `'pushnotif',` from the fallback array in `getValidSections()` (line 45).

- [ ] **Step 8: Remove pushnotif from settings template**

In `resources/templates/admin/settings.twig`:
- Remove the `pushnotif` sidebar entry (lines 44-47)
- Remove the entire `{% if currentSection == 'pushnotif' %}` block (lines 163-190)

- [ ] **Step 9: Remove Pushover from quick-nav-index**

In `resources/templates/admin/quick-nav-index.twig`:
- Remove the `pushnotif` settings entry (line 133)
- Remove the `Pushover Notifications` docs entry (line 152)

- [ ] **Step 10: Remove pushnotif translation keys from all locales**

Remove the following keys from all 6 locale files (`admin.en_US.php`, `admin.de_DE.php`, `admin.en_GB.php`, `admin.es_ES.php`, `admin.it_IT.php`, `admin.nl_NL.php`):
- `settings.pushnotif`
- `settings.pushnotif_desc`
- `settings.pushnotif_test_title`
- `settings.pushnotif_test_desc`
- `settings.pushnotif_test_message`
- `settings.pushnotif_test_ph`
- `settings.pushnotif_test_help`
- `settings.pushnotif_test_btn`

- [ ] **Step 11: Run PHPStan to verify clean removal**

Run: `composer run stan`
Expected: No errors. If there are errors, they indicate a missed reference — fix before continuing.

---

### Task 8: Move tests and update for extension settings

**Files:**
- Move: `tests/Unit/Domain/Notification/Service/PushoverServiceTest.php` → `tests/Unit/Bundled/Pushover/PushoverServiceTest.php`
- Move: `tests/Integration/PushoverIntegrationTest.php` → `tests/Unit/Bundled/Pushover/PushoverIntegrationTest.php`

- [ ] **Step 1: Create test directory and move files**

```bash
mkdir -p tests/Unit/Bundled/Pushover
mv tests/Unit/Domain/Notification/Service/PushoverServiceTest.php tests/Unit/Bundled/Pushover/PushoverServiceTest.php
mv tests/Integration/PushoverIntegrationTest.php tests/Unit/Bundled/Pushover/PushoverIntegrationTest.php
```

- [ ] **Step 2: Update PushoverServiceTest**

Update the test file:
- Change namespace to `Tests\Unit\Bundled\Pushover`
- Add `require_once` for the extension file (matching other bundled test patterns)
- Update imports to `TotalCMS\Bundled\Pushover\PushoverService`
- Remove `Config` mock — instead pass `$appToken`, `$userKey`, `$groupKey` strings directly to the constructor
- Replace all `$this->config->pushnotif = [...]` with direct string arguments in the `PushoverService` constructor
- Replace `EditionFeature::PUSHOVER_ACTIONS` with the actual edition check the service uses internally
- Update `buildServiceWith()` helper accordingly

- [ ] **Step 3: Update PushoverIntegrationTest similarly**

Apply the same namespace/import/constructor changes.

- [ ] **Step 4: Run the moved tests**

Run: `composer run test -- --filter='PushoverServiceTest'`
Expected: All tests PASS

- [ ] **Step 5: Run full test suite check**

Run: `composer run test -- --filter='Bundled'`
Expected: All bundled extension tests PASS (protect, scheduled, maintenance, pushover, ab-split, geo-redirect)

---

### Task 9: Move docs and update menus

**Files:**
- Move: `resources/docs/notifications/pushover.md` → `resources/docs/extensions/pushover.md`
- Modify: `resources/docs/menu.php`
- Modify: `resources/docs/extensions/bundled.md`

- [ ] **Step 1: Move the docs file**

```bash
mv resources/docs/notifications/pushover.md resources/docs/extensions/pushover.md
```

- [ ] **Step 2: Update the doc frontmatter**

Update the title and description in `resources/docs/extensions/pushover.md` to match the bundled extension doc pattern:

```markdown
---
title: "Pushover Notifications (Bundled Extension)"
description: "Send push notifications via Pushover when forms are submitted. Supports Twig templates, image attachments, priority levels, and delivery groups."
since: "3.5.0"
---
```

Add a "See also" section at the bottom linking to bundled extensions overview and extension points.

- [ ] **Step 3: Update docs menu**

In `resources/docs/menu.php`, remove Pushover from the Notifications section (line 178). Add it to the Bundled Extensions subgroup (after Geo Redirect):

```php
					['title' => 'Pushover',         'path' => 'extensions/pushover'],
```

If the Notifications section becomes empty or only has Mailer, that's fine — leave it with just Mailer.

- [ ] **Step 4: Update bundled.md extensions table**

In `resources/docs/extensions/bundled.md`, add Pushover to the table:

```markdown
| `totalcms/pushover` | Send push notifications via Pushover when forms are submitted. Supports Twig templates, image attachments, and delivery groups. | [Pushover →](docs/extensions/pushover) |
```

- [ ] **Step 5: Rebuild search index**

Run: `php bin/build-docs-index.php`

---

### Task 10: Final verification

- [ ] **Step 1: Run PHPStan**

Run: `composer run stan`
Expected: No errors

- [ ] **Step 2: Run all tests**

Run: `composer run test -- --filter='FormActionRegistry|PushoverServiceTest'`
Expected: All pass

- [ ] **Step 3: Verify no stale Pushover references in core**

Run: `grep -rn 'PushoverService\|PushoverEditionMiddleware\|PUSHOVER_ACTIONS\|pushnotif' src/ config/ resources/schemas/ resources/templates/ resources/translations/`
Expected: No matches. All references should be in `resources/extensions/totalcms/pushover/` and `resources/docs/extensions/pushover.md` only.

- [ ] **Step 4: Verify extension actions JSON is emitted**

Grep for `data-extension-actions` in `TotalForm.php` to confirm it's wired. Check that `FormActionRegistry` is in the DI container.

- [ ] **Step 5: Commit**

Stage all changes and commit with a message describing the form action extension point and Pushover extraction.
