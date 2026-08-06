---
title: Cron URLs
description: Run the job queue and scheduled automations over HTTP when your host cannot run scheduled shell commands.
audience: intermediate
related:
  - operations/deployment
  - extensions/cli
---

# Cron URLs

Total CMS does its scheduled work from two cron jobs:

```bash
* * * * * php /path/to/vendor/bin/tcms jobs:process
* * * * * php /path/to/vendor/bin/tcms automations:process
```

Some hosts do not offer that. Their control panel takes a **URL to fetch** on a schedule rather than a command to run, and every external cron service (cron-job.org, EasyCron, and similar) works the same way. On those hosts the queue never drains: imports sit pending forever and scheduled automations never fire.

For that case only, the same two jobs can be triggered over HTTP.

> **This is a fallback, not the recommended setup.** A real cron job has no request time limit and processes everything in one pass. Use cron URLs only when your host cannot run scheduled shell commands at all.

## Finding your URLs

The URLs are shown in the admin, already assembled with your site's token:

- **Job queue** — Utilities → Job Queue Manager, under *No shell access? Use a cron URL instead*
- **Automations** — Automations, in the same place

They look like this:

```
https://example.com/cron/jobs?token=…
https://example.com/cron/automations?token=…
```

Schedule each one to be fetched every few minutes. As with the command-line versions, running them often is cheap — each request checks what is actually due.

## The token

The token is generated the first time you view one of those admin panels and stored at `tcms-data/.system/cron-token`. There is no setup step and nothing to configure.

**Treat the URL as a password.** It will appear in your server's access logs, and in the logs of any proxy or CDN in front of your site — that is unavoidable with URL-based cron. Only ever fetch it over HTTPS, and do not share it.

Requests without a valid token get a `404`, not a `403`, so the endpoint does not advertise its own existence to scanners.

To rotate it, delete `tcms-data/.system/cron-token` and reload the admin panel. A new token is generated, and **any cron already pointed at the old URL stops working** until you update it.

## Limits you should know about

**Each run stops before your server's time limit.** The endpoint measures how long it has been working and stops starting new jobs while there is still time to return a response. Whatever is left stays queued for the next run.

This is deliberate. If a request were simply killed mid-job, that job would be retried and killed again, and after three attempts it would be marked permanently failed — despite nothing being wrong with it. Stopping cleanly between jobs means work either completes or waits.

**A single job larger than the window will never finish.** The protection above only helps jobs that have not started yet. A very large import, or an image-heavy job on a host with a short time limit, can fail to complete no matter how often the URL is fetched. If you are running a bulk migration, do it from the command line.

**Throughput is capped.** At one request per minute with, say, a 25-second working window, you get roughly 25 seconds of processing per minute. A large backlog drains slowly and visibly.

## What the response tells you

Both endpoints return JSON and always answer `200` for normal outcomes, so a monitored cron URL stays quiet:

```json
{"processed": 12, "succeeded": 12, "failed": 0, "deadline_hit": true, "remaining": true}
```

- `deadline_hit` — the run stopped on its time budget with work left over. Normal on a busy queue.
- `remaining` — whether anything is still queued.
- `{"skipped": "already-running"}` — a previous run is still working. Also normal; the endpoints take the same lock as the command-line versions, so runs cannot overlap or stack.

## Moving back to real cron

Nothing needs undoing. Point a normal cron job at the two commands at the top of this page and stop fetching the URLs. The token can stay where it is, or be deleted.
