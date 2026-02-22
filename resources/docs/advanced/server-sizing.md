# Server Sizing Guide

This guide helps you choose the right server resources for running Total CMS 3. Whether you're hosting a single site or managing multiple sites on one server, understanding what drives CPU, memory, and disk usage will help you make informed decisions.

## Quick Reference

| Server Size | CPU | RAM | Storage | Best For |
|-------------|-----|-----|---------|----------|
| Starter | 1 core | 1 GB | 10 GB | Personal site, low traffic, small content |
| Small | 1-2 cores | 2 GB | 25 GB | Client site, moderate traffic |
| Medium | 2-4 cores | 4 GB | 50 GB | Active site with image processing, multiple sites |
| Large | 4+ cores | 8+ GB | 100+ GB | High traffic, large collections, many sites |

---

## CPU: When More Cores Help

Each visitor request is handled by a single CPU core. More cores means your server can handle more visitors simultaneously without slowing down.

### What Uses CPU

**Image Processing (Heaviest CPU Consumer)**
When a visitor requests a resized, cropped, or watermarked image for the first time, your server does the actual image manipulation in real time. This is the single most CPU-intensive operation in Total CMS. Once processed, the result is cached to disk so subsequent requests for the same image are fast.

- Resizing a large photo to a thumbnail: moderate CPU
- Applying an image watermark: high CPU
- Applying a text watermark: moderate CPU
- Combined image + text watermark: very high CPU (two-pass rendering)
- Format conversion (e.g., JPEG to WebP): moderate CPU

**Twig Template Compilation**
The first time a Twig template is loaded, it gets compiled to PHP. This costs some CPU but only happens once — after that, the compiled version is cached. If OPcache is enabled (recommended), the compiled PHP is cached in memory for even faster loading.

**Collection Filtering and Search**
When visitors browse filtered content (e.g., blog posts by category, products by price range), Total CMS filters the collection in memory using PHP. For small to medium collections (under 10,000 objects), this is very fast. For larger collections, filtering takes more CPU time since it scans through all objects.

**Background Jobs (Job Queue)**
Several operations run as background jobs that consume CPU:

- **Index rebuilding** — When you save an object, Total CMS rebuilds the collection's search index. For small collections this is instant, but for collections with thousands of objects, rebuilding the index scans every object and can cause noticeable CPU spikes. For collections with 1,000+ objects, enable the `queueRebuildOnSave` collection setting to move index rebuilds to the background job queue instead of blocking the admin interface.
- **Large imports** — Importing content via JumpStart or migrating from another CMS processes objects one at a time. Each object goes through saving, cache invalidation, and index updating. Importing hundreds or thousands of objects is CPU-intensive and can take several minutes.
- **Factory data generation** — Generating test content with the Factory system creates objects with randomized data, which adds image processing on top of the normal save overhead.

These jobs run as a single-threaded CLI process, so they won't benefit from multiple cores directly — but having extra cores prevents background jobs from competing with visitor requests.

### What to Expect When Adding Cores

**1 core → 2 cores:**
The biggest improvement you'll feel. With 1 core, a single image processing request can block all other visitors. With 2 cores, one core can handle image work while the other serves regular page requests. This is the most impactful upgrade for sites that use ImageWorks.

**2 cores → 4 cores:**
Noticeable improvement for sites with moderate traffic or heavy image processing. Four simultaneous visitors can each get a fast response. If you're running multiple sites on the same server, this helps prevent one busy site from slowing down the others.

**4+ cores:**
Primarily beneficial for high-traffic sites or servers hosting many sites. Each additional core adds capacity for one more simultaneous request.

### CPU Tips

- **Install the Imagick PHP extension.** Total CMS uses Imagick for image processing when available, falling back to GD if not. Imagick is significantly faster than GD, especially for large images. This is one of the easiest performance wins available.
- **OPcache is free performance.** It caches compiled PHP bytecode in memory, eliminating recompilation on every request. Most PHP installations have it enabled by default.
- **Enable `queueRebuildOnSave` for large collections.** Collections with 1,000+ objects should use this setting to prevent index rebuilds from blocking the admin. The object is immediately visible in the index, and the full rebuild happens in the background.
- The job queue runs as a single-threaded CLI process. Adding more cores won't speed up an individual job, but it prevents jobs from competing with visitor requests.

