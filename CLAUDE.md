# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ws-http is a lightweight, cURL-based PHP HTTP client library (currently PHP 5.4-era code) with two higher-level capabilities:

1. **Http Request** — send HTTP requests (SSL, Basic/Digest auth, proxies, cookies, custom headers, JSON/form/multipart bodies) and get a unified `Response`.
2. **Http Watcher** — chainable assertions on a `Response` (status code, headers, body, total time, JSON comparison) for HTTP API testing.
3. **Automated** (incomplete) — JSON-script-driven API test engine (variable substitution, response extraction, assertions, delays), styled after Postman scenarios.

## Refactoring Context (important)

This codebase is being rewritten for PHP 7.4. **Read `design/` first before making changes** — it contains the authoritative analysis and plan:

- `design/01-现状分析与功能架构.md` — architecture analysis and a numbered bug list (B1–B11)
- `design/02-需求文档.md` — full requirements baseline (module IDs N/A/B/C/D/Q)
- `design/03-重构方案与实施步骤.md` — target directory structure and phased implementation plan

Known bugs to fix during refactor (references are in design/01 §6): `Watcher::assertBody` has reversed `strpos` args; `ARequest::verifyHost` calls `verifyPeer`; Automated `Task\Request` auth/proxy conditions are inverted (`empty($x['type'])`); Automated `Bootstrap::runTask` breaks after the first request; `Request::post` has leftover debug `output()` calls; `Body::json` does not auto-set Content-Type despite README claims.

## Architecture

Three layers, all under `src/Ws/Http/`:

```
Request (cURL engine)  ←—  ARequest (static facade → Request::create('default'))
  ├── Response (parsed status/headers/body/raw_body/curl_info)
  ├── Request\Body (json/form/multipart/file body builders)
  ├── Method (interface of IANA HTTP method constants)
  └── Watcher (chainable assertions → throws Watcher\Exception)

Automated\Bootstrap → Task → Task\Body (vars + requests) → Task\Request
  (parses JSON scripts from tests/Ws/Http/_json/*.json; ${var} substitution via varReplace)
```

Key behaviors:
- `Request::create($id)` is a named singleton pool; constructor is private. Default CA bundle is bundled at `src/Ws/Http/_ssl/ca-bundle.crt`.
- `send()` is the single choke point for all requests; shortcut methods (`get/post/...`) delegate to it. GET array params are flattened into the query string by `buildHTTPCurlQuery` (recursive `a[b]` keys, CURLFile-aware).
- `Response::$body` is only populated when Content-Type contains `application/json` and `json_decode` succeeds (respects `jsonOpts`); otherwise it stays `false`.
- Header names are lowercased when sending; `Response` headers are parsed manually (no PECL http dependency), with duplicate headers merged into arrays.
- Automated `postProcessors` (setVar extraction / asserts) are defined in the JSON schema but **not implemented** in code yet.

## Commands

There is no working build or test command yet:

- No `vendor/` — run `composer install` first (composer.json has PSR-4 autoload `Ws\Http\` → `src/Ws/Http`).
- `tests/bootstrap.php` is a hand-rolled test runner that depends on an **external** `\Ws\Env` class (for `output()`) — tests cannot run as-is. The refactor plan replaces this with PHPUnit 9 (`phpunit.xml` at repo root, unit tests in `tests/Unit/`, integration tests in `tests/Integration/`).
- No lint/static-analysis tooling is configured yet; the plan targets PHPStan ≥ level 6 and PHP-CS-Fixer.

## Conventions

- Code, comments, and docs mix Chinese and English (bilingual docblocks); keep this style in new docs.
- Public API signatures of `Request/ARequest/Response/Body/Watcher` must stay backward-compatible during the refactor; bug-fix behavior changes go in the CHANGELOG with a 2.0 version bump.
- Target PHP version for new code is 7.4 (`strict_types`, typed properties); do not add PHP 5.x compatibility shims.
