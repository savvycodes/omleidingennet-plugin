<?php

namespace Omleidingennet\Plugin;

use Kirby\Toolkit\Collection;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\A;

// Custom search function for detours, borrows heavily from the native search function
function searchDetours(
    Collection $detours,
    string|null $query = null,
    string|array $params = []
): Collection {
    if (is_string($params) === true) {
        $params = ['fields' => Str::split($params, '|')];
    }

    $defaults = [
        'fields'    => [],
        'minlength' => 2,
        'score'     => [],
        'words'     => false,
    ];

    $collection  = clone $detours;
    $options     = array_merge($defaults, $params);
    $query       = trim($query ?? '');

    // empty or too short search query
    if (Str::length($query) < $options['minlength']) {
        return $collection->limit(0);
    }

    $words = preg_replace('/(\s)/u', ',', $query);
    $words = Str::split($words, ',', $options['minlength']);

    if (empty($options['stopwords']) === false) {
        $words = array_diff($words, $options['stopwords']);
    }

    // returns an empty collection if there is no search word
    if (empty($words) === true) {
        return $collection->limit(0);
    }

    $words = A::map(
        $words,
        fn($value) => Str::wrap(preg_quote($value), $options['words'] ? '\b' : '')
    );

    $exact = preg_quote($query);

    if ($options['words']) {
        $exact = '(\b' . $exact . '\b)';
    }

    $query   = Str::lower($query);
    $preg    = '!(' . implode('|', $words) . ')!iu';
    $scores  = [];

    $results = $collection->filter(function ($item) use ($query, $exact, $preg, $options, &$scores) {
        $data   = $item->content()->toArray();
        $keys   = array_keys($data);
        $keys[] = 'id';


        // apply the default score for pages
        $options['score'] = array_merge(
            ['id' => 64, 'title' => 64],
            $options['score']
        );

        if (empty($options['fields']) === false) {
            $fields = array_map('strtolower', $options['fields']);
            $keys   = array_intersect($keys, $fields);
        }

        $scoring = [
            'hits'  => 0,
            'score' => 0
        ];

        foreach ($keys as $key) {
            $score = $options['score'][$key] ?? 1;
            $value = $data[$key] ?? (string)$item->$key();

            // IMPORTANT: flatten arrays
            if (is_array($value)) {
                $value = flattenArray($value);
            }

            $lowerValue = Str::lower($value);

            // check for exact matches
            if ($query == $lowerValue) {
                $scoring['score'] += 16 * $score;
                $scoring['hits']  += 1;

                // check for exact beginning matches
            } elseif (
                $options['words'] === false &&
                Str::startsWith($lowerValue, $query) === true
            ) {
                $scoring['score'] += 8 * $score;
                $scoring['hits']  += 1;

                // check for exact query matches
            } elseif ($matches = preg_match_all('!' . $exact . '!ui', $value, $r)) {
                $scoring['score'] += 2 * $score;
                $scoring['hits']  += $matches;
            }

            // check for any match
            if ($matches = preg_match_all($preg, $value, $r)) {
                $scoring['score'] += $matches * $score;
                $scoring['hits']  += $matches;
            }
        }

        $scores[$item->id()] = $scoring;

        return $scoring['hits'] > 0;
    });

    return $results->sort(
        fn($item) => $scores[$item->id()]['score'],
        'desc'
    );
}

// Flatten a multidimensional array into a string
function flattenArray($array): string
{
    $flatArray = [];

    array_walk_recursive($array, function ($value) use (&$flatArray) {
        $flatArray[] = $value;
    });

    return implode(' ', $flatArray);
}

function preferNumberedChildren(array $children): array
{
    $uniqueChildren = [];

    foreach ($children as $child) {
        $slug = $child['slug'] ?? null;
        $current = $uniqueChildren[$slug] ?? null;

        if ($current === null || (($child['num'] ?? null) !== null && ($current['num'] ?? null) === null)) {
            $uniqueChildren[$slug] = $child;
        }
    }

    return array_values($uniqueChildren);
}
