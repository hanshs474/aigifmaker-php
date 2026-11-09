<?php

declare(strict_types=1);

namespace Aigifmaker;

/**
 * Official PHP SDK for AI GIF Maker (https://aigifmaker.ai): turn text into animated GIFs.
 *
 *     $gif = new Aigifmaker\Client();            // reads AIGIFMAKER_API_KEY
 *     $url = $gif->generate('a corgi in sunglasses dancing on a beach');
 *     $left = $gif->credits();
 *
 * Create a key at https://aigifmaker.ai/settings/apikeys. Each GIF costs 1 credit on your
 * AI GIF Maker account, the same as on the website.
 */
final class Client
{
    public const VERSION = '0.1.1';
    public const BASE_URL = 'https://aigifmaker.ai';
    public const KEYS_URL = 'https://aigifmaker.ai/settings/apikeys';
    public const PRICING_URL = 'https://aigifmaker.ai/pricing';

    private ?string $apiKey;
    private string $baseUrl;
    /** @var callable(string,array|object,array):array{0:int,1:array} */
    private $transport;

    /**
     * @param string|null $apiKey  defaults to the AIGIFMAKER_API_KEY environment variable
     * @param float $timeout  seconds generate() waits for a GIF (default 15 minutes)
     * @param float $pollEvery  seconds between status checks
     * @param callable|null $transport  fn(string $url, array|object $body, array $headers): array{int, array}, for tests
     */
    public function __construct(
        ?string $apiKey = null,
        private float $timeout = 900.0,
        private float $pollEvery = 3.0,
        string $baseUrl = self::BASE_URL,
        ?callable $transport = null,
    ) {
        $key = trim((string) ($apiKey ?? (getenv('AIGIFMAKER_API_KEY') ?: '')));
        $this->apiKey = $key === '' ? null : $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'http'];
    }

    /** Generate one animated GIF and return its URL. */
    public function generate(string $prompt): string
    {
        return $this->wait($this->submit($prompt));
    }

    /** Start one GIF and return its task id without waiting. */
    public function submit(string $prompt): string
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('prompt is required');
        }
        $data = $this->post('/api/ai/generate', [
            'provider' => 'openlux',
            'mediaType' => 'image',
            'model' => 'gif-maker-v1',
            'scene' => 'text-to-image',
            'prompt' => $prompt,
            'options' => ['output_format' => 'gif'],
        ]);
        $id = $data['id'] ?? null;
        if ($id === null || $id === '') {
            throw new AigifmakerException('the service returned no task id');
        }
        return (string) $id;
    }

    /**
     * The task record: "status" is pending, processing, success or failed;
     * "images"[0] holds the GIF URL when it succeeds.
     *
     * @return array<string,mixed>
     */
    public function status(string $taskId): array
    {
        return $this->post('/api/ai/query', ['taskId' => $taskId]);
    }

    /** Poll a task every 3 seconds until it finishes and return the GIF URL. */
    public function wait(string $taskId, ?float $timeout = null): string
    {
        $limit = $timeout ?? $this->timeout;
        $deadline = microtime(true) + $limit;
        while (true) {
            try {
                $d = $this->status($taskId);
            } catch (NetworkError) {
                $d = []; // a dropped poll is not a failed generation
            }
            $state = strtolower((string) ($d['status'] ?? ''));
            $images = $d['images'] ?? [];
            if ($state === 'success' && is_array($images) && $images !== []) {
                return (string) $images[0];
            }
            if ($state === 'failed' || $state === 'canceled') {
                $msg = (string) ($d['errorMessage'] ?? "the GIF $state");
                if (($d['failureKind'] ?? null) === 'moderation') {
                    throw new RejectedError($msg);
                }
                throw new GenerationFailedError($msg);
            }
            if (microtime(true) >= $deadline) {
                throw new TimeoutError("still running after {$limit}s; task $taskId may finish later in your AI GIF Maker history");
            }
            usleep((int) ($this->pollEvery * 1e6));
        }
    }

    /** Remaining credits on the account. Free to call. */
    public function credits(): int
    {
        return (int) ($this->post('/api/user/get-user-credits', (object) [])['remainingCredits'] ?? 0);
    }

    /**
     * Return data for code 0; otherwise throw the error that says what to do next.
     * Every response is HTTP 200; the JSON "code" tells success from refusal.
     *
     * @return array<string,mixed>
     */
    public static function unwrap(int $httpStatus, array $env): array
    {
        $code = $env['code'] ?? null;
        $data = is_array($env['data'] ?? null) ? $env['data'] : [];
        if ($code === 0) {
            if (!empty($data['wall'])) {
                throw new AuthError('an API key is required; create one at ' . self::KEYS_URL . ' and set AIGIFMAKER_API_KEY', 0);
            }
            return $data;
        }
        $msg = (string) ($env['message'] ?? $env['error'] ?? "HTTP $httpStatus");
        $low = strtolower($msg);
        $c = is_int($code) ? $code : null;
        if (str_contains($low, 'invalid api key') || str_contains($low, 'no auth') || $httpStatus === 401) {
            throw new AuthError($msg, $c);
        }
        if (str_contains($low, 'insufficient credits')) {
            throw new QuotaError("$msg. Top up at " . self::PRICING_URL, $c);
        }
        if ($c !== null && $c !== -1) {
            throw new RejectedError($msg, $c);
        }
        throw new AigifmakerException($msg, $c);
    }

    /** @return array<string,mixed> */
    private function post(string $path, array|object $body): array
    {
        $headers = ['Content-Type: application/json', 'User-Agent: aigifmaker-php/' . self::VERSION];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        [$status, $env] = ($this->transport)($this->baseUrl . $path, $body, $headers);
        return self::unwrap($status, $env);
    }

    /** @return array{0:int,1:array} */
    public static function http(string $url, array|object $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new NetworkError($err ?: 'request failed');
        }
        $json = json_decode((string) $raw, true);
        return [$status, is_array($json) ? $json : ['code' => -1, 'message' => substr((string) $raw, 0, 200) ?: "HTTP $status"]];
    }
}
