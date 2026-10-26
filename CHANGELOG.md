# Changelog

All notable changes to `honk/honk` (Composer; `honk-me/honk-me` until 0.2.1) are documented
here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

The server now delivers first (contracts/API.md §5.1): it refuses only what it cannot deliver
and corrects the rest, saying what it corrected. The SDK becomes a thin wrapper around that.

### Added
- `Accepted::$warnings`: a list of `Honk\Warning` (`field`, `code`, `message`, `__toString()`
  like `title truncated: shortened to 160 characters`) for what the server corrected (codes
  `truncated`, `normalized`, `ignored`, `clamped`, `downgraded`, `hashed`, also as
  `Warning::TRUNCATED` …); `[]` when nothing. `Accepted::hasWarnings()`; `toArray()` includes
  them.
- `onWarnings` client option (`callable(Accepted): void`, also in `Client::fromEnv()`): called
  once per send that has warnings; anything it throws is ignored.
- Laravel: every warning is logged at `warning` level (field, code, message id, idempotency
  key) for all ways of sending; `honk.log_warnings` / `HONK_LOG_WARNINGS=false` turns it off.
  `HonkSent::$accepted->warnings` carries them and `php artisan honk:test` prints them.

### Changed
- No more local checks of field contents (lengths, URLs, actions, metadata, enums, TTL,
  source sequence, body size): fields are sent as given and the server corrects them. The SDK
  still refuses, before sending, a message without a message and a title, an
  `Idempotency-Key` that is not 1–128 printable ASCII characters (it could not be sent as a
  header), a body that cannot be encoded as JSON, and unknown or wrongly typed keys in arrays.
- A title alone is enough: `Message::make()->title('Backup finished')` sends no `message`.
- The group key is optional everywhere: `uhoh()`, `phew()` (and `problem()`, `recovery()`,
  also on `Honk::defer()`) take `?string $groupKey`; `null` or `''` sends none, and a recovery
  or a `sourceSequence` no longer needs one. An unknown severity is sent as given (the server
  makes it `info` with a warning); horn names are still sent canonical.
- Server errors say everything in the message: status, code, message, each field with its code
  and message, and the request id (`… (event_type invalid_enum: must be one of …) [request
  req_…]`). `FieldError` has a `__toString()`.
- The key no longer has to start with `honk_` locally; the server answers `401 invalid_key`.

### Deprecated
- The `validate` constructor argument (and `fromEnv()` option) is accepted and ignored.

### Removed
- Documentation of the retired `403 priority_not_allowed` and `requires_group_key`: an
  `urgent` priority from a key without *allow urgent* is now lowered to `high` with a
  `downgraded` warning.

## [0.3.2] - 2026-10-18

### Changed
- The README, the Composer description and `php artisan honk:test` call the product
  Honk! Honk!; the test message's title is now "Test message". The package, the namespace and
  the API are unchanged.

## [0.3.1] - 2026-10-15

### Changed
- The repository is now github.com/honk-hq/php, and the User-Agent is `honk-php/<version>`.

## [0.3.0] - 2026-10-14

