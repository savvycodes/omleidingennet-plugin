<?php

require dirname(__DIR__) . '/kirby/bootstrap.php';

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Omleidingennet\Plugin\DetoursPage;
use Omleidingennet\Plugin\PointOfInterestsPage;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function command(array $command, string $cwd): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('Could not start CLI');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

$plugin = dirname(__DIR__);
$root = sys_get_temp_dir() . '/omleidingennet-integration-' . bin2hex(random_bytes(8));
$server = null;
mkdir($root, 0700);
try {
    foreach (['site/config', 'site/plugins', 'content/home', 'content/one/1_local', 'content/two/1_other', 'content/locations/1_local-poi', 'content/places/1_other-poi'] as $directory) {
        Dir::make($root . '/' . $directory);
    }
    file_put_contents($root . '/content/site.txt', "Title: Test site\n");
    file_put_contents($root . '/content/home/home.txt', "Title: Home\n");
    file_put_contents($root . '/content/one/detours.txt', "Title: One\n");
    file_put_contents($root . '/content/two/detours.txt', "Title: Two\n");
    file_put_contents($root . '/content/one/1_local/detour.txt', "Title: Local\n");
    file_put_contents($root . '/content/two/1_other/detour.txt', "Title: Other\n");
    file_put_contents($root . '/content/locations/point-of-interests.txt', "Title: Locations\n");
    file_put_contents($root . '/content/places/point-of-interests.txt', "Title: Places\n");
    file_put_contents($root . '/content/locations/1_local-poi/point-of-interest.txt', "Title: Local POI\n");
    file_put_contents($root . '/content/places/1_other-poi/point-of-interest.txt', "Title: Other POI\n");
    symlink($plugin, $root . '/site/plugins/omleidingennet');
    // Select an available loopback port; the child server binds it immediately.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    copy(__DIR__ . '/mock-api.php', $root . '/mock-api.php');
    $server = proc_open([PHP_BINARY, '-S', $address, $root . '/mock-api.php'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a'],
    ], $pipes, $root);
    if (!is_resource($server)) throw new RuntimeException('Could not start mock API');
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Local credential-free API fixture ready');
    $config = [
        'debug' => false,
        'omleidingennet.plugin' => ['url' => 'http://' . $address, 'organisationId' => 'test-org', 'clientId' => 'test-client', 'clientSecret' => 'synthetic-secret'],
    ];
    file_put_contents($root . '/site/config/config.php', '<?php return ' . var_export($config, true) . ';');
    file_put_contents($root . '/index.php', '<?php require ' . var_export($plugin . '/kirby/bootstrap.php', true) . '; return new Kirby\\Cms\\App(["roots" => ["index" => __DIR__]]);');
    $app = new App(['roots' => ['index' => $root], 'urls' => ['index' => 'https://test.invalid']]);
    check($app->site()->find('one') instanceof DetoursPage, 'Clean Kirby loads registered detours model');
    $one = $app->site()->find('one');
    $two = $app->site()->find('two');
    check($one->subpages()->first()->parent()->id() === 'one', 'First detour parent preserved');
    check($two->subpages()->first()->slug() === 'other' && $two->subpages()->first()->parent()->id() === 'two', 'Detour subpages isolated by instance');
    $locations = $app->site()->find('locations');
    $places = $app->site()->find('places');
    check($locations instanceof PointOfInterestsPage, 'Clean Kirby loads registered POI model');
    check($locations->subpages()->first()->slug() === 'local-poi' && $places->subpages()->first()->slug() === 'other-poi', 'POI subpages isolated by instance');
    foreach (['pages/detours', 'pages/detour', 'pages/point-of-interests', 'pages/point-of-interest'] as $name) {
        check(is_file($app->extension('blueprints', $name)), $name . ' blueprint exists');
    }
    check(json_decode((new DetoursPage(['slug' => 'detours']))->apiDetours()->body(), true)['detours'] === [], 'Cold public endpoint does not call upstream');
    App::destroy();
    $cli = [PHP_BINARY, $plugin . '/vendor/bin/kirby', 'omleidingennet:refresh-cache'];
    [$status, $output] = command($cli, $root);
    check($status === 0, 'Native Kirby CLI refresh succeeds: ' . trim($output));
    $app = new App(['roots' => ['index' => $root], 'urls' => ['index' => 'https://test.invalid']]);
    $detours = new DetoursPage(['slug' => 'detours', 'template' => 'detours']);
    $pois = new PointOfInterestsPage(['slug' => 'pois', 'template' => 'point-of-interests']);
    $data = json_decode($detours->apiDetours()->body(), true);
    check(count($data['detours']) === 1 && $data['detours'][0]['id'] === 'detour-one', 'HTTP namespace reads native CLI detour cache');
    check($detours->apiDetour('missing')->code() === 404, 'Unknown detour returns 404');
    check(json_decode($pois->apiPointOfInterests()->body(), true)['pois'][0]['id'] === 'poi-one', 'HTTP namespace reads native CLI POI cache');
    check($pois->children()->first() instanceof Omleidingennet\Plugin\PointOfInterestPage, 'Virtual POI child resolves correct model');
    check((new Omleidingennet\Plugin\Organisation())->apiOrganisation()->code() === 200, 'Organisation JSON response works');
    file_put_contents($root . '/fail', '1');
    [$status, $output] = command($cli, $root);
    check($status !== 0, 'Native Kirby CLI exits nonzero on upstream failure');
    check(str_contains($output, 'detours:') && str_contains($output, 'organisation:'), 'Native CLI reports individual failed datasets');
    check(!str_contains($output, 'synthetic upstream failure'), 'CLI does not expose upstream response bodies');
    App::destroy();
    $app = new App(['roots' => ['index' => $root], 'urls' => ['index' => 'https://test.invalid']]);
    check(json_decode((new DetoursPage(['slug' => 'detours']))->apiDetours()->body(), true)['detours'][0]['id'] === 'detour-one', 'Public reads retain last complete data after failed native refresh');
    echo "All Kirby integration tests passed.\n";
} finally {
    App::destroy();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    // Only remove the unique test fixture, never follow the plugin symlink.
    if (is_link($root . '/site/plugins/omleidingennet')) unlink($root . '/site/plugins/omleidingennet');
    Dir::remove($root);
}
