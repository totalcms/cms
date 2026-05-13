---
title: "APIs & Integrations"
description: "REST and PHP APIs for reading and writing Total CMS content from external apps and integrations."
---

# APIs & Integrations

Total CMS is a headless CMS by default. Every operation in the admin is backed by a REST endpoint, and the same endpoints are available to external apps using API keys.

You can use Total CMS purely as a content backend (no Twig templates on the public site) or alongside templates (use REST for AJAX, Twig for SSR).

## Start here

- **[REST API](docs/api/rest-api)** — Endpoints, methods, request and response shapes.
- **[API Keys](docs/api/api-keys)** — Issue and revoke keys; scope them to specific collections.
- **[PHP API](docs/api/php-api)** — In-process API for plugins and other PHP code that runs alongside T3.

## Building integrations

- **[Index Filter](docs/api/index-filter)** — Query parameters for filtering and sorting collection lookups.
- **[Download API](docs/api/download)** — Stream files (depot uploads, image variants).
- **[OpenAPI Docs](docs/api/openapi)** — Interactive Swagger UI for every endpoint.

## Authentication options

- **Session cookies** — for in-browser AJAX (already authenticated as the logged-in user).
- **API keys** — for server-to-server integrations and external apps.
- **CSRF tokens** — required for writes from in-browser code; available as `<meta name="csrf-token">`.

## Common tasks

- **Fetch a collection** — `GET /api/collections/{collection}/objects?filter[status]=published`.
- **Create an object** — `POST /api/collections/{collection}/objects` with JSON body.
- **Update an object** — `PUT /api/collections/{collection}/objects/{id}`.
- **Upload a file** — multipart POST to `/api/upload/...` (see [Upload routes](docs/api/rest-api)).
- **Search across collections** — `GET /api/search?q=...`.
