<?php

namespace App\Enums;

/**
 * Distinguishes a classified failure from an unresolved operation.
 */
enum FailureOutcome: string
{
    case Definitive = 'definitive';

    case Unknown = 'unknown';
}
