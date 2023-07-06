<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Toolkit\A;

class DetourPage extends Page
{
    public function fases()
    {
        $fases = A::get($this->content()->get('fases')->toArray(), 'fases');
        return $fases;
    }

    public function cover()
    {
        return A::get($this->content()->get('cover')->toArray(), 'cover.url');
    }
}