---

## RAM: When More Memory Helps

Memory in Total CMS serves three main purposes: running PHP itself, powering the cache system, and handling content operations.

### How Total CMS Uses Memory

**PHP Request Processing**
Every web request needs memory to run. A typical Total CMS page request uses 20-40 MB of memory. PHP-FPM (the standard way PHP handles web requests) runs multiple worker processes, and each worker needs its own memory allocation. More RAM means you can run more workers, which means more simultaneous visitors.

A rough formula for PHP-FPM workers:
```
Available workers = (Total RAM - 512 MB for OS) / 40 MB per worker
```

For example:
- 1 GB RAM ≈ 12 workers
- 2 GB RAM ≈ 37 workers
- 4 GB RAM ≈ 87 workers

In practice, you won't need this many workers on most sites. The default PHP-FPM configuration is fine for most setups.

**Caching (The Biggest Reason to Add RAM)**
Total CMS uses a multi-layer cache system. The faster layers live in RAM, and giving them more space dramatically improves response times.

The cache priority order is:

1. **APCu** (fastest) — lives in PHP's memory space, no network overhead. Stores collection indexes, object data, schema definitions, and API responses. This is the single biggest beneficiary of extra RAM.
2. **Redis** (fast) — separate memory-based service. Good for multi-server setups or when you want cache to survive PHP restarts.
3. **Memcached** (fast) — similar to Redis, alternative option.
4. **Filesystem** (slowest) — disk-based fallback. Always available but much slower than memory-based caches.

When data is in APCu, it's served in microseconds. When it falls back to the filesystem, it takes milliseconds — orders of magnitude slower. More RAM for APCu means more of your content stays in the fast cache.

**What Gets Cached and For How Long:**

| Data | Cache Duration | Why It Matters |
|------|---------------|----------------|
| Collection indexes | 1 hour | Loaded on almost every page that lists content |
| Individual objects | 4 hours | Loaded when viewing any single content item |
| Schema definitions | 4-24 hours | Loaded on every admin and front-end request |
| API responses | 30 minutes | REST API calls cache their results |
| Twig templates | Until changed | Compiled PHP files cached on disk + OPcache |
| Processed images | Indefinitely | Cached on disk after first generation |

**Large Content Operations**
When filtering or sorting large collections, the entire collection index is loaded into memory. For most sites this is negligible, but collections with tens of thousands of objects will use more memory per request. JumpStart imports and exports stream data to avoid loading everything at once, but the job queue process defaults to a 512 MB memory limit for safety.

### What to Expect When Adding RAM

**1 GB → 2 GB:**
The most impactful upgrade. With 1 GB, there's barely enough room for the OS, PHP workers, and APCu cache. At 2 GB, you can comfortably run 15-20 PHP-FPM workers and allocate 128-256 MB to APCu. Pages that rely on cached content will respond noticeably faster, and the admin interface will feel snappier because schema and collection data stays in cache longer.

**2 GB → 4 GB:**
Meaningful improvement for active sites. You can allocate 256-512 MB to APCu, keeping more collection data in fast memory. This is especially noticeable on sites with many collections or large image galleries where there's a lot of metadata to cache. You also have headroom for more PHP workers to handle traffic spikes.

**4 GB → 8 GB:**
Primarily beneficial for servers hosting multiple sites, sites with very large collections (10,000+ objects), or if you're running Redis alongside PHP. At this level, you can give APCu 512 MB-1 GB, run 50+ PHP workers, and still have comfortable headroom.

**8 GB+:**
For high-traffic servers or those hosting many sites. Consider running a dedicated Redis instance at this level for cache that persists across PHP restarts and can be shared between sites.

### RAM Tips

- **Increase APCu cache size** by setting `apc.shm_size` in your `php.ini`. The default is often 32 MB, which fills up quickly. Set it to at least 128 MB for a single site, or 256-512 MB for multiple sites.
- **Monitor your APCu hit rate** in the admin Cache Manager. If it drops below 80%, your cache is too small and frequently evicting data. Increase `apc.shm_size`.
- Redis and Memcached are optional. For a single server running one or two sites, APCu alone with a filesystem fallback is usually sufficient. Add Redis when you need cache that survives PHP restarts or when scaling to multiple servers.

