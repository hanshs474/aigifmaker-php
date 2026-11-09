<?php

declare(strict_types=1);

namespace Aigifmaker;

/** The request never got an answer (DNS, TLS, connection reset, timeout). Safe to retry. */
final class NetworkError extends AigifmakerException
{
}
