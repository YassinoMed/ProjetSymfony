<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum Decision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
