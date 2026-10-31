# Performance testing kit

Measures how Total CMS behaves as collections grow: time and memory per request at 100, 1k, 10k and 50k objects, a profiler for any slow request, and load tests against a server set up like a BSH customer's. Nothing here ships: `bin/` and `tests/` are `export-ignore`.

Design and findings: `docs/planning/3.6/performance-testing.md` (local only).

> **Mac numbers are relative, not absolute.** APFS and macOS file caching behave differently from ext4 on a Linux host, and file I/O is exactly what a flat-file CMS spends its time on. Use the Mac to compare before and after a change; quote throughput and concurrency from the load box only.

## 1. Fixtures

```bash
composer run perf:fixtures        # all sizes, ~3 min (50k alone ~2 min)
php bin/perf-fixtures.php --size=10000 --force
php bin/perf-fixtures.php --size=1000 --pages=500 --root=tmp/perf/1000-p500 --force
```

Each fixture is a self-contained install in `tmp/perf/<name>/`: `public/tcms.php` points at its own `tcms-data/` and `cache/`, and `fixture.json` records the size, seed, PHP and Total CMS versions. It holds a `posts` collection (schema `perf-post`: text, dates, a 10-value `category` select, tags, numbers, styled text; no images, so nothing touches the network) and a `builder-pages` set (70% static routes, 30% templated). Faker is seeded (42), so runs are comparable. `--force` rebuilds an existing fixture; a folder without `fixture.json` is never wiped, `--force` or not.

`--site=<project root>` seeds an existing Composer install instead, in whatever data folder the site is configured with. With `--force` it replaces only a `posts` collection it created itself (the site has `fixture.json` and the collection uses `perf-post`) and the fixture's own pages:

```bash
php bin/perf-fixtures.php --site=$HOME/Websites/acme.totalcms.co --size=10000 --pages=100 --force
(cd ~/Websites/acme.totalcms.co && vendor/bin/tcms cache:clear)   # the web server's APCu still holds the old index
```

## 2. Benchmarks (phpbench)

```bash
composer run bench                # ~10 min; writes tmp/perf/bench.xml
composer run bench -- --filter=IndexBench
composer run bench:baseline       # store a run tagged "baseline" in .phpbench/
composer run bench:compare        # run again and diff against the baseline
php bin/perf-report.php           # bench.xml → bench.csv + flags
```

Each iteration is a fresh PHP process booting one fixture in production mode, so one iteration is one request. "Warm" subjects prime Total CMS's cache, then start a new container (as the next request would) before the measured call. That cache is APCu only while an index fits in `apc.shm_size` — at the default 32M a 50k index does not, and Total CMS falls back to its file cache — so `perf-report.php` prints the `apc.shm_size` in effect. Bench processes share an OPcache file cache (`tmp/perf/opcache`), so compile time stays out of the numbers, as on a live server where clearing Total CMS's caches leaves OPcache warm. "Cold" means Total CMS caches cold, not PHP. `mem_peak` is that request's peak (container boot + the call) — the number that has to fit under `memory_limit`. Bench processes run with `memory_limit=1G` so a large size reports a number instead of dying; the report flags anything over 256 MB (the BSH default).

`composer run bench*` first runs `tests/Performance/fixture-status.php`: missing sizes are listed with the command that builds them and left out of the run; a group with nothing built (all sizes, or all router page counts) stops the run.

| Bench | What it measures |
|---|---|
| `IndexBench` | index load cold (off disk) and warm (Total CMS cache); a full index rebuild |
| `QueryBench` | filter + sort + page 1, the last page, and a repeat served from the response cache |
| `SearchBench` | the built-in `text` provider |
| `TwigBench` | a listing via `cms.collection.objects()`, cold (Twig + caches cleared) and warm |
| `RouterBench` | Site Builder route matching over 10 / 100 / 500 pages: static, templated, and no match (the full walk: every page, then every collection URL) |
| `WriteBench` | saving one existing post through `ObjectUpdater` — includes the index rebuild every save triggers |

`perf-report.php` flags a subject when time or memory grows more than 1.5× faster than the size between two consecutive sizes (`--limit=N` changes the memory line).

## 3. Profiling (SPX)

php-spx is built from source for Homebrew PHP 8.2 and enabled in `/opt/homebrew/etc/php/8.2/conf.d/ext-spx.ini` (profiling only for requests carrying the key, UI only from localhost; profiles in `/opt/homebrew/var/spx`). To rebuild after a PHP upgrade:

```bash
git clone --depth 1 https://github.com/NoiseByNorthwest/php-spx.git && cd php-spx
phpize && ./configure --with-zlib-dir="$(brew --prefix zlib)" && make && make install
patchy restart
```

Use it: open `https://acme.test/?SPX_KEY=dev&SPX_UI_URI=/`, tick **Enabled**, load the slow page, then pick it from the list for a flame graph and timeline. From the command line:

```bash
curl -sk -b "SPX_ENABLED=1; SPX_KEY=dev; SPX_AUTO_START=1; SPX_REPORT=full" https://acme.test/perf/listing
```

Use Xdebug's profiler instead when you need exact call counts; its overhead distorts timings.

## 4. The test site: acme

`~/Websites/acme.totalcms.co` is a Composer install of `totalcms/cms` `dev-develop` (its own private git repo). Locally it is `https://acme.test` (patchy); on the load box it is `acme.totalcms.co`. Data lives in `acme.totalcms.co/tcms-data` (above the web root). Logins and the k6 API key are in `acme.totalcms.co/perf-accounts.local.md` (gitignored).

It runs the `blog` starter plus 10k fixture posts and these test pages (templates in `Fixtures/pages/`, copied to `tcms-data/builder/pages/perf/`; the generator creates their page records when the templates exist):

| URL | Hot path |
|---|---|
| `/perf/listing` | `cms.collection.objects()` → sort → first 20 |
| `/perf/query?page=N&category=cat-3` | filter + sort + paginate in Twig |
| `/perf/search?q=word` | `cms.collection.search()` |
| `/posts/{id}` | one post (builder page `perf-post`) |
| `/classic.php` | a classic PHP page (`new TotalCMS()` + buffered Twig), outside the Slim stack — `Fixtures/classic.php` |
| `/page-N`, `/section-N/{id}` | router filler pages (hidden from the nav) |

## 5. Load tests and the load box

Load testing lives with the site, not here: `~/Websites/acme.totalcms.co` has the k6 scenarios (`perf/k6/`), `bin/perf-load.sh`, `bin/perf-upload-data.sh` and the integrity checker. See `perf/README.md` there.

The box is a dedicated ServerAvatar VPS — never production — sized like most BSH customers start: **1 CPU / 1 GB**, BSH default PHP settings unchanged (`memory_limit` 256M, `pm = ondemand`, `max_children` 20, `max_requests` 500, `process_idle_timeout` 30s). Code reaches it by git (`totalcms/cms` `dev-develop` from Packagist), so a change has to be on `develop` before the box can measure it. Data is uploaded separately, with a clean copy that is restored after every test that writes.
