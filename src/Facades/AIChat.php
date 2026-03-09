<?php

declare(strict_types=1);

namespace PointerDev\AIChat\Facades;

use Illuminate\Support\Facades\Facade;

class AIChat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai-chat.client';
    }
}
