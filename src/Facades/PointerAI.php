<?php

declare(strict_types=1);

namespace PointerDev\PointerAI\Facades;

use Illuminate\Support\Facades\Facade;

class PointerAI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pointerai.client';
    }
}
