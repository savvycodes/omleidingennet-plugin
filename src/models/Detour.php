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

    public function activeFase()
    {
        return $this->active_fase()->value();
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
            $start = A::get($fase, 'beginDateAlternative');
            if (!$start) {
                $start = A::get($fase, 'beginDate') ? new \DateTime(A::get($fase, 'beginDate'), new \DateTimeZone('Europe/Amsterdam')) : null;
                $start = $start ? $start->format('j-m-Y') : null;
            }

            $end = A::get($fase, 'endDateAlternative');
            if (!$end) {
                $end = A::get($fase, 'endDate') ? new \DateTime(A::get($fase, 'endDate'), new \DateTimeZone('Europe/Amsterdam')) : null;
                $end = $end ? $end->format('j-m-Y') : null;
            }
        } else {
            $fases = $this->fases();

            $start = A::get($fases, '0.beginDateAlternative');
            if (!$start) {
                $start = A::get($fases, '0.beginDate') ? new \DateTime(A::get($fases, '0.beginDate'), new \DateTimeZone('Europe/Amsterdam')) : null;
                $start = $start ? $start->format('j-m-Y') : null;
            }
            $end = A::get(A::last($fases), 'endDateAlternative');
            if (!$end) {
                $end = A::get(A::last($fases), 'endDate') ? new \DateTime(A::get(A::last($fases), 'endDate'), new \DateTimeZone('Europe/Amsterdam')) : null;
                $end = $end ? $end->format('j-m-Y') : null;
            }
        }

        // if start, but no end, return start
        if (!empty($start) && empty($end)) {
            return $start;
        }

        if (empty($start) || empty($end)) {
            return 'Nader te bepalen datum';
        }

        // if start and end are the same day, return start
        if ($start === $end) {
            return $start;
        }

        return $start . ' t/m ' . $end;
    }

    public function startDate()
    {
        $first_fase = A::first($this->fases());
        if (empty($first_fase['beginDateAlternative']) && empty($first_fase['beginDate'])) {
            return null;
        }

        if (!empty($first_fase['beginDateAlternative'])) {
            return $first_fase['beginDateAlternative'];
        }

        return date('j-m-Y', strtotime($first_fase['beginDate']));
    }

    public function endDate()
    {
        $fase = A::last($this->fases());

        if (empty($fase['endDateAlternative']) && empty($fase['endDate'])) {
            return null;
        }

        if (!empty($fase['endDateAlternative'])) {
            return $fase['endDateAlternative'];
        }

        return date('j-m-Y', strtotime($fase['endDate']));
    }
}
