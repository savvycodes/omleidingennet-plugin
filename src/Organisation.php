<?php

namespace Omleidingennet\Plugin;

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

        $organisation = Api::request($query, [
            'organisationId' => option('omleidingennet.plugin.organisationId'),
        ], 'organisation');
        Api::validateRecord($organisation, ['id' => 'string', 'geometry' => 'nullable']);
        Cache::store($cache, $backupCache, $key, $backupKey, $organisation);

        return $organisation;
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
