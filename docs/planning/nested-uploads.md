# Nested Uploads — Image & File Fields Inside Card and Deck

## Goal

Support `image` and `file` field types as children of `card` and `deck` fields. Make the corresponding read-side APIs (imageworks, download, stream) and Twig URL builders work with the resulting nested file paths. Fix the existing styledtext-in-deck upload bug as a prerequisite.

## Non-goals

- `gallery` and `depot` as deck/card children — explicitly out of scope. UX doesn't justify the complexity.
- Browser-side cleanup. Duplicate and delete buttons in deck.js stay DOM-only; reconciliation happens at form save.
- Carrying file references through deck-item duplicate for `image` / `file` fields — the duplicated item starts with empty values for those fields. (Styledtext is the documented exception, see below.)
- Solving the pre-existing "abandoned form orphans uploads" problem. New nested uploads inherit the same behavior; not new, not in scope.

## Path strategy

`PathUtils::buildPath(collection, objectID, property, filename, subpath)` already supports a `$subpath` segment between property and filename. Reuse it.

Disk layout:

| Field location | Disk path |
|---|---|
| Top-level `image` field `pic` | `coll/id/pic/file.jpg` (unchanged) |
| `image` inside card `mycard` | `coll/id/mycard/pic/file.jpg` |
| `image` inside deck `mydeck`, item `item-3` | `coll/id/mydeck/item-3/pic/file.jpg` |

In `PathUtils` terms: `property` = top-level field name on the object; `subpath` = everything between, dot-separated path components joined with `/`.

## Wire format

Use route segments for subpath. The existing gallery and depot URL shapes are subsumed by a single greedy-segment route per API — no `?path=` query parameter, no special cases.

### Upload route

```
POST /upload/{collection}/{id}/{rootProp}                  (top-level, unchanged)
POST /upload/{collection}/{id}/{rootProp}/{path:.+}        (gallery, depot folder, card child, deck item child)
```

### Read-side routes

```
GET /api/imageworks/{coll}/{id}/{rootProp}.{format}                 (top-level, unchanged)
GET /api/imageworks/{coll}/{id}/{rootProp}/{path:.+}.{format}       (everything nested)

GET /api/download/{coll}/{id}/{rootProp}                            (top-level, unchanged)
GET /api/download/{coll}/{id}/{rootProp}/{path:.+}                  (everything nested)

GET /api/stream/{coll}/{id}/{rootProp}                              (top-level, unchanged)
GET /api/stream/{coll}/{id}/{rootProp}/{path:.+}                    (everything nested)
```

Example URLs:

| Case | URL |
|---|---|
| Gallery image (was `/{prop}/{name}.jpg`) | `/api/imageworks/blog/post-1/myGallery/photo.jpg` |
| Depot file (was `?path=folder/sub`) | `/api/download/blog/post-1/myDepot/folder/sub/file.pdf` |
| Card child image | `/api/imageworks/blog/post-1/myCard/profilePic.jpg` |
| Deck item image | `/api/imageworks/blog/post-1/myDeck/item-3/pic.jpg` |

### Action-side dispatch

The action handler branches on the shape of the value at `obj[rootProp]` to resolve the file:

- **Array** → gallery semantics. Path's first segment is the image filename; look up by name in the array.
- **Depot data shape** → folder navigation. Path is a folder path within the depot, last segment is the filename.
- **Object containing a nested field** → card or deck. Descend the path through the JSON to find the file metadata.

This dispatch logic lives in `PropertyFetcher` (the keystone — see Phase 4.1).

### Backwards compatibility

This is a **minor breaking change**. The old gallery `/{prop}/{name}.{format}` URL shape is unchanged on the wire (single-segment `{path:.+}` matches it identically), so existing gallery URLs continue working. The old depot `?path=folder/sub` URL shape is what changes — depot URLs become segment-based instead of query-based.

