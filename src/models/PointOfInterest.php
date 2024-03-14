<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class PoiPage extends Page
{
    // public function fases()
    // {
    //     $fases = A::get($this->content()->get('fases')->toArray(), 'fases');
    //     return $fases;
    // }

    public function cover()
    {
        return A::get($this->content()->get('cover')->toArray(), 'cover.url');
    }
}
