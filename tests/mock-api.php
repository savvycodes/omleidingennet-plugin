<?php

// Local-only fixture server for the native CLI integration test.
header('Content-Type: application/json');
if (is_file(__DIR__ . '/fail')) {
    http_response_code(503);
    echo json_encode(['errors' => [['message' => 'synthetic upstream failure']]]);
    return;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/oauth/token') {
    echo json_encode(['access_token' => 'synthetic-integration-token', 'expires_in' => 3600]);
    return;
}
$request = json_decode(file_get_contents('php://input'), true);
$query = $request['query'] ?? '';
$detour = [
    'id' => 'detour-one', 'slug' => 'bridge', 'name' => 'Bridge maintenance',
    'description' => 'Synthetic project', 'status' => 'In uitvoering',
    'website' => null, 'infraManager' => null, 'detourType' => ['id' => 'road', 'name' => 'Road'],
    'coverPhoto' => null, 'fases' => [[
        'id' => 'phase-one', 'name' => 'Work', 'slug' => 'work', 'status' => 'In uitvoering',
        'description' => 'Synthetic phase', 'beginDate' => '2026-10-01T00:00:00Z',
        'endDate' => '2026-11-01T00:00:00Z', 'beginDateAlternative' => null, 'endDateAlternative' => null,
        'blockedTransportations' => [], 'availableTransportations' => [], 'limitedTransportations' => [],
        'areas' => [['id' => 'area-one', 'name' => 'Centre', 'type' => 'area']],
        'detourlines' => [],
    ]],
];
$poi = [
    'id' => 'poi-one', 'name' => 'Cycle parking', 'description' => 'Synthetic POI',
    'beginDate' => null, 'endDate' => null, 'coverPhoto' => null, 'geometry' => '{}',
    'type' => ['id' => 'bike', 'name' => 'Bike', 'styling' => '{}'],
];
$data = match (true) {
    str_contains($query, 'query detours(') => ['detours' => ['totalCount' => 1, 'detours' => [$detour]]],
    str_contains($query, 'query areas(') => ['areas' => [['id' => 'area-one', 'name' => 'Centre', 'type' => 'area']]],
    str_contains($query, 'query pointOfInterests(') => ['pointOfInterests' => ['totalCount' => 1, 'pois' => [$poi]]],
    str_contains($query, 'query pointOfInterestsTypes(') => ['pointOfInterestTypes' => [['id' => 'bike', 'name' => 'Bike']]],
    str_contains($query, 'query organisation(') => ['organisation' => ['id' => 'test-org', 'geometry' => '{}']],
    default => null,
};
if ($data === null) http_response_code(400);
echo json_encode(['data' => $data]);
