<?php

namespace App\Enums;

/**
 * Identifies the Google integration step that failed.
 */
enum GoogleWalletStage: string
{
    case Configuration = 'configuration';

    case OAuth = 'oauth';

    case ClassDefinition = 'class';

    case ObjectCreation = 'object';
}
