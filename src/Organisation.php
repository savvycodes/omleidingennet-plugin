<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Http\Remote;
use Kirby\Http\Response;

class Organisation
{

    private function fetchOrganisation($refresh = false)
    {
        $key = 'organisation.' . option('omleidingennet.plugin.organisationId');
        $backupKey = 'backup.organisation.' . option('omleidingennet.plugin.organisationId');
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');
        $organisation = $cache->get($key);

        if ($refresh === false) {
            if ($organisation !== null) {
                return $organisation;
            }

            $backupOrganisation = $backupCache->get($backupKey);
            if ($backupOrganisation !== null) {
                return $backupOrganisation;
            }

            $cliOrganisation = Cache::cli('omleidingennet.plugin')->get($key);
            if ($cliOrganisation !== null) {
                return $cliOrganisation;
            }

            return Cache::cli('backup.omleidingennet.plugin')->get($backupKey);
        }

        $query = <<<'GRAPHQL'
        query organisation($organisationId: String!) {
            organisation(id: $organisationId) {
                id
                geometry
            }
          }
        GRAPHQL;

        $token = Token::get();

        $res = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            'data' => json_encode([
                'query' => $query,
                'variables' => [
                    'organisationId' => option('omleidingennet.plugin.organisationId'),
                ]
            ])
        ]);

        if ($res->code() === 200) {
            $data = $res->json();
            if (isset($data['error'])) {
                throw new Exception($data['error']['message']);
            }
            if (isset($data['errors'])) {
                $errorMessages = array_map(fn($error) => $error['message'], $data['errors']);
                throw new Exception(json_encode($errorMessages));
            }

            $organisation = $data['data']['organisation'];
            // cache for ttl minutes
            if ($organisation) {
                $cache->set($key, $organisation, option('omleidingennet.plugin.ttl'));
                $backupCache->set($backupKey, $organisation);
            }

            return $organisation;
        }
        throw new Exception('Could not fetch organisation');
    }

    public function apiOrganisation()
    {
        $organisation = $this->fetchOrganisation();

        return Response::json(['organisation' => $organisation]);
    }

    public function refreshCache()
    {
        return $this->fetchOrganisation(true);
    }
}
