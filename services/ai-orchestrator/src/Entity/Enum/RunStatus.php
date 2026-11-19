<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum RunStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
