# aigifmaker

Turn text into animated GIFs with AI GIF Maker. Native GIF generation — no video conversion. Type what you want and download your GIF in about a minute.

This is the official PHP client for [AI GIF Maker](https://aigifmaker.ai/?utm_source=packagist&utm_medium=package). One call sends your prompt, waits for the GIF and returns its URL.

```bash
composer require hanshs474/aigifmaker
```

```php
<?php
require 'vendor/autoload.php';

$gif = new Aigifmaker\Client();   // reads AIGIFMAKER_API_KEY
$url = $gif->generate('a corgi in sunglasses dancing on a beach, looping');
echo $url;                        // the finished GIF
```

PHP 8.1+ with the bundled `curl` and `json` extensions, no other dependencies.

## API key

Create a key at https://aigifmaker.ai/settings/apikeys, then pass it to the constructor or export it:

```bash
export AIGIFMAKER_API_KEY=sk-...
```

Each GIF costs 1 credit on your AI GIF Maker account, the same as on the website. New accounts get one free GIF (it carries a small aigifmaker.ai badge). A GIF that fails to generate gives its credit back. Credit packs are on the [pricing](https://aigifmaker.ai/pricing?utm_source=packagist&utm_medium=package) page.

```php
echo $gif->credits();   // remaining credits, free to call
```

## Options

```php
$gif = new Aigifmaker\Client('sk-...', timeout: 300);   // timeout in seconds, 5 minutes by default
```

## Submit now, collect later

```php
$taskId = $gif->submit('happy birthday text bouncing in rainbow letters');
echo $gif->status($taskId)['status'];   // pending | processing | success | failed
$url = $gif->wait($taskId);             // polls every 3 seconds
```

A run from an account that has not bought credits yet may wait in a short queue first (`'queued' => true` while `status` stays `processing`). That is normal; `generate()` and `wait()` keep polling.

## Errors worth catching

Each failure is its own subclass of `Aigifmaker\AigifmakerException`:

- `AuthError`: no key, or the key is wrong or deleted
- `QuotaError`: the account is out of credits
- `RejectedError`: the content filter refused the prompt, or a cooldown is active; reword it
- `GenerationFailedError`: the run was accepted but failed; the message says why
- `TimeoutError`: still running when the timeout passed; the GIF may finish later in your history
- `NetworkError`: the request never got an answer; safe to retry

Any other refusal throws `AigifmakerException` with the server's message.

## Tests

```bash
php tests/run.php          # mocked, offline
php tests/smoke_live.php   # two free live checks, no generation
```

MIT licensed. Questions: support@aigifmaker.ai
