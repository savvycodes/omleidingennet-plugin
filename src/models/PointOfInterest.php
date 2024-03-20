<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class PointOfInterestPage extends Page
{
    public function cover()
    {
        return A::get($this->content()->get('cover')->toArray(), 'cover.url');
    }
}
