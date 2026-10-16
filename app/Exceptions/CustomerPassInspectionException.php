<?php

namespace App\Exceptions;

use App\Enums\InspectionFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Domain failure raised by the pass inspection flow.
 *
 * Carries a typed {@see InspectionFailure} and an optional retry hint.
 * The inspection boundary owns operational events; callers map feedback
 * without duplicating reports or retaining a secret-bearing cause.
 */
final class CustomerPassInspectionException extends RuntimeException implements ShouldntReport
{
    /**
     * Builds the exception from its typed failure and optional retry hint.
     *
     * @param  InspectionFailure  $failure  Typed reason for the failure.
     * @param  int|null  $retryAfterSeconds  Seconds the caller should wait before retrying, when applicable.
     */
    public function __construct(
        public readonly InspectionFailure $failure,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('Customer pass inspection failed.');
    }
}
