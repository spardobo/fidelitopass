<?php

namespace App\Enums;

/**
 * Identifies the application step that could not complete.
 */
enum PassCreationStage: string
{
    case Preparation = 'preparation';

    case Provider = 'provider';

    case Local = 'local';
}
