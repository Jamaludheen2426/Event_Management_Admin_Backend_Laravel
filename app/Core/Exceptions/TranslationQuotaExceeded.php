<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/** The translation provider's daily allowance is spent; retrying today will not help. */
final class TranslationQuotaExceeded extends RuntimeException {}
