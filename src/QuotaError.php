<?php

declare(strict_types=1);

namespace Aigifmaker;

/** The account is out of credits; top up at https://aigifmaker.ai/pricing */
final class QuotaError extends AigifmakerException
{
}
