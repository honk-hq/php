# honk/honk

[![CI](https://github.com/honk-hq/php/actions/workflows/ci.yml/badge.svg)](https://github.com/honk-hq/php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/honk/honk)](https://packagist.org/packages/honk/honk)

Your PHP app honks, your phone buzzes. The official PHP client for [Honk! Honk!](https://honk-honk.app),
the inbox that turns events from apps, scripts, cron jobs and CI into calm, grouped push
notifications. It works in any PHP 8.3 app and comes with a full Laravel 13 integration.

```php
use Honk\Laravel\Facades\Honk;

// The oven sensor smells trouble.
Honk::uhoh('kitchen/oven', 'The cake is burning', 'Oven at 260 °C and climbing.');

// Someone got there in time.
Honk::phew('kitchen/oven', 'Cake saved', 'Nobody needs to know.');
```

`uhoh()` opens a problem and `phew()` closes it. The shared group key `kitchen/oven` ties them
together: an oven that keeps panicking adds to the same incident instead of buzzing you every
minute, and `phew()` closes it with one last push.

No Laravel? Same idea, with a client:

```php
use Honk\Client;
use Honk\Message;

$honk = Client::fromEnv(); // HONK_URL and HONK_KEY

$honk->honk(Message::make('Someone bought 3 rubber chickens.')->title('New order')->beep());
```

- PHP 8.3+. ext-curl with keep-alive by default, or any PSR-18 client.
- Laravel 13: auto-discovered provider, `Honk` facade, `Honk::defer()` / `Honk::queue()`,
  the `honk` notification channel, `Honk::fake()`, exception reporting, scheduler hooks,
  `php artisan honk:install` / `honk:test` and a `php artisan about` section.
- Retries with backoff, `Retry-After`, a total deadline and an idempotency key on every send,
  so a retry never creates a duplicate.

The ingestion key (`honk_…`) is a secret: keep it in `.env`, never in front-end code or Git.

## Install

```sh
composer require honk/honk
```

Create a project and an ingestion key at [honk-honk.app](https://honk-honk.app). Its
*Integrations* page generates ready-to-paste code for plain PHP and Laravel.

`Client::fromEnv()` reads `HONK_URL`, `HONK_KEY` and the optional `HONK_SOURCE`,
`HONK_ENVIRONMENT` and `HONK_CHANNEL` defaults; `new Client(url: …, key: …)` takes them
directly (see [Options](#options)).

## The Honk scale

Five levels. The Honk app shows them by their standard names (Info, Success, Warning, Error,
Critical); people who prefer horn sounds can switch on the Honk names (Light, Beep, Loud, Long,
Blast) with the "Honk names for levels" switch in Settings. The SDK takes both: the canonical values and the
Honk names as input aliases, in any case, and always sends the canonical value.

| Level | Honk name | Severity | Client / facade | Builder | Enum |
|---|---|---|---|---|---|
| Info | Light | `light` (info) | `light($title, $message, $options)` | `->light()` | `Severity::Light` |
| Success | Beep | `beep` (success) | `beep(…)` | `->beep()` | `Severity::Beep` |
| Warning | Loud | `loud` (warning) | `loud(…)` | `->loud()` | `Severity::Loud` |
| Error | Long | `long` (error) | `long(…)` | `->long()` | `Severity::Long` |
| Critical | Blast | `blast` (critical) | `blast(…)` | `->blast()` | `Severity::Blast` |

`->severity('loud')`, `'LOUD'`, `'warning'` and `Severity::Loud` (which *is*
`Severity::Warning`) are the same event, also for idempotency. The level alone decides how
loud the push is. `info()`, `success()`, `warning()`, `error()` and `critical()`
remain as synonyms; `Severity::parse('Blast')` returns `Severity::Critical`.

## Laravel 13

```sh
composer require honk/honk
php artisan honk:install      # publishes config/honk.php, adds HONK_URL / HONK_KEY to .env.example
```

Put your server and a project ingestion key in `.env` (never commit it), then check the
connection:

```dotenv
HONK_URL=https://honk.example.com
HONK_KEY=honk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

```sh
php artisan honk:test         # sends a Light test message and prints its id
php artisan about --only=honk # URL, key prefix (never the secret), queue, Context metadata
```

`source` defaults to your `APP_NAME` (slugged) and `environment` to `APP_ENV`. The package is
for Laravel 13 only (PHP 8.3+); Composer refuses older Laravel versions.

### Recipe: notify me when a customer asks for something

**1. A notification** on the `honk` channel. `ShouldQueue` keeps the customer's request fast;
Laravel 13's queue attributes set the retries:

```php
// app/Notifications/CustomerRequested.php
namespace App\Notifications;

use App\Models\CustomerRequest;
use Honk\Laravel\Notifications\HonkMessage;
use Honk\Laravel\Notifications\ToHonk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

#[Tries(5)]
#[Backoff(10, 60, 300, 900)]
class CustomerRequested extends Notification implements ShouldQueue, ToHonk
{
    use Queueable;

    public function __construct(public CustomerRequest $request)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['honk']; // add 'mail', 'database', … as you like
    }

    public function toHonk(object $notifiable): HonkMessage
    {
        $r = $this->request;

        return HonkMessage::create()
            ->title(Str::limit("New request: {$r->subject}", 150))
            ->line("{$r->name} ({$r->company})")
            ->line(Str::limit($r->body, 2000))
            ->light()                                // a Light message (info)
            ->category('customers')
            ->groupKey("requests/{$r->id}")          // one group per request
            ->occurredAt($r->created_at)
            ->url(route('admin.requests.show', $r))  // https only, shown as "Open link"
            ->meta('request_id', (string) $r->id);
    }
}
```

**2. Send it** where the request is stored:

```php
$request = CustomerRequest::create($validated);
$admin->notify(new CustomerRequested($request));            // a User with the Notifiable trait
Notification::route('honk', null)->notify(new CustomerRequested($request)); // or without a user
```

The phone buzzes once per request, follow-ups for the same request update the same group, and
queue retries never create duplicates: the idempotency key is `notification-{id}`, which
Laravel keeps across retries. `examples/laravel-app` in the Honk! Honk! repository is this recipe as a
complete Laravel 13 + Inertia + Vue + Wayfinder app.

- Keep `toHonk()` deterministic (no `now()`; use `$r->created_at`): a queued retry must send the
  same payload, otherwise Honk! Honk! answers `409 idempotency_conflict`.
- Links and images must be `https://`. With `APP_URL=http://localhost` in development, leave
  `->url()` / `->imageUrl()` out or guard them with `str_starts_with($link, 'https://')`.
- Routing: without `routeNotificationForHonk()` the configured key is used. Return another
  ingestion key, an array of config overrides (`['key' => …, 'defaults' => [...]]`), a
  `Honk\Client`, or `false` to skip Honk! Honk! for that notifiable.

### Send now, after the response, or from the queue

```php
use Honk\Laravel\Facades\Honk;
use Honk\Message;

Honk::defer()->beep('New order', "{$order->email} paid {$order->total} €");   // after the response
Honk::queue(Message::make('Imported 1 204 rows')->beep()->channel('imports'));   // a queued job
Honk::loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/app-01/var']);     // right now
```

| | Runs | Use it for |
|---|---|---|
| `Honk::defer()` | in this process, after the HTTP response is sent (Laravel's `defer()`; after the command or job in the console and on queues) | web requests where a lost honk is acceptable if the process dies; no worker needed. Skipped when the request fails (4xx/5xx) unless `Honk::defer(always: true)` |
| `Honk::queue()` | in a queue worker (`SendHonkMessage`, `#[Tries(5)]`, `#[Backoff(10, 60, 300, 900)]`) | anything that must arrive: survives restarts and retries with the same key; a daily quota releases the job until the reset |
| `Honk::honk()`, `Honk::light()` … | right now, retries included (up to `honk.deadline`) | jobs, commands, the scheduler |

`defer()` and `queue()` check right away that there is a message or a title, so that mistake
surfaces in your code, not later. `queue()` returns the job; the connection and queue come from
`HONK_QUEUE_CONNECTION` / `HONK_QUEUE` (registered with Laravel 13's `Queue::route()`).

### Testing: `Honk::fake()`

```php
use Honk\Laravel\Facades\Honk;
use Honk\Message;
use Honk\Severity;

Honk::fake();

$this->post(route('quote.store'), $data);

Honk::assertSent(fn (Message $m) => $m->groupKey === 'requests/1' && $m->severity === Severity::Light);
Honk::assertSentTimes(1);
Honk::assertNotSent(fn (Message $m) => $m->severity === Severity::Blast);
Honk::assertQueued(fn (Message $m, string $idempotencyKey) => $m->channel === 'imports');
Honk::assertNothingSent(); // also assertNothingQueued(), assertNothingOutgoing()
```

The fake records `honk()`, `uhoh()`, `phew()`, the horn helpers, `defer()` and
notification-channel sends as *sent*, and `queue()` as *queued*; nothing touches the network.
It checks like the real client (no message and no title still throws), applies the defaults and
Context metadata, and records the severity as a `Severity` case whatever spelling was used.
Callbacks receive the message and its idempotency key. With `Notification::fake()` instead,
`toHonk()` is never called.

### Exceptions

One line in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->report(Honk::reportable());
})
```

Every reported exception becomes a Long message: a *problem* whose `group_key` is
`exceptions/<Class>@<file>:<line>`, so repeats of one failure are a single incident. At most
one honk per group per 5 minutes (`honk.exceptions.throttle`, cache-backed), sent after the
response (`honk.exceptions.mode`: `defer`, `sync` or `queue`). Pick another severity or window
per app: `Honk::reportable(severity: 'blast', throttleSeconds: 60)`; list classes to skip in
`honk.exceptions.ignore`. It never honks about the SDK's own failures, does nothing while
`HONK_URL` / `HONK_KEY` are missing (local development, CI), and never throws. Exception
messages are sent as they are (truncated): do not put personal data in them.

### Scheduler

```php
// routes/console.php
Schedule::command('backup:run')->daily()->honkOnFailure();          // problem on failure, recovery once it works again
Schedule::command('reports:send')->hourly()->honkOnSuccess();       // a Beep message after every successful run
```

`honkOnFailure(?string $groupKey = null, string $severity = 'long')` sends a problem with the
exit code and the output tail, grouped per task (`schedule/<command or description>`), and a
recovery the next time the task succeeds, only after a failure: the failing state lives in the
cache, so a healthy task never pings (a recovery without an open problem would still notify).

### Context (opt-in)

With `HONK_CONTEXT=true`, the keys listed in `honk.context.keys` (default `request_id`,
`trace_id`, `route`) are copied from Laravel's `Context` into each message's `metadata`. Only
visible context is read, never hidden context; the message's own metadata wins; the 16-key
limit is respected. Nothing is copied by default, and the list should never contain personal
data (emails, names, IP addresses). Add the values in a middleware, for example:

```php
Context::add('trace_id', (string) Str::uuid());
Context::add('route', $request->route()?->getName());
```

Context also travels with queued jobs, so queued honks carry the values of the request that
dispatched them.

### Events and container

- `Honk\Laravel\Events\HonkSent` (message, `Accepted` with its `warnings`, idempotency key, `via`) and
  `HonkFailed` (message, exception, idempotency key, `via`) fire for every message; `via` is
  `'send'` (sent right away), `'defer'`, `'queue'` or `'notification'`.
- `HonkManager` (the facade root) is a `#[Singleton]` that receives `#[Config('honk')]`; inject
  it, or `Honk\Client`, anywhere. Config is read when Honk! Honk! is first used.

## Grouping in three lines

Messages with the same `groupKey` (per project, environment, source and channel) form one
group: the first one pushes, repeats update it calmly instead of buzzing again. Use one key
per customer request (`requests/{$id}`), and a shared key only for repeats of the same problem
(`queue/failed-jobs`). The group key is optional: without one, a message groups by its title.

## Sending

```php
$client->honk(Message|array $message, ?string $idempotencyKey = null): Accepted // id, duplicate, receivedAt, warnings
```

`honk()`, `uhoh()` and `phew()` arrived in 0.3.0; `send`, `problem` and `recovery` still work
and send exactly the same request.

The essentials:

| Field (builder method) | Notes |
|---|---|
| `message` | the text; line breaks allowed (`->line()` appends one) |
| `title` | one line; default: the first line of the message. A message or a title is required, nothing else |
| `severity` (`->light()` … `->blast()`) | the level, and the level alone decides how loud the push is: `light` `beep` `loud` `long` `blast`, a `Severity` case, or the canonical names, any case; default `light` |
| `groupKey` | optional; ties repeats and a problem to its recovery |
| `eventType` | `event` `problem` `recovery` (what `uhoh()` / `phew()` set) |

The other fields (`priority`, `category`, `source`, `environment`, `channel`, `occurredAt`,
`url`, `imageUrl`, `actions`, `metadata`, `ttlSeconds`, `sourceSequence`) mirror
`POST /v1/messages` in camelCase; `occurredAt` takes a `DateTimeInterface` (Carbon works). The
SDK does not check their contents: the server does, and it delivers the message even when it
has to correct one (see [Warnings](#warnings)).

Arrays use the same camelCase keys (`['message' => …, 'groupKey' => …]`); snake_case keys
are rejected with a hint. Null and empty optional fields (and empty `actions`) are omitted. A
returned `Accepted` means Honk! Honk! **durably stored** the message (`202`), not that a push was
delivered or read.

Helpers (`$options` takes any field in camelCase plus `idempotencyKey`):

```php
$client->loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/app-01/var']);
$client->light($title, $message, $options);  $client->beep(...);  $client->long(...);  $client->blast(...);
$client->uhoh('db/backup', 'Backup failed', 'pg_dump exited with 1');   // opens a problem; a Long message by default
$client->phew('db/backup', 'Backup OK', 'pg_dump finished in 41 s');    // closes it; a Beep message by default
$client->phew(null, 'Backup OK', 'pg_dump finished');                  // no group key: matched by the title
```

### Buttons (actions)

Up to three buttons on the message, in display order: reply to the customer, call them, open
the order.

```php
$client->honk(Message::make('Maria Tudor (Acme) asked for a quote: online shop, 40 products')
    ->title('New request: online shop quote')
    ->category('customers')
    ->groupKey('requests/4812')
    ->action('Reply', 'mailto:maria@acme.example?subject=' . rawurlencode('Your quote'))
    ->action('Call Maria', 'tel:+15550134'));
```

- `title`: a short label, one line, shown as sent.
- `url`: `https://`, `mailto:` (optionally `?subject=…&body=…`, percent-encoded with
  `rawurlencode()`), `tel:` or `sms:`. The server leaves out a button it cannot use and says so
  in the warnings (`actions[1].url ignored`); the message is still delivered.
- Honk! Honk! never opens or fetches them; the app does when you tap one. They appear on the
  message in the app and the web inbox, and on iPhone notifications that show the message
  text.
- The same works in `toHonk()` (`HonkMessage::create()->action(…)`), in arrays
  (`'actions' => [['title' => 'Call Maria', 'url' => 'tel:+15550134']]`), with `Honk::queue()`
  and `Honk::defer()`. `->actions([...])` replaces the list.

### Options

```php
new Client(
    url: 'https://honk.example.com',
    key: 'honk_…',
    timeoutMs: 5000,       // per attempt
    retries: 4,            // after the first attempt
    deadlineMs: 30000,     // total, waits included
    defaults: ['source' => 'billing', 'environment' => 'production', 'channel' => 'payments'],
    httpClient: $psr18,    // optional PSR-18 client; with requestFactory/streamFactory (auto with Guzzle)
    onWarnings: fn (Accepted $a) => error_log(implode('; ', $a->warnings)), // optional, see Warnings
);
```

With a PSR-18 client, configure its own timeout and disable redirects
(Guzzle: `new GuzzleHttp\Client(['timeout' => 5, 'allow_redirects' => false])`); the SDK
still enforces the total deadline.

## Warnings

The server delivers first: an alert is never lost to an optional field. When it has to correct
something (shorten a long title, leave out an `http://` link or a button it cannot use, move a
TTL into range, lower `urgent` for a key that may not send it), it accepts the message and says
what it did. `Accepted::$warnings` lists them (`[]` when nothing was corrected):

```php
$accepted = $honk->honk(Message::make('Disk 91%')->title($veryLongTitle)->url('http://grafana.local'));

foreach ($accepted->warnings as $w) {   // Honk\Warning
    echo $w;                            // "title truncated: shortened to 160 characters"
    // $w->field ("title", "metadata.order", "actions[1].url"), $w->code, $w->message
}
$accepted->hasWarnings();
```

`$w->code` is `truncated`, `normalized`, `ignored`, `clamped`, `downgraded` or `hashed`
(`Warning::TRUNCATED` …). Warnings are never exceptions. To log them in one place, pass
`onWarnings: fn (Accepted $a) => …` to the client; it runs once per send that has warnings, and
anything it throws is ignored. In Laravel each warning is logged at `warning` level (with the
field, code, message id and idempotency key) for every way of sending; turn that off with
`HONK_LOG_WARNINGS=false` (`honk.log_warnings`). `HonkSent::$accepted->warnings` has them too,
and `php artisan honk:test` prints them.

## Retries and idempotency, guaranteed

- Every send carries an `Idempotency-Key`: yours, or a fresh UUIDv7. **The same key is reused
  on every retry.** Within 24 h Honk! Honk! answers a replay with the original id and
  `duplicate: true`, so a lost response never creates a second message.
- Only network errors, timeouts, `429` and `5xx` are retried, with exponential backoff and
  full jitter (`random(0, min(8 s, 0.5 s·2ⁿ))`), never sooner than the server's `Retry-After`.
- Everything stops at `deadlineMs`: if the next wait would cross it (for example a daily quota
  that resets at midnight), the exception is thrown at once with `retryAfter`.
- `4xx` other than `429` are never retried: fix the request instead.
- Short per-attempt timeouts, one keep-alive connection per client (reuse the client; Laravel
  keeps one per worker).
- Use a stable key of your own (`request-4812`) to stay duplicate-free across processes and job
  retries. Reusing a key with a *different* payload is a conflict.

## Errors

All exceptions extend `Honk\Exception\HonkException` and expose `status`, `errorCode`,
`requestId`, `idempotencyKey`, `attempts`, `retryAfter` and `isRetryable()`. The message says
it all, e.g. `Honk 422 validation_failed: Request validation failed (event_type invalid_enum:
must be one of event, problem, recovery) [request req_01k6…]`.

The server refuses only what it cannot deliver: a bad or missing key, invalid JSON, a body over
16 KiB, no message and no title, an unknown top-level field, an invalid `eventType`.

| Exception | When | What to do |
|---|---|---|
| `ValidationException` | `400`/`413`/`415`/`422`, or locally (`local === true`) for no message and no title or an invalid idempotency key; `fields` lists each field with its code and message | fix the message |
| `AuthException` | `401 invalid_key`, `403 project_suspended`, `workspace_suspended` | fix the key |
| `QuotaException` | `429 quota_exceeded` (daily, resets at UTC midnight) or `rate_limited`, after retries | retry after `retryAfter` seconds |
| `ConflictException` | `409 idempotency_conflict`: same key, different payload | use a new key or the original payload |
| `NetworkException` | unreachable on every attempt | retry later, same key |
| `TimeoutException` (a `NetworkException`) | attempts timed out; the message may or may not be stored | retry later, same key |
| `ServerException` | `5xx` on every attempt | retry later, same key |

```php
use Honk\Exception\HonkException;
use Honk\Exception\ValidationException;

try {
    $honk->honk($message, "order-{$order->id}-failed");
} catch (ValidationException $e) {
    report($e); // a bug: $e->fields (field, code, message) says what to fix
} catch (HonkException $e) {
    if (!$e->isRetryable()) {
        throw $e;
    }
    // retry later with $e->idempotencyKey, after $e->retryAfter seconds if set
}
```

## Development

```sh
composer install
composer test                 # unit tests (scriptable mock server) + Laravel 13 tests (Orchestra Testbench 11)
composer analyse              # PHPStan level max with Larastan
HONK_URL=… HONK_KEY=… composer test:integration   # against a real server (use a test project's key)
```

The version lives in `Honk\Client::VERSION` (also the User-Agent). Releases: push a tag
`vX.Y.Z` matching it; Packagist picks the tag up (see `CHANGELOG.md`).

## Links

- [honk-honk.app](https://honk-honk.app): the Honk! Honk! inbox (web, iPhone).
- Other SDKs: [Node.js](https://github.com/honk-hq/node),
  [Go + CLI](https://github.com/honk-hq/go), [Swift](https://github.com/honk-hq/honk-swift),
  [Kotlin / Java](https://github.com/honk-hq/kotlin).

MIT License.