- All Twig URL builders (`cms.media.galleryPath()`, `cms.media.depotDownload()`, `cms.media.depotStream()`) are updated to emit the new shape, so any template using them re-renders correctly on next request.
- Hardcoded depot URLs in customer templates or external systems will break. Mitigation: call this out in the release notes for the version that ships this work.
- No automatic redirect from old `?path=` URLs to new — keeping it simple.

## Implementation phases

### Phase 1 — Plumbing

**1.1 Upload action accepts subpath via route**

- Add new route: `POST /upload/{collection}/{id}/{rootProp}/{path:.+}` alongside existing top-level route.
- `src/Action/Upload/UploadFileAction.php`: read `path` route arg when present, sanitize (no `..`, normalize slashes — `PathUtils` already does this), pass through to `UploadSaver`.
- `UploadSaver` / `PropertyRepository::saveFile()` already accept `$subpath` — wire it.
- Response payload (currently includes `link`) must reflect the nested path so Tiptap and other clients can embed the correct URL.

**1.2 JS field-context API**

Every JS field exposes `getUploadContext()` returning `{ collection, id, property, subpath }`.

- Top-level fields: `subpath = ''`.
- Card child fields: parent's context + `subpath = parent.subpath ? parent.subpath + '/' + cardProp : cardProp`. Implementation hook: `card.js` sets a context reference on each child during `processFields()`.
- Deck item child fields: parent deck's context + `subpath = deckProp + '/' + itemId` (plus card composition if nested deeper, though not a real use case today).
- Each upload-capable field (`file.js`, `image.js`, plus the Tiptap config) reads context at upload time, not at init.

**1.3 Tiptap lazy upload config (fixes existing TODO)**

- `javascript/totalform/totalform/tiptap/TiptapEditor.js` `buildUploadConfig()` (~L502): change `uploadUrl` from a closure capturing init-time URL to a function that asks the owning field for its current context every call.
- `javascript/totalform/tiptap/extensions/ImageUpload.js` and `FileLink.js`: confirm they call `uploadUrl()` per upload (they do — just need to make sure the function is read fresh).
- `javascript/totalform/styledtext.js` `uploadAPI()` (L56-62): refactor to compose URL from `getUploadContext()` instead of `this.form.id`.

This unblocks styledtext uploads inside deck items independently of the new image/file work.

### Phase 2 — Card support

**2.1 Whitelist child types**

- `javascript/totalform/totalform.js` `generateFieldObject()` (~L1550-1620): allow `image` and `file` cases when card/deck context flag is present.
- `src/Domain/Admin/FormField/CardField.php` `buildSubFields()`: same whitelist on the PHP side if it's gating types.

**2.2 Card child upload context**

Cards just append the card's property name to subpath. No item-id concept.

**2.3 Render and value flow**

Card already nests sub-objects in its saved JSON (`obj.cardprop.childprop`). Image/file metadata objects slot in naturally. No schema changes.

### Phase 3 — Deck support

**3.1 Whitelist child types in deck**

Same factory edit as 2.1 — deck and card share `generateFieldObject()`.

**3.2 Deck item upload context**

Deck item subpath = `deckprop/{itemid}`. Item IDs are not user-editable post-creation (confirmed) so no rename-with-uploads scenario.

**3.3 Duplicate behavior**

- `javascript/totalform/deck.js` duplicate handler: when copying an item, walk its sub-field config; for each child of type `image` or `file`, set the duplicated value to empty before the new item is registered.
- **Styledtext exception**: HTML content is copied as-is, including `<img src=...>` and upload-flagged `<a href=...>` referencing the original item's files. Two items end up sharing physical files. **Trade-off**: deleting the original later breaks the duplicate's media. Documented limitation; revisit if it bites users.

**3.4 Save-time reconciliation (server-side cleanup)**

