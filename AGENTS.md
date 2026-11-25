# Agent rules for fiz-sdk-php

Rules every coding agent (Codex, Cursor, Claude Code, …) and every human
contributor follows in this repository. `CLAUDE.md` imports this file.

## This repository is public

Everything written here is world-readable and permanent: code, comments,
commit messages, pull request titles and descriptions, review comments, issue
replies. A force-push does not take it back; forks and mirrors keep it.

Write from the public API's side, as an outside integrator would read it:

- **Refer to the API only by what is public.** Operations as the OpenAPI
  document names them (`POST /invoices/{id}/issue`), fields and error `code`s
  as documented, the docs at https://api.fiz.co, the changelog at
  https://api.fiz.co/docs/changelog. Write "the API now returns `updatedAt`
  when a draft is created", not where that change was made.
- **Name no internal system.** Not FIZ's private repositories, internal
  services or their components, infrastructure, deploy pipelines, internal
  tools, trackers, chats, dashboards, or internal pull request and ticket
  numbers. If the reason for a change only makes sense with internal context,
  state the observable behaviour instead.
- **No customer or account data.** No company or person names, tax numbers,
  document numbers, record ids, request ids or log excerpts from any account,
  sandbox accounts included: sandbox output is shaped like real data and
  is treated as such. Examples use the placeholders the README uses
  (`Example customer`, `inv1`, `order-1042`).
- **No credentials.** Never commit an API key, live or `fiz_test_`. Tests and
  scripts read `FIZ_API_KEY` / `FIZ_TEST_API_KEY` from the environment;
  `.env` is ignored.
- **No internal process.** Who reviewed what, internal schedules, staffing,
  or decisions made elsewhere stay out. A commit explains *why* from the code
  and the API's documented behaviour.
- **English only**, in code, comments, commits, pull requests and reviews.

Before every push, read the diff, the commit message and the pull request
text once more against this list. Something internal that needs recording
belongs in FIZ's internal tracker, not here.

## What this is

The official PHP client for the FIZ API: certified invoicing in Portugal
(documents numbered, with ATCUD and QR code, reported to the AT). Published
on Packagist as `fiz-co/sdk`. PHP 8.1+; runtime dependencies are Guzzle 7
(the default transport) and the PSR HTTP interfaces, nothing else. It
behaves like the JavaScript SDK (github.com/FIZ-co/fiz-sdk-js): same
resources, methods, options and safety rules.

```
openapi/openapi.json     the API document (from https://api.fiz.co/-json)
src/Generated/           array shapes and the operation table, generated from it
src/FizClient.php        HTTP: auth, Idempotency-Key, retries, errors
src/Exception/           the exception classes
src/Resources/*.php      one typed method per API operation
src/Fiz.php              the Fiz class: resources as properties, call()
tests/Unit/              unit tests (mocked HTTP)
tests/Sandbox/           end to end against the API sandbox, with contract checks
examples/quickstart.php  the runnable example
scripts/                 generate, update-spec, check-readme
```

## Commands

```bash
composer update-spec   # download the live API document and regenerate
composer generate      # regenerate src/Generated/ from openapi/openapi.json
composer check         # code style, PHPStan (max), README examples, unit tests
FIZ_TEST_API_KEY=fiz_test_… composer test:sandbox   # against the API sandbox
```

`composer check` is what CI runs, on PHP 8.1 to 8.4; run it before every
push. Without a local PHP, run the same in a container, e.g.
`docker run --rm -v "$PWD":/app -w /app composer:2 check`.

## Rules of the code

- **Never edit `src/Generated/` by hand.** Change the spec source or
  `scripts/generate.php` and run `composer generate`; CI fails when the
  generated files differ.
- **Every API operation has a resource method.** `tests/Unit/CoverageTest.php`
  fails until a new operation is wrapped; until then it is reachable through
  `$fiz->call('<operationId>')`.
- **The client's safety rules are behaviour, not style.** A change to any of
  them comes with a test that fails on the previous client:
  - one `Idempotency-Key` per call, reused by every retry; an invalid key is
    refused before sending;
  - retries only where a repeat is safe (no response, 429, a 409 with
    `Retry-After` on a keyed write, 502/503/504 on reads), honouring
    `Retry-After`; a known status decides even when its body is lost;
  - a success must be JSON; anything else is a lost response;
  - redirects are not followed with credentials; credentials go only to the
    API origin;
  - an unknown option is refused, never ignored.
- **Fiscal methods say so.** A method that issues, pays, cancels or credits
  (reported to the AT, irreversible with a production key) states it in its
  docblock.
- **Examples are safe to copy.** README and `examples/` run against the
  sandbox first, never issue without an explicit step, and issue exactly the
  draft that was reviewed. Every ```php block of the README is linted and
  analysed by PHPStan (`composer check:readme`).

## Commits, pull requests, releases

- Conventional commits (`feat:`, `fix:`, `docs:`, `chore(spec):`, `ci:`),
  following the public-repository rules above.
- A change a user of the package can notice gets a line in `CHANGELOG.md`.
- Releasing: the version in `Fiz::VERSION` and `CHANGELOG.md`, then a
  `v<version>` tag; Packagist picks the tag up. Details in the README,
  "Development".
