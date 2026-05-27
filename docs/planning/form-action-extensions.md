# Form Action Extensions + Pushover Extraction

Extensible form actions for Total CMS, starting with extracting Pushover into a bundled extension. Enables future notification providers (Slack, Discord, Ntfy) without core changes.

## Context

Form actions run after a form save in the admin UI. Today they're hardcoded: `mailer`, `webhook`, `pushover`, `redirect`, `refresh`, `back`, `redirect-object`, `ajax`. The JS form processor (`totalform.js`) has a `switch` with a `case` per type. Adding a new notification provider means touching core JS, core routes, core config, and core edition gating.

Pushover is a Pro-edition notification service currently wired through core. It should be an extension — it's a third-party integration, not a CMS primitive. Extracting it requires making the form action system pluggable.

## Design

### New Extension Point: `addFormAction()`

Extensions register form actions during `register()`:

```php
$context->addFormAction('pushover', new FormAction(
    route: '/ext/totalcms/pushover/send',
    label: 'Pushover Notification',
));
```

**`FormAction` data class** (`src/Domain/Extension/Data/FormAction.php`):

```php
final readonly class FormAction
{
    public function __construct(
        public string $name,
        public string $route,
        public string $label,
    ) {}
}
```

**`FormActionRegistry`** (`src/Domain/Extension/Service/FormActionRegistry.php`): Simple bag of `FormAction` entries keyed by action name. Methods: `register(FormAction)`, `get(string): ?FormAction`, `all(): array`, `toJsonMap(): string` (returns `{"pushover": "/ext/totalcms/pushover/send"}` for the JS side).

**`ExtensionContext::addFormAction()`**: Delegates to `FormActionRegistry`. Detected as capability `form-actions` for the permissions system.

### JavaScript: Generic Extension Action Dispatch

Remove `case "pushover"` from `totalform.js`. During form init, parse a `data-extension-actions` attribute (JSON map of action name → route). In the `default` branch of `runAction()`:

```javascript
default:
    if (this.extensionActions && this.extensionActions[action.action]) {
        const { action: _type, ...config } = action;
        await this.api.postAPI(this.extensionActions[action.action], {
            data: this.generateData(),
            ...config,
        });
    } else {
        console.warn(`Unknown action type: ${action.action}`);
    }
```

**`TotalForm` wiring**: Inject `FormActionRegistry` into `TotalForm`. Serialize the registry's route map into `data-extension-actions` on the `<form>` element alongside the existing `data-new-actions` etc. Extension actions bypass `filterActionsByEdition()` — the extension's own route middleware handles gating.

### Pushover Extension

Bundled at `resources/extensions/totalcms/pushover/`. Not hidden — operators need the settings page for API keys.

**Files:**

| File | Purpose |
|------|---------|
| `Extension.php` | Reads settings, registers form action + routes + container defs |
| `PushoverService.php` | Existing service, reads config from extension settings |
| `SendPushoverAction.php` | Existing action handler for form dispatch |
| `PushoverTestAction.php` | Test notification handler for the settings page |
| `settings.json` | Schema: appToken, userKey, groupKey (all `secret` type) |
| `extension.json` | Manifest with `settings_schema` |
| `icon.png` | T3 flame icon |

**Extension `register()`:**

- Reads `appToken`, `userKey`, `groupKey` from extension settings
- Registers `PushoverService` and `SendPushoverAction` as container definitions
- Calls `$context->addFormAction('pushover', ...)` with route `/ext/totalcms/pushover/send`
- Registers API route via `$context->addRoutes()` for the send endpoint
- Registers admin route via `$context->addAdminRoutes()` for the test notification page

**Settings page**: The standard extension settings form renders the three API key fields. The test notification UI (message input + send button) renders via an admin route registered by the extension, linked from the extension's settings page.

**Edition gating**: The extension checks edition internally (via `EditionFeatureService` injected into the service) rather than via route middleware, matching the Algolia pattern.

### Core Cleanup

**Remove entirely:**

- `src/Action/Notification/SendPushoverAction.php`
- `src/Domain/Notification/Service/PushoverService.php`
- `src/Middleware/License/PushoverEditionMiddleware.php`
- `resources/schemas/settings/pushnotif.json`
- Pushover route from `config/routes/api/action.php`
- `pushnotif` defaults from `config/defaults.php`
- `pushnotif` section from `resources/templates/admin/settings.twig`
- `handlePushoverTest()` from `AdminSettingsSaveSectionAction.php`
- `PUSHOVER_ACTIONS` from `EditionFeature` enum and its `requiredEdition()` match
- `pushnotif_*` translation keys from all locale files (en_US, de_DE, en_GB, es_ES, nl_NL)
- `resources/docs/notifications/pushover.md` (replaced by extension doc)

**Modify:**

- `TotalForm::filterActionsByEdition()` — remove `'pushover'` case
- `TotalForm` constructor / form rendering — inject `FormActionRegistry`, emit `data-extension-actions`
- `totalform.js` — remove `case "pushover"`, add extension actions map parsing in init, generic dispatch in `default`
- `resources/docs/menu.php` — move Pushover from Notifications section to Bundled Extensions
- `resources/docs/extensions/bundled.md` — add Pushover to the table

**New core files:**

- `src/Domain/Extension/Data/FormAction.php`
- `src/Domain/Extension/Service/FormActionRegistry.php`
- `ExtensionContext::addFormAction()` method addition

**Move:**

- Tests → `tests/Unit/Bundled/Pushover/`
- Docs → `resources/docs/extensions/pushover.md`

### What stays in core

- `FormActionRegistry` + `FormAction` (new extension infrastructure)
- `addFormAction()` on `ExtensionContext`
- Generic JS dispatch in `totalform.js`
- Mailer, webhook, redirect, refresh, back, redirect-object, ajax — all remain hardcoded core actions
- `EditionFeature::MAILER_ACTIONS`, `WEBHOOK_ACTIONS` — these stay since mailer/webhook stay in core

### Testing

- Unit tests for `FormActionRegistry` (register, get, toJsonMap)
- Move existing `PushoverServiceTest` and `PushoverIntegrationTest` to `tests/Unit/Bundled/Pushover/`
- Update tests to use extension settings instead of core config
- Verify `filterActionsByEdition()` no longer references pushover
- Verify extension actions serialize correctly to `data-extension-actions`

### Future

When a second notification provider arrives (Slack, Ntfy, etc.), it registers its own `addFormAction()` with its own route. Zero core changes. The form action config in collection `.meta.json` just uses the new action name — the JS generic dispatch handles it automatically.