- Hook into `ObjectUpdater` (or wherever the object save diff happens). For each deck field on the schema, compare old item keys vs new item keys. For each removed key, call `PropertyRepository::deleteDirectory()` on `coll/id/deckprop/{removedItemId}/`.
- Same diff applies on full object delete (existing object-delete cleanup should already cascade because the entire `coll/id/` tree is removed; verify this).
- Card field cleanup of replaced single-image values: existing `deleteFile()` flow handles it, since the disk path is deterministic from the old vs new value of a single image/file field — just needs to use the subpath-aware path.

### Phase 4 — Read-side parity

**4.1 PropertyFetcher (keystone)**

- `src/Domain/Property/PropertyFetcher::fetchProperty()`: extend signature to accept a subpath (path segments). Walk the JSON tree using path segments to resolve the value:
  - Top-level (no path): return `obj[rootProp]` (current behavior).
  - Array value at `obj[rootProp]` + single path segment: gallery lookup — return the image whose `name` matches the segment.
  - Object value at `obj[rootProp]` + path segments: descend the tree (`obj[rootProp][path[0]][path[1]]...`).
  - Depot value at `obj[rootProp]` + path segments: depot folder navigation — return the file at the folder path.
- Returns the file metadata + the disk-side subpath so the caller can build the path via `PathUtils::buildPath()`.
- This is the central change — gallery, depot, and the new card/deck cases all funnel through this dispatch.

**4.2 ImageWorks**

- `config/routes/api/imageworks.php`:
  - **Remove** the existing gallery route `/api/imageworks/{coll}/{id}/{property}/{name}.{format}`.
  - **Add** the unified greedy route `/api/imageworks/{coll}/{id}/{rootProp}/{path:.+}.{format}`.
  - Top-level route unchanged.
- `src/Action/ImageWorks/ImageWorksImageFetchAction.php`: read `path` from route args, forward to generator.
- `src/Domain/ImageWorks/Service/ImageGenerator.php`: thread subpath through; call the extended `PropertyFetcher`.

**4.3 Download**

- `config/routes/api/download.php`:
  - **Remove** the existing depot route `/api/download/{coll}/{id}/{property}/{name}` (the upload-specific route stays).
  - **Add** unified `/api/download/{coll}/{id}/{rootProp}/{path:.+}`.
- `src/Action/Download/DownloadFileAction.php`: drop `?path=` query handling; read path from route args. `DownloadFileFromDepotAction` either merges into `DownloadFileAction` (since dispatch is now in `PropertyFetcher`) or is kept as a thin wrapper — decide during implementation based on what's cleaner.

**4.4 Stream**

- `config/routes/api/stream.php`: same shape change as download.
- `src/Action/Stream/StreamFileAction.php`: same as download. `StreamFileFromDepotAction` may merge or stay.

**4.5 URL builders (Twig)**

- `src/Domain/Twig/Adapter/MediaTwigAdapter.php`:
  - `imagePath()`, `galleryPath()`, `download()`, `depotDownload()`, `stream()`, `depotStream()`: all updated to emit segment-based URLs.
  - Existing public signatures preserved — the change is internal to the URL composition.
  - Add `subpath` option (or a single dotted-key string) to `imagePath()` / `download()` / `stream()` for the new card/deck cases.
- Document the new option and the URL format change in `resources/docs/`.

## File-by-file change list

