<?php

namespace App\Enums;

enum PromotionStatus: string
{
    case Draft = 'draft';

    case Published = 'published';

    case Cancelled = 'cancelled';
}
