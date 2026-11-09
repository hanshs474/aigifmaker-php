<?php
// php tests/smoke_live.php: two free live checks against https://aigifmaker.ai. No generation runs.
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'Aigifmaker\\')) {
        require __DIR__ . '/../src/' . substr($c, 11) . '.php';
    }
});

use Aigifmaker\AuthError;
use Aigifmaker\Client;

putenv('AIGIFMAKER_API_KEY');

try { (new Client('sk-invalidtest000'))->credits(); fwrite(STDERR, "FAIL invalid key accepted\n"); exit(1); }
catch (AuthError) { echo "ok  invalid key -> AuthError\n"; }

try { (new Client())->submit('smoke test: a blinking star'); fwrite(STDERR, "FAIL no-key submit accepted\n"); exit(1); }
catch (AuthError) { echo "ok  no key -> AuthError (wall)\n"; }

echo "live smoke passed\n";
