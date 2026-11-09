<?php

declare(strict_types=1);

namespace Aigifmaker;

/**
 * Base of every error this library throws on purpose. Catch a subclass to decide what to do next:
 * AuthError · QuotaError · RejectedError · GenerationFailedError · TimeoutError · NetworkError.
 * Any other refusal is this class itself, with the server's message.
 */
class AigifmakerException extends \RuntimeException
{
    /** @param int|null $apiCode the JSON "code" of the response, when there was one */
    public function __construct(string $message, public readonly ?int $apiCode = null)
    {
        parent::__construct($message);
    }
}
