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
        if (!$fases || !(is_array($fases))) return []; // Return empty array if fases is not set or not an array

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

        $status = $this->current_status();

        $beginDate = null;
        if (count($this->fases()) === 0) return '9999-0'; // No phases available
        

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
                $start = A::get($fase, 'beginDate') ? $this->localDate(A::get($fase, 'beginDate')) : null;
                $start = $start ? $start->format('j-m-Y') : null;
            }

            $end = A::get($fase, 'endDateAlternative');
            if (!$end) {
                $end = A::get($fase, 'endDate') ? $this->localDate(A::get($fase, 'endDate')) : null;
                $end = $end ? $end->format('j-m-Y') : null;
            }
        } else {
            $fases = $this->fases();

            // if first fase has a textual beginDateAlternative, use that
            $start = A::get(A::first($fases), 'beginDateAlternative');

            // if last fase has a textual endDateAlternative, use that
            $end = A::get(A::last($fases), 'endDateAlternative');

            // Loop through fases to find the earliest and latest 
            if (!$start || !$end) {
                $earliest = null;
                $latest = null;

                foreach ($fases as $fase) {
                    $beginDateString = A::get($fase, 'beginDate');
                    $endDateString = A::get($fase, 'endDate');

                    if ($beginDateString) {
                        $date = $this->localDate($beginDateString);

                        if (!$earliest || $date < $earliest) {
                            $earliest = $date;
                        }
                    }

                    if ($endDateString) {
                        $date = $this->localDate($endDateString);

                        if (!$latest || $date > $latest) {
                            $latest = $date;
                        }
                    }
                }

                // If no textual start or textual end was set, use the earliest and latest dates found
                if (!$start) {
                    $start = $earliest ? $earliest->format('j-m-Y') : null;
                }
                if (!$end) {
                    $end = $latest ? $latest->format('j-m-Y') : null;
                }
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
        if (count($this->fases()) === 0) return null; // No phases available
        
        $first_fase = A::first($this->fases());
        if (empty($first_fase['beginDateAlternative']) && empty($first_fase['beginDate'])) {
            return null;
        }

        if (!empty($first_fase['beginDateAlternative'])) {
            return $first_fase['beginDateAlternative'];
        }

        return $this->localDate($first_fase['beginDate'])->format('j-m-Y');
    }

    public function endDate()
    {
        if (count($this->fases()) === 0) return null; // No phases available

        $fase = A::last($this->fases());

        if (empty($fase['endDateAlternative']) && empty($fase['endDate'])) {
            return null;
        }

        if (!empty($fase['endDateAlternative'])) {
            return $fase['endDateAlternative'];
        }

        return $this->localDate($fase['endDate'])->format('j-m-Y');
    }

    private function localDate(string $date): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
    }
}
