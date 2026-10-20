<?php

namespace App\Enums;

/** Identifies the rendered steps independently of the browser's submission state. */
enum VisitRegistrationStage: string
{
    case Identify = 'identify';
    case Confirm = 'confirm';
    case Result = 'result';
}
