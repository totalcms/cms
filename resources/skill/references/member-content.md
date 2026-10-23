# Recipe: content your members submit and edit

A directory of members' work, a job board, a recipe exchange, a portfolio
gallery: signed-in members add their own objects, edit only their own, and
nothing goes live until an admin approves it. Total CMS does this with
collection and schema settings. You only write templates.

The pieces, in the order to build them. Doc paths are under
`vendor/totalcms/cms/resources/docs/`; MCP `docs_get` takes the same path.

## 1. Members: an auth collection

A collection on the `auth` schema (or one that inherits it) holds the
members. Turn on **Allow Public Registration** and **Require Email
Verification** in its Public Registration section. **Registration Groups**
give new members the access group that grants `create` and `update` on the
member collections. Write that group with `vendor/bin/tcms access-group:set <id> -`
(or `access-group:patch`), never by editing `.system/access-groups.json`.
Docs: `forms/options.md`, `auth/access-groups.md`.

## 2. Ownership: `ownerProperty`

On each member collection, add an `owner` text property to the schema **and
to its `index`**, then make it the collection's Owner Property:
`vendor/bin/tcms collection:update <collection> --set ownerProperty=owner`.
Set `ownerCollection` to the members collection the same way when the site
has more than one auth collection. Docs: `collections/settings.md#ownerproperty`.

What core then does for every user who is not a super admin:

- stamps their id into `owner` on create, and reverts any change to it on update;
- denies another member's object, and scopes list and query routes to their own;
- refuses routes that cannot be scoped: bulk delete, clone, import, export;
- applies all of this over REST, the admin and MCP alike, through one check.

Do not re-implement any of this in templates. A template check like
`item.owner == me.id` is for what to *show*. It is not the security.

**Leave public operations at `create` at most.** Public requests skip access
control entirely, ownership included: a public `read`, `update` or `delete`
on a member collection lets anyone read, change or delete any member's
object. Members are signed in, so they need no public operations at all;
public `create` is only for a form anonymous visitors submit.

## 3. Review: protect the flags

Make the moderation flags protected in the schema (`schemas/validation.md`):

| `draft` setting | What a member's save does |
|---|---|
| `"protected": true`, `"default": true` | A new item starts as a draft. An edit keeps the stored value, so a live item stays live and an item the admin set back to draft stays a draft. Review newcomers, not edits. |
| `"protected": {"value": true}` | Every member save goes back to draft. Review every change. |
| `"protected": {"value": false}` | Nothing is reviewed. |

Also protect what only the admin decides: `featured`, `approved`, dates, and
any counts an automation keeps. A protected field in a member's form is not
an error: the rest of the save goes through and the protected value just
does not change.

## 4. The account page: forms with `cms.form.builder`

One page where a member manages their work. List their objects with
`cms.collection.objects(...)|filter(o => o.owner == me.id)` and open a form
to add or edit:

- **Add:** `addOnly: true`, so the form can only create. Alert the admin with
  a mailer in `newActions` (`{action: 'mailer', mailerId: '…', continue: true}`).
  `continue: true` keeps a failed email from turning a good save into an error.
- **Edit:** `method: 'PATCH'` with the object's `id`. Leave out the fields the
  member should not see (draft, owner, admin flags). Protection makes leaving
  them out a matter of tidiness, not safety.
- **Layout:** pass `formgrid` to show a subset of the schema's fields in your
  own arrangement without changing the admin form.

Gotchas that each cost a round trip:

- **A form inside a hidden element stays hidden.** A `<dialog>` placed in a
  tab panel that is `hidden` never shows, even when opened. Put shared
  dialogs at page level.
- **`settings` merge shallowly.** `addField(name, {settings: {...}})` replaces
  only the keys you pass and keeps the schema's other settings. To switch a
  schema setting off for this form, pass it as `null` (for example
  `settings: {relationalOptions: null}` when the form passes its own `options`).
- **Clear the Twig cache** (`vendor/bin/tcms cache:clear`) when a moved
  partial or include does not seem to take effect.

## 5. Drafts: preview for the owner, 404 for everyone else

A collection URL matches every object, drafts included. On the detail page,
let the owner and admins preview a draft (with a banner and
`noindex, nofollow`), and answer everyone else with `cms.notFound()`. That
renders the real 404 at the visitor's URL, so a guessed draft slug looks
exactly like a slug that never existed. Docs: `twig/totalcms.md`.

## 6. Mail and counts: automations

- **The admin's alert** for a new item comes from the add form's `newActions`.
  An alert that must fire on any write (an edit, or a write from the API)
  belongs in an automation on the object event instead.
- **Counts and earned states** a template reads (approved items per member,
  a verified flag) belong in an automation that recounts on object events and
  writes protected fields on the member. Automations run as the system, so
  protection does not stop them.
- An automation that fetches a member-supplied URL (checking a link-back,
  say) uses `$ctx->publicHttp`, never a raw request. Docs:
  `automations/handlers.md#fetching-a-url-from-content`.

## 7. Moderation queue

A Data View of drafts, newest first, gives the admin one list to work
through, and over MCP an agent can read it too. Keep it admin-only
(`mcp.access: admin`, the default): a view is built with full access, so
ownership does not narrow it, and any member it admits sees every draft.
Over MCP an OAuth member reaches a non-public view only with the `dataviews`
permission, so do not put that grant in the members' access group.
Docs: `collections/data-views.md`.

## 8. Members' own AI clients (optional)

With OAuth on, a member can connect Claude or another client and manage
their work over MCP under the same rules as the account page: they read
their own objects, drafts included, plus published ones, and write only
their own, with protected fields reverted or forced.

- Set `oauth.loginCollection` (Settings → OAuth Server) to the members
  collection, so the consent screen signs members in on their own login page.
- Set the member collections' `mcp.access` to `authenticated`
  (`collection:update <collection> --set mcp.access=authenticated`). Their
  access group's grants decide what they can do there.
- `describe_collection` reports the scope and the fields the site sets in
  `ownership`, so a member's agent knows before it writes.

Docs: `apis/oauth.md#login-collection`, `mcp/server.md`.
