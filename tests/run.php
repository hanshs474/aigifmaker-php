<?php
// php tests/run.php: mocked unit tests, no framework. Response bodies are the service's real shapes
// (every response is HTTP 200; the JSON "code" decides).
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'Aigifmaker\\')) {
        require __DIR__ . '/../src/' . substr($c, 11) . '.php';
    }
});

use Aigifmaker\Client;

function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, "FAIL $what\n"); exit(1); } }
function ok(array $data): array { return [200, ['code' => 0, 'message' => 'ok', 'data' => $data]]; }
function err(string $msg, int $code = -1): array { return [200, ['code' => $code, 'message' => $msg]]; }
function throws(callable $f, string $class, string $what): \Throwable {
    try { $f(); } catch (\Throwable $x) { check(get_class($x) === $class, "$what: got " . get_class($x)); return $x; }
    check(false, "$what: nothing thrown");
}

const GIF = 'https://cdn.aigifmaker.ai/out/a.gif';

/** submit -> each poll answers the next element of $polls (the last one repeats). */
function fake(array &$calls, array $polls): callable {
    $i = 0;
    return function (string $url, $body, array $headers) use (&$calls, &$i, $polls): array {
        $calls[] = [$url, json_decode(json_encode($body), true), $headers];
        if (str_ends_with($url, '/api/ai/generate')) return ok(['id' => 't1', 'status' => 'pending', 'taskId' => 'up-1']);
        if (str_ends_with($url, '/api/user/get-user-credits')) return ok(['remainingCredits' => 7, 'made' => 2]);
        return $polls[min($i++, count($polls) - 1)];
    };
}
function client(callable $t, ?string $key = 'sk-test', float $timeout = 300.0): Client {
    return new Client($key, timeout: $timeout, pollEvery: 0, transport: $t);
}

// success
$calls = [];
$c = client(fake($calls, [ok(['id' => 't1', 'status' => 'processing']), ok(['id' => 't1', 'status' => 'success', 'images' => [GIF], 'watermarked' => [false]])]));
check($c->generate('a dancing cat') === GIF, 'gif url');
check($calls[0][0] === 'https://aigifmaker.ai/api/ai/generate', 'submit url');
check($calls[0][1] === ['provider' => 'openlux', 'mediaType' => 'image', 'model' => 'gif-maker-v1', 'scene' => 'text-to-image', 'prompt' => 'a dancing cat', 'options' => ['output_format' => 'gif']], 'fixed body');
check(in_array('Authorization: Bearer sk-test', $calls[0][2], true), 'bearer header');
check($calls[1][0] === 'https://aigifmaker.ai/api/ai/query' && $calls[1][1] === ['taskId' => 't1'], 'query body');
check($c->credits() === 7, 'credits');

// queued
$calls = [];
$q = ok(['id' => 't1', 'status' => 'processing', 'queued' => true, 'queueEta' => 30]);
check(client(fake($calls, [$q, $q, ok(['id' => 't1', 'status' => 'success', 'images' => [GIF], 'watermarked' => [true]])]))->generate('x') === GIF, 'queued then success');
check(count($calls) === 4, 'kept polling through the queue');

// failed
$x = throws(fn () => client(fake($calls, [ok(['id' => 't1', 'status' => 'failed', 'errorMessage' => 'upstream error'])]))->generate('x'), \Aigifmaker\GenerationFailedError::class, 'failed');
check($x->getMessage() === 'upstream error', 'failed message');
throws(fn () => client(fake($calls, [ok(['id' => 't1', 'status' => 'failed', 'errorMessage' => 'blocked', 'failureKind' => 'moderation'])]))->generate('x'), \Aigifmaker\RejectedError::class, 'moderation');

// invalid key
throws(fn () => client(fn () => err('invalid API key — create one at https://aigifmaker.ai/settings/apikeys'))->credits(), \Aigifmaker\AuthError::class, 'invalid key');

// wall (no key sent)
putenv('AIGIFMAKER_API_KEY');
$seen = null;
$x = throws(function () use (&$seen) {
    (new Client(transport: function ($u, $b, array $h) use (&$seen) { $seen = $h; return ok(['wall' => true, 'reason' => 'anon_credits']); }))->submit('x');
}, \Aigifmaker\AuthError::class, 'wall');
check(str_contains($x->getMessage(), 'settings/apikeys'), 'wall message points to keys');
check(!array_filter($seen, fn ($h) => str_starts_with($h, 'Authorization')), 'no auth header without key');

// insufficient credits
$x = throws(fn () => client(fn () => err('insufficient credits'))->submit('x'), \Aigifmaker\QuotaError::class, 'quota');
check(str_contains($x->getMessage(), 'aigifmaker.ai/pricing'), 'quota message points to pricing');

// cooldown / generic / timeout / empty prompt
throws(fn () => client(fn () => err('slow down', 429))->submit('x'), \Aigifmaker\RejectedError::class, 'cooldown');
throws(fn () => client(fn () => err('something else'))->submit('x'), \Aigifmaker\AigifmakerException::class, 'generic');
throws(fn () => client(fake($calls, [ok(['id' => 't1', 'status' => 'processing'])]), timeout: 0)->generate('x'), \Aigifmaker\TimeoutError::class, 'timeout');
throws(fn () => client(fake($calls, []))->submit('  '), \InvalidArgumentException::class, 'empty prompt');

echo "all unit tests passed\n";