**PHP**
- `config/routes/api/imageworks.php` — replace gallery route with unified greedy-segment route.
- `config/routes/api/download.php` — replace depot route with unified greedy-segment route.
- `config/routes/api/stream.php` — same.
- `config/routes/...upload route file` — add nested upload route alongside top-level.
- `src/Action/Upload/UploadFileAction.php` — read `path` route arg, pass subpath to saver.
- `src/Domain/Upload/UploadSaver.php` — accept and forward subpath.
- `src/Domain/Property/PropertyFetcher.php` — **keystone**: subpath-aware property resolution dispatching on value shape (array=gallery, depot=folder navigation, object=card/deck descent).
- `src/Action/ImageWorks/ImageWorksImageFetchAction.php` — read `path` from route args.
- `src/Domain/ImageWorks/Service/ImageGenerator.php` — thread subpath through.
- `src/Action/Download/DownloadFileAction.php` — read `path` from route args; merge depot logic via `PropertyFetcher` dispatch (decide whether to keep `DownloadFileFromDepotAction` as thin wrapper or merge).
- `src/Action/Stream/StreamFileAction.php` — same as download.
- `src/Domain/Twig/Adapter/MediaTwigAdapter.php` — all URL builders emit segment-based URLs; `imagePath` / `download` / `stream` accept new `subpath` option for nested cases.
- `src/Domain/Object/ObjectUpdater.php` (or wherever save-diff lives) — deck-item-removal cleanup.
- `src/Domain/Admin/FormField/CardField.php` — accept image/file children if currently filtered.
- `src/Domain/Admin/FormField/DeckField.php` / `DeckItem.php` — same.

**JavaScript**
- `javascript/totalform/totalform.js` — extend `generateFieldObject()` whitelist; introduce `getUploadContext()` baseline on `TotalField`.
- `javascript/totalform/card.js` — wire context to child fields.
- `javascript/totalform/deck.js` — wire context to deck-item child fields; clear image/file values on duplicate.
- `javascript/totalform/file.js` — use `getUploadContext()` for upload URL.
- `javascript/totalform/image.js` — same.
- `javascript/totalform/styledtext.js` — `uploadAPI()` uses context.
- `javascript/totalform/tiptap/TiptapEditor.js` — lazy `uploadUrl` in `buildUploadConfig()`.

**Tests**
- New Pest tests:
  - Upload to nested path (card child, deck item child) lands at correct disk location.
  - Imageworks/download/stream resolve nested files via `?path=`.
  - Save-time reconciliation deletes orphaned deck-item upload directories.
  - Subpath sanitization rejects `..` and absolute paths.
- Update existing upload tests to confirm top-level uploads still hit the same paths (no regression).

**Docs**
- `resources/docs/` — document `subpath` option on `cms.media.imagePath()` / `download()` / `stream()`.
- Field reference: note that `image` and `file` are now valid children of `card` and `deck`.

## Test plan (manual)

1. Schema with a card field containing an image and a file child. Upload to each, save, reload form — values persist, files on disk at `coll/id/cardprop/childprop/`.
2. Schema with a deck containing an image child and a styledtext child. Add several items, upload images, embed images in styledtext. Save. Reload. Each item's image renders. Disk layout has per-item directories.
3. Delete a deck item, save. Item directory gone from disk; sibling items unaffected.
4. Duplicate a deck item — image/file fields empty in the copy; styledtext HTML carries forward (with shared media references — verify behavior matches plan).
5. `cms.media.imagePath(obj, [], {property: 'mydeck', subpath: 'item-1/pic'})` produces a working URL.
6. Download/stream for a nested file behaves identically to top-level.
7. Imageworks transformations (resize, format) work on nested images.
8. Top-level (non-nested) uploads, downloads, streams unchanged.

## Open risks

- **`PropertyFetcher` is a hot path** — adding subpath descent and shape-dispatch must not add overhead to top-level lookups. Top-level path (no subpath) should be a fast early return. Benchmark if it matters.
- **Depot URL breaking change**: customers with hardcoded `?path=` depot URLs in templates or external integrations will 404. Twig builders re-emit the new shape automatically, so dynamic URLs are fine. Call out in release notes.
- **Styledtext shared-media references**: if user feedback shows it causes confusion, the cheapest fix later is a "duplicate with media" toggle that re-uploads embedded files. Not building that now.
- **Schema-level validation**: nothing today prevents a user from putting an image inside a card inside a deck inside another card. The plumbing supports arbitrary depth, but UI testing should focus on one-level nesting (image-in-card, image-in-deck-item). Deeper nesting is technically supported but not a target use case.
