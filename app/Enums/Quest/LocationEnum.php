<?php

declare(strict_types=1);

namespace App\Enums\Quest;

enum LocationEnum: string
{
    case Gates = 'gates';
    case Tavern = 'tavern';
    case Portal = 'portal';
    case Forest = 'forest';
    case Board = 'board';
    case Lists = 'lists';
    case Training = 'training';
    case Arena = 'arena';
}
