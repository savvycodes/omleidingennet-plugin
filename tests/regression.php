<?php

// Deliberately no Kirby/Sentry/network dependency: exercise every failure path
// with deterministic synthetic responses and in-memory caches.
namespace Kirby\Http {
    class Remote
    {
        public static array $queue = [];
        public static array $requests = [];

        public static function post($url, $options)
        {
            self::$requests[] = [$url, $options];
            $next = array_shift(self::$queue);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            if ($next === null) {
                throw new \RuntimeException('Unexpected HTTP request');
            }
            return new class($next) {
                public function __construct(private array $response) {}
                public function code() { return $this->response[0]; }
                public function json() { return $this->response[1]; }
            };
        }
    }
}

namespace Kirby\Cms {
    class Page
    {
        public function __construct(array $props = []) {}
    }
    class Pages {}
}

namespace Kirby\Exception {
    class InvalidArgumentException extends \InvalidArgumentException {}
}

namespace {
    use Kirby\Http\Remote;
    use Omleidingennet\Plugin\Api;
    use Omleidingennet\Plugin\Cache;
    use Omleidingennet\Plugin\DetoursPage;
    use Omleidingennet\Plugin\Organisation;
    use Omleidingennet\Plugin\PointOfInterestsPage;
    use Omleidingennet\Plugin\RefreshCache;
    use Omleidingennet\Plugin\Token;
    use Omleidingennet\Plugin\Traveler;

    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    class MemoryCache
    {
        public array $values = [];
        public array $ttls = [];
        public bool $writable = true;
        public function get($key) { return $this->values[$key] ?? null; }
        public function set($key, $value, $ttl = 0) {
            if (!$this->writable) return false;
            $this->values[$key] = $value;
            $this->ttls[$key] = $ttl;
            return true;
        }
    }
    class MockApp
    {
        public array $caches = [];
        public array $body = [];
        public string $root;
        public function cache($name) { return $this->caches[$name] ??= new MemoryCache(); }
        public function root($name) { return $this->root; }
        public function request() { return $this; }
        public function body() { return $this; }
        public function get($name) { return $this->body[$name] ?? null; }
    }
    $app = new MockApp();
    $app->root = sys_get_temp_dir() . '/omleidingennet-regression-' . bin2hex(random_bytes(8));
    mkdir($app->root, 0700);
    $options = [
        'url' => 'https://api.example.invalid', 'organisationId' => 'test-org',
        'clientId' => 'test-client', 'clientSecret' => 'synthetic-secret',
    ];
    $params = [];
    function kirby() { return $GLOBALS['app']; }
    function option($name) { return $GLOBALS['options'][substr($name, strlen('omleidingennet.plugin.'))] ?? null; }
    function get($name) { return $GLOBALS['params'][$name] ?? null; }
    foreach (['Cache', 'Token', 'Api', 'Traveler', 'Organisation', 'RefreshCache', 'models/Detours', 'models/PointOfInterests'] as $file) {
        require dirname(__DIR__) . '/src/' . $file . '.php';
    }
    function check($condition, $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function fails(callable $callback, string $message): void {
        try { $callback(); } catch (\RuntimeException | \Kirby\Exception\InvalidArgumentException $e) {
            check(!str_contains($e->getMessage(), 'sensitive-upstream'), $message . ' (redacted)');
            return;
        }
        throw new \RuntimeException('Expected failure: ' . $message);
    }
    function response($field, $data): array { return [200, ['data' => [$field => $data]]]; }
    function authorize(): void { kirby()->cache('omleidingennet.plugin')->set('token.' . Cache::scope(), 'synthetic-token'); }
    function callPrivate($object, $method, ...$args) {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }
    function seedCaches(): array {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backup = kirby()->cache('backup.omleidingennet.plugin');
        $cache->values = [];
        $backup->values = [];
        foreach (['detours', 'pois', 'types'] as $type) {
            $key = $type . '.' . hash('sha256', '') . option('omleidingennet.plugin.organisationId');
            $cache->set($key, [['id' => 'last-good']]);
            $backup->set('backup.' . $key, [['id' => 'last-good']]);
        }
        foreach (['areas', 'organisation'] as $type) {
            $key = $type . '.' . option('omleidingennet.plugin.organisationId');
            $cache->set($key, [['id' => 'last-good']]);
            $backup->set('backup.' . $key, [['id' => 'last-good']]);
        }
        authorize();
        return [$cache->values, $backup->values];
    }
    try {
        $cache = $app->cache('omleidingennet.plugin');
        Remote::$queue = [[200, ['access_token' => 'synthetic-token', 'expires_in' => 3600]]];
        check(Token::get() === 'synthetic-token', 'Token parsed');
        check($cache->ttls['token.' . Cache::scope()] === 59, 'Token expires one minute early');
        $before = count(Remote::$requests);
        Token::get();
        check(count(Remote::$requests) === $before, 'Cached token avoids HTTP');
        $scope = Cache::scope();
        $options['clientSecret'] = 'rotated-synthetic-secret';
        check(Cache::scope() !== $scope, 'Credential rotation changes token scope');
        Remote::$queue = [[200, ['access_token' => 'short-token', 'expires_in' => 30]]];
        check(Token::get() === 'short-token' && $cache->get('token.' . Cache::scope()) === null, 'Short-lived tokens are not cached forever');
        foreach ([[200, null], [200, ['access_token' => [], 'expires_in' => 3600]], [401, ['error' => 'sensitive-upstream']], new \RuntimeException('sensitive-upstream')] as $bad) {
            Remote::$queue = [$bad];
            fails(fn() => Token::get(), 'Invalid token/transport fails safely');
        }
        authorize();
        foreach ([[500, []], [200, null], [200, ['errors' => [['message' => 'sensitive-upstream']]]], response('items', null), new \RuntimeException('sensitive-upstream')] as $bad) {
            Remote::$queue = [$bad];
            fails(fn() => Api::request('query', [], 'items'), 'Invalid GraphQL response rejected');
        }
        Remote::$queue = [response('items', ['totalCount' => 2, 'rows' => [['id' => 'a']]]), response('items', ['totalCount' => 2, 'rows' => [['id' => 'b']]])];
        check(count(Api::paginate('query', [], 'items', 'rows', 1)) === 2, 'Pagination combines all pages');
        $last = end(Remote::$requests);
        check(json_decode($last[1]['data'], true)['variables']['filter']['skip'] === 1, 'Pagination advances by actual result count');
        foreach ([
            [response('items', ['totalCount' => 1, 'rows' => []])],
            [response('items', ['totalCount' => '1', 'rows' => [['id' => 'a']]])],
            [response('items', ['totalCount' => 100001, 'rows' => []])],
            [response('items', ['totalCount' => 1, 'rows' => [null]])],
            [response('items', ['totalCount' => 2, 'rows' => [['id' => 'a']]]), response('items', ['totalCount' => 2, 'rows' => [['id' => 'a']]])],
            [response('items', ['totalCount' => 2, 'rows' => [['id' => 'a']]]), response('items', ['totalCount' => 3, 'rows' => [['id' => 'b']]])],
        ] as $queue) {
            Remote::$queue = $queue;
            fails(fn() => Api::paginate('query', [], 'items', 'rows', 1), 'Incomplete/repeated/malformed pagination rejected');
        }
        $params['areas'] = ['bad'];
        fails(fn() => Api::filter('areas'), 'Array-valued query parameters rejected');
        $params['areas'] = str_repeat('x', 1001);
        fails(fn() => Api::filter('areas'), 'Oversized query parameters rejected');
        $params['areas'] = 'a,b';
        check(Api::filter('areas') === 'a,b', 'String filter accepted');
        $params = [];

        $detours = new DetoursPage();
        $pois = new PointOfInterestsPage();
        $organisation = new Organisation();
        foreach ([
            [$detours, 'refreshCache', 'detours'], [$detours, 'refreshAreasCache', 'areas'],
            [$pois, 'refreshCache', 'pointOfInterests'], [$pois, 'refreshTypesCache', 'pointOfInterestTypes'],
            [$organisation, 'refreshCache', 'organisation'],
        ] as [$object, $method, $field]) {
            foreach ([
                [503, []], [200, ['errors' => [['message' => 'sensitive-upstream']]]],
                response($field, $field === 'detours' || $field === 'pointOfInterests'
                    ? ['totalCount' => 1, $field === 'detours' ? 'detours' : 'pois' => [['id' => 'malformed']]]
                    : [['id' => 'malformed']]),
            ] as $bad) {
                $snapshot = seedCaches();
                Remote::$queue = [$bad];
                fails(fn() => $object->$method(), "$field refresh fails without Sentry");
                check([$cache->values, $app->cache('backup.omleidingennet.plugin')->values] === $snapshot, "$field failure preserves both caches");
            }
        }
        seedCaches();
        Remote::$queue = [response('detours', ['totalCount' => 2, 'detours' => [['id' => 'first']]]), [500, []]];
        $snapshot = [$cache->values, $app->cache('backup.omleidingennet.plugin')->values];
        fails(fn() => $detours->refreshCache(), 'Later pagination failure propagates');
        check([$cache->values, $app->cache('backup.omleidingennet.plugin')->values] === $snapshot, 'Partial page never replaces cache');
        Remote::$queue = [response('pointOfInterestTypes', [])];
        check($pois->refreshTypesCache() === [], 'Successful empty type list clears old entries');
        $key = 'types.' . hash('sha256', '') . $options['organisationId'];
        check($cache->get($key) === [] && $app->cache('backup.omleidingennet.plugin')->get('backup.' . $key) === [], 'Empty result stored in both caches');
        $key = 'pois.' . hash('sha256', '') . $options['organisationId'];
        $cache->set($key, [
            ['id' => 'a', 'name' => 'Bridge', 'description' => 'Cycle parking', 'type' => ['id' => 'bike']],
            ['id' => 'b', 'name' => 'Road', 'description' => 'Car parking', 'type' => ['id' => 'car']],
        ]);
        Remote::$queue = [];
        check(array_column(callPrivate($pois, 'pois', 'parking', 'bike'), 'id') === ['a'], 'Cached POIs filtered by search and type without HTTP');
        $cache->writable = false;
        fails(fn() => Cache::store($cache, $app->cache('backup.omleidingennet.plugin'), 'x', 'x', []), 'Cache write failure propagates');
        $cache->writable = true;

        $options['organisationId'] = 'transport-one';
        authorize();
        Remote::$queue = [response('transportation', [['id' => 'bike', 'name' => 'Bike']])];
        Traveler::transportation();
        $options['organisationId'] = 'transport-two';
        authorize();
        Remote::$queue = [response('transportation', [['id' => 'car', 'name' => 'Car']])];
        check(Traveler::transportation()[0]['id'] === 'car', 'Transportation cache is organisation-specific');
        $app->body = ['vehicle' => 'car', 'from' => ['bad'], 'to' => '52, 4'];
        fails(fn() => Traveler::check(), 'Array coordinates rejected');
        $app->body['from'] = '91, 4';
        fails(fn() => Traveler::check(), 'Out-of-range coordinates rejected');
        $app->body['from'] = '52, 4';
        Remote::$queue = [response('checkRoute', ['detours' => []])];
        check(Traveler::check() === [], 'Traveler accepts empty route result');

        $cli = new class {
            public array $messages = [];
            public function success($message) { $this->messages[] = $message; }
            public function error($message) { $this->messages[] = $message; }
        };
        Remote::$queue = array_fill(0, 5, [500, []]);
        fails(fn() => RefreshCache::run($cli), 'Refresh aggregates failures and throws');
        check(count(Remote::$queue) === 0, 'All datasets attempted despite failures');
        $locks = glob($app->root . '/*.lock');
        $handle = fopen($locks[0], 'c');
        check(flock($handle, LOCK_EX | LOCK_NB), 'Lock released after failed refresh');
        RefreshCache::run($cli);
        check(str_contains(end($cli->messages), 'already running'), 'Concurrent refresh skips atomically');
        flock($handle, LOCK_UN);
        fclose($handle);
        check(Remote::$queue === [], 'Concurrent skip makes no API requests');
        echo "All regression tests passed.\n";
    } finally {
        foreach (glob($app->root . '/*') as $file) unlink($file);
        rmdir($app->root);
    }
}
