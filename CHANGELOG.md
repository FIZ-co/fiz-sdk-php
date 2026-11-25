# Changelog

Versions follow [semver](https://semver.org). Changes to the API itself are
in the [API changelog](https://api.fiz.co/docs/changelog).

## 0.1.0 — 2026-11-25

First release.

- Every operation of the FIZ API, with PHPStan/Psalm array shapes generated
  from its OpenAPI document.
- An `Idempotency-Key` on every write, reused by automatic retries.
- Retries of rate-limited requests, of writes still in progress and of
  failed reads, honouring `Retry-After`.
- Exceptions by status with `status`, `errorCode`, `messages` and
  `requestId`.
- `listAll` generators over every list, and PDF downloads.
- API key or OAuth access token; a `fiz_test_` key reaches the sandbox.
- Credentials go to the API's origin only: redirects are never followed
  on API calls, and a file download drops them when a redirect leaves the
  API origin.
- An `Idempotency-Key` the API would refuse (empty, spaces, over 128
  characters) is refused before sending; a response whose body is cut off
  is retried with the same key, or reported with it.
- `onResponse` hook: every response's operation, status, `requestId`,
  key, replay flag, attempt and timing.
- PHP 8.1 or later. Guzzle 7 by default, or any PSR-18 client.
