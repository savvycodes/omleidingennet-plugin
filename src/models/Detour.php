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

    public function period($fase = null)
    {
        $start = null;
        $end = null;

        if ($fase) {
            $start = A::get($fase, 'beginDate');
            $end = A::get($fase, 'endDate');
        } else {
            $fases = $this->fases();

            $start = A::get($fases, '0.beginDate');
            $end = A::get(A::last($fases), 'endDate');
        }

        // Convert UTC dates to local timezone
        $timezone = new \DateTimeZone('Europe/Amsterdam');
        $startDateTime = $start ? new \DateTime($start, new \DateTimeZone('UTC')) : null;
        $endDateTime = $end ? new \DateTime($end, new \DateTimeZone('UTC')) : null;

        if ($startDateTime) {
            $startDateTime->setTimezone($timezone);
        }
        if ($endDateTime) {
            $endDateTime->setTimezone($timezone);
        }

        // if start, but no end, return start
        if (!empty($startDateTime) && empty($endDateTime)) {
            return $startDateTime->format('j-m-Y');
        }

        if (empty($startDateTime) || empty($endDateTime)) {
            return 'Nader te bepalen datum';
        }

        // if start and end are the same day, return start
        if ($startDateTime->format('j-m-Y') === $endDateTime->format('j-m-Y')) {
            return $startDateTime->format('j-m-Y');
        }

        return $startDateTime->format('j-m-Y') . ' t/m ' . $endDateTime->format('j-m-Y');
    }
}