---

## Disk Space

Disk usage in Total CMS comes from three areas:

**Content Data**
JSON files in the `tcms-data` directory. Text-based content is very compact — even thousands of blog posts only take a few megabytes. The data itself is rarely a storage concern.

**Uploaded Media**
Images, files, and depot uploads are the primary consumers of disk space. A single high-resolution photo can be 5-15 MB. A gallery-heavy site with hundreds of images can easily reach several gigabytes.

**Image Cache**
Every unique combination of image + processing parameters (size, crop, format, watermark) creates a cached file on disk. A single source image displayed at three different sizes creates three cached files. This can grow significantly on image-heavy sites.

**Recommendations:**
- **10 GB** — small sites with limited media
- **25-50 GB** — typical client sites with image galleries
- **100+ GB** — media-heavy sites, large depot file repositories, or multiple sites

**SSD vs HDD:** SSD is strongly recommended. Total CMS reads and writes many small JSON files, and the image cache benefits greatly from fast random read speeds. The difference is especially noticeable in the admin interface and on pages that load many images.

---

## Running Multiple Sites on One Server

Each Total CMS installation runs independently with its own data directory, cache, and configuration. When hosting multiple sites on the same server, resources are shared and you need to plan accordingly.

### How Resources Are Shared

**CPU:** Each site's visitors compete for the same CPU cores. If Site A is processing images while Site B gets a traffic spike, both slow down. Plan for the combined peak load of all sites.

**RAM — PHP Workers:** All sites share the same pool of PHP-FPM workers (unless you configure separate pools per site). Each worker handles one request at a time regardless of which site it's serving.

**RAM — APCu Cache:** All sites share the same APCu memory space. Each site's collection data, schemas, and indexes compete for cache room. With a small APCu allocation, busy sites can evict other sites' cached data, causing cache misses and slower responses.

**Disk:** Each site has its own `tcms-data` directory and image cache. Disk usage is additive across all sites.

### Multi-Site Sizing Guidelines

| Sites | CPU | RAM | APCu Size | Storage |
|-------|-----|-----|-----------|---------|
| 2-3 small sites | 2 cores | 2-4 GB | 256 MB | 25-50 GB |
| 3-5 active sites | 4 cores | 4-8 GB | 512 MB | 50-100 GB |
| 5-10 sites | 4+ cores | 8-16 GB | 1 GB | 100-200 GB |
| 10+ sites | 8+ cores | 16+ GB | 2 GB | 200+ GB |

### Multi-Site Tips

- **Increase APCu size proportionally.** A good starting point is 64-128 MB per site. Monitor the hit rate and increase if it drops below 80%.
- **Consider Redis** for multi-site servers. Redis runs as a separate service with its own dedicated memory, making cache behavior more predictable than APCu which is shared across all PHP processes.
- **Separate PHP-FPM pools** per site (if your hosting allows it) prevents one busy site from consuming all workers and starving other sites.
- **Stagger maintenance tasks.** If multiple sites run JumpStart imports or cache clears simultaneously, they'll compete for CPU and memory. Schedule these at different times.
- **Monitor individual sites.** Each site's admin Cache Manager shows its own cache hit rates and performance metrics. A drop in one site's hit rate may indicate the shared cache is too small.

---

## Cache Configuration

Tuning your cache settings is one of the most effective ways to improve Total CMS performance. The admin Cache Manager shows hit rates and memory usage for each backend — use it to guide your tuning.

### APCu Settings

APCu is the fastest cache layer and the one most worth investing RAM into. Configure it in your `php.ini`:

```ini
; Total memory allocated to APCu
; Default is often 32M, which is too small for most Total CMS sites
apc.shm_size = 128M

; Maximum number of cached entries (default 10000 is usually fine)
apc.entries_hint = 10000

; Enable APCu for CLI scripts (needed for job queue cache access)
apc.enable_cli = 1
```

**Recommended `apc.shm_size` by setup:**

