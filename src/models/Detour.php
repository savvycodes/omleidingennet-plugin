<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

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

    public function orderByKey()
    {
        // Phase order (last to first)
        $phaseOrder = ['Afgerond', 'Verwachte werkzaamheden', 'Op de planning', 'In uitvoering'];

        $status = $this->detour_status();

        $beginDate = null;

        if ($fase = $this->fases()[0]) {
            $beginDate = A::get($fase, 'beginDate');
        } else {
            $fases = $this->fases();
            $beginDate = A::get($fases, '0.beginDate');
        }

        // Create a sort key combining phase order and date
        $phaseIndex = array_search($status, $phaseOrder);
        $dateKey = $beginDate ? strtotime($beginDate) : 0;

        return $phaseIndex . '-' . $dateKey;
    }
}
