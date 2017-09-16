<?php

namespace Ockle\Extasset;

class Facade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return Extasset::class;
    }
}