### Changed
- **Breaking:** the package is now `honk/honk` (was `honk-me/honk-me`) and the namespace is
  `Honk\` (was `HonkMe\`): `Honk\Client`, `Honk\Message`, `Honk\Laravel\Facades\Honk` and so on.
  Laravel auto-discovery registers the new provider and alias; the `Honk` facade,
  `config/honk.php`, its `honk-config` tag and the Artisan commands keep their names. To
  upgrade, add the new package first, replace `HonkMe\` with `Honk\` in your code, and only
  then remove the old one:

  ```sh
  composer require honk/honk
  # replace HonkMe\ with Honk\ in your code
  composer remove honk-me/honk-me
  ```

  In that order, because Composer runs `php artisan package:discover` after each step: removed
  first, an app that calls `->honkOnFailure()` or `Honk::` in `routes/console.php` stops
  booting ("Method …::honkOnFailure does not exist") and the require never runs. If that
  already happened, `composer require honk/honk` fixes it. Jobs queued before the upgrade by
  `Honk::queue()` name the old classes, so let the queue drain before you deploy.
- `php artisan honk:test` without `--message` sends "Honk! Honk! Your phone and your app are on
  speaking terms."

### Added
- `honk()`, `uhoh()` and `phew()` on `Client`, the `Honk` facade and `Honk::defer()`: `honk()`
  sends, `uhoh()` opens a problem and `phew()` closes it. They take the same arguments as
  `send()`, `problem()` and `recovery()`, which stay as aliases (no deprecation notice) and send
  exactly the same request. `Honk::fake()` records both spellings alike.

## [0.2.1] - 2026-10-14

### Changed
- `php artisan honk:test` sends the title "Honk test" and, without `--message`, the text
  "Sent with php artisan honk:test.". The app's name is gone from both: the push's subtitle
  already names the project, so a project called like the app showed its name twice.

## [0.2.0] - 2026-10-07

### Added
- `actions`: up to 3 buttons on a message, `['title' => …, 'url' => …]` with an `https://`,
  `mailto:`, `tel:` or `sms:` URL. `Message::action($title, $url)` adds one,
  `Message::actions([...])` replaces them, and arrays take `'actions'`. Validated locally
  like the server does, with errors named `actions[1].url`. They pass through the Laravel
  facade, `Honk::defer()`, `Honk::queue()`, the notification channel and `Honk::fake()`.
  Empty `actions` are omitted, so messages without buttons are sent exactly as before.

## [0.1.0] - 2026-10-04

### Added
- Framework-agnostic `HonkMe\Client` for `POST /v1/messages` (PHP 8.3+), using ext-curl with
  keep-alive by default or any PSR-18 client with PSR-17 factories.
- Fluent `HonkMe\Message` builder with every field of the v1 ingestion API (including
  `imageUrl`); arrays with camelCase keys are accepted too.
- Automatic UUIDv7 `Idempotency-Key` (or your own), reused on every retry.
- Retries for network errors, timeouts, 429 and 5xx with exponential backoff, full jitter,
  `Retry-After` and a total deadline.
- Typed exceptions: `ValidationException`, `AuthException`, `QuotaException`,
  `ConflictException`, `NetworkException`, `TimeoutException`, `ServerException`.
- Local validation of limits, enums and https-only URLs, with every invalid field reported.
- The Honk scale: `HonkMe\Severity` enum with horn aliases (`Severity::Loud` is
  `Severity::Warning`), `Severity::parse()`, case-insensitive aliases always sent canonical,
  and `light()`, `beep()`, `loud()`, `long()`, `blast()` on the builder, client and facade;
  `problem`, `recovery` and the `info` … `critical` synonyms; `Client::fromEnv()`.
- Laravel 13 integration:
  - auto-discovered `HonkServiceProvider`, publishable `config/honk.php` (tag `honk-config`),
    `php artisan honk:install`, `php artisan honk:test` and a Honk section in
    `php artisan about`;
  - `Honk` facade on a `#[Singleton]` `HonkManager` configured with `#[Config('honk')]`;
  - `Honk::defer()` (after the response, via `defer()`), `Honk::queue()` (`SendHonkMessage` job
    with `#[Tries]` / `#[Backoff]`, routed with `Queue::route()`);
  - `honk` notification channel (`HonkChannel`, `HonkMessage`, the `ToHonk` contract),
    idempotent across queued retries;
  - `Honk::fake()` with `assertSent`, `assertSentTimes`, `assertNotSent`, `assertNothingSent`,
    `assertQueued`, `assertNothingQueued`, `assertNothingOutgoing`;
  - `Honk::reportable()` for `$exceptions->report(...)`: grouped, throttled problems per
    exception class and location;
  - `->honkOnFailure()` / `->honkOnSuccess()` on scheduled tasks;
  - opt-in Context metadata (allow-list, never hidden context);
  - `HonkSent` / `HonkFailed` events.