| Setup | Recommended Size | Why |
|-------|-----------------|-----|
| Single small site | 64-128 MB | Enough for indexes, schemas, and frequently accessed objects |
| Single active site | 128-256 MB | Room for larger collections and more object data in cache |
| 2-5 sites on one server | 256-512 MB | All sites share the same APCu pool |
| 5-10 sites on one server | 512 MB-1 GB | Prevents busy sites from evicting other sites' cached data |
| 10+ sites or large collections | 1-2 GB | Keeps hit rates high across many sites |

**How to know if you need more:** Check the Cache Manager in the admin. If your APCu hit rate is below 80%, the cache is evicting data too frequently — increase `apc.shm_size`. The Cache Manager also shows memory usage percentage; if it stays above 90%, the cache is full and needs more room.

### Redis Settings

Redis is configured in your `redis.conf` file (not in PHP). Total CMS connects to Redis at `127.0.0.1:6379` by default.

```conf
# Maximum memory Redis can use
# If not set, Redis will use all available memory
maxmemory 256mb

# What happens when maxmemory is reached
# allkeys-lru evicts least recently used keys (recommended for caching)
maxmemory-policy allkeys-lru

# Optional: require a password
# requirepass your-password-here
```

**Recommended `maxmemory` by setup:**

| Setup | Recommended Size | Notes |
|-------|-----------------|-------|
| Single site | 128-256 MB | Supplements APCu, survives PHP restarts |
| Multiple sites | 256-512 MB | Shared across all sites on the server |
| High traffic | 512 MB-1 GB | Keeps more data cached between restarts |

**When to use Redis:**
- You want cache to persist across PHP restarts (APCu is cleared when PHP restarts)
- You're running multiple sites and want more predictable cache behavior
- You're planning to scale to multiple servers (Redis can be shared across servers, APCu cannot)

**When APCu alone is enough:**
- Single server with 1-2 sites
- You don't mind cache warming up after PHP restarts
- You'd rather keep things simple

### Memcached Settings

Memcached is an alternative to Redis. Total CMS connects to `127.0.0.1:11211` by default. Configure memory allocation when starting Memcached:

```bash
# Start with 256MB of memory
memcached -m 256
```

Most users should choose either Redis or Memcached, not both. Redis is generally recommended for its richer feature set.

---

## Server Configuration Checklist

### PHP Extensions (Required)
- **json** — data storage and API
- **mbstring** — UTF-8 string handling
- **fileinfo** — file type detection

### PHP Extensions (Recommended)
- **Imagick** — significantly faster image processing than GD
- **APCu** — in-memory caching (biggest single performance improvement)
- **OPcache** — bytecode caching (usually enabled by default)
- **intl** — internationalization and date formatting

### PHP Settings to Review

```ini
; Memory per PHP process (default is often 128M, sufficient for most sites)
memory_limit = 128M

; APCu cache size (increase from default 32M)
apc.shm_size = 128M       ; single site
; apc.shm_size = 256M     ; 2-5 sites
; apc.shm_size = 512M     ; 5+ sites

; OPcache (usually enabled by default)
opcache.enable = 1
opcache.memory_consumption = 128

; Upload limits (adjust based on your media needs)
upload_max_filesize = 64M
post_max_size = 64M

; Execution time (sufficient for most operations)
max_execution_time = 30
```

### Optional Services
- **Redis** — recommended for multi-site or high-traffic setups
- **Memcached** — alternative to Redis
- **Cron** — needed if using the job queue for background imports

---

## Monitoring and Troubleshooting

### Signs You Need More CPU
- Pages take a long time to load when images are being processed for the first time
- The admin becomes sluggish when saving objects in large collections (index rebuilding)
- The server becomes unresponsive during traffic spikes
- JumpStart imports or large collection imports take excessively long

### Signs You Need More RAM
- APCu hit rate below 80% (check in admin Cache Manager)
- PHP-FPM logs show "max children reached" warnings
- Pages load slowly even after content has been visited before (cache eviction)

### Signs You Need More Disk
- Image cache directories growing faster than expected
- Upload failures due to insufficient space
- Slow disk I/O on HDD (consider upgrading to SSD)

### Emergency Cache Clearing
If your server becomes unresponsive due to cache issues, Total CMS provides an emergency endpoint at `/emergency/cache/clear` that can be accessed without logging into the admin.
