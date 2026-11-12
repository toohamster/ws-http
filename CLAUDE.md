# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ws-http is a lightweight, zero-dependency (ext-curl + ext-json only) PHP 7.4 cURL HTTP client library, published as `toohamster/ws-http` (MIT). Current release line: v2.13.x.

Three capability layers, all under `src/Ws/Http/`:

1. **core** (`Ws\Http`) — Request/RequestOptions (wither style) / HeaderBag / Response / Body / Method + `Contract\` extension interfaces (RequestFactory, CookieStore, Comparator, Extractor, Plugin).
2. **functional** (`Ws\Http\Assert|Expression|Automated`) — Watcher chainable assertions; JSONPath subset expression engine; scenario testing engine (ScenarioParser strict schema v2 → Runner state machine → Report JSON contract; variables, extraction, datasets, pause, step context retry/until/when).
3. **plugin** (`Ws\Http\Plugin\*`) — built-in adapters (OpenAI sample) + PluginRegistry; `Ws\Http\NanoGpt\*` agent line (Agent loop, ChatTransportInterface vendor transports, tools, sessions) with the `examples/cc-gpt` CLI shell.

## Authoritative Documentation

**Read `design/` first before making changes**:

- `design/README.md` — document map and reading order
- `design/PROGRESS.md` — per-design implementation status ledger (the source of truth for what is done)
- Design docs 10–17 (architecture/core/assertion/expression/schema/variables/runner/plugin), 20–30 (NanoGpt, pause, datasets, sessions, tools, skill, outputs, transports, step context, quality-management reporting)

Bilingual user manuals live in `docs/reference/` (English default + `zh-CN/` mirror, paired 1:1 — checked by the quality gate). Bilingual style (Chinese primary, English identifiers) is used in design docs; code identifiers are English.

## Commands

All PHP execution must use `/usr/local/bin/php74` (system default `php` is 7.1 — never use it).

```bash
/usr/local/bin/php74 vendor/bin/phpunit --no-coverage          # default suite: Unit + Engine (zero network tests)
/usr/local/bin/php74 vendor/bin/phpunit --testsuite Integration # real-network tests (httpbin.org), opt-in only
/usr/local/bin/php74 vendor/bin/phpstan analyse --no-progress   # static analysis, level 5, 0 errors expected
/usr/local/bin/php74 bin/check                                  # pre-flight quality gate (see below)
php bin/ws-http run scenario.json                               # run a scenario (exit 0/1/2)
```

**Pre-flight gate (`bin/check`)** — runs on any "publish to main"/"release" instruction, before any git action: ① full test suite ② PHPStan ③ docs integrity (0 broken links + zh-CN pairing invariant). Any red → do not tag/push.

## Testing Rules

- Test layering: `tests/Unit` + `tests/Engine` (mock transport via `FakeRequestFactory`/`FakeCurlRequest`) run by default; `tests/Integration` classes **must** carry `@group integration` (excluded from the default suite by `phpunit.xml.dist`).
- Failure-tolerance tests use fakes to simulate **real failure shapes** (500/timeout/exception sequences) — never inject a real client and gamble on external service behavior.
- Scenario/fixture URLs in fake-based tests are plain data (never resolved). Integration tests and doc examples use real public APIs (httpbin.org); do not invent domains.
- On test failure: read the full failure output first (no truncation, no new commands before that), batch-attribute all failures, fix once, run the full suite once.

## Conventions

- PHP 7.4 target: `declare(strict_types=1)`, typed properties; no PHP 8+ syntax (readonly/promotion/match/enum/str_contains). Check `.claude/memory/php74-syntax.md` before writing core classes.
- Zero external dependencies at require level; URL encoding via built-in functions only.
- Engine collects results internally (no exceptions except parse failures); exceptions only at boundaries.
- Schema v2 is strict: unknown fields rejected at load time (validation rules V1–V23, errors carry JSON Pointer, code 402).
- Versioning: pure semver tags only (no descriptive suffixes); new feature = MINOR, fix = PATCH; tags are immutable once pushed. CHANGELOG entry + tag + Release are one release action; commit/release timing is decided by the user.
- One complete delivery = one commit (implementation + tests + docs + ledger + CHANGELOG together). Known defects found during a delivery are fixed within it — never deferred to a later release.
