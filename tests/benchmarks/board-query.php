<?php

declare(strict_types=1);

use Naf\Auth\Auth;
use Naf\Board\Models\User;
use Naf\Board\Services\BoardQuery;

// The same defaults phpunit.xml gives the suite, so this reaches its database.
foreach ([
    'APP_ENV'     => 'test',
    'APP_URL'     => 'http://127.0.0.1:38080',
    'DB_HOST'     => '127.0.0.1',
    'DB_PORT'     => '33306',
    'DB_DATABASE' => 'nafinity_test',
    'DB_USERNAME' => 'board',
    'DB_PASSWORD' => 'board',
] as $name => $default) {
    $_ENV[$name] = getenv($name) ?: $default;
    putenv($name . '=' . $_ENV[$name]);
}
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    throw new RuntimeException('The benchmark needs APP_ENV=test and DB_DATABASE=nafinity_test.');
}
require dirname(__DIR__) . '/bootstrap.php';
$c    = \Naf\app()->container();
$pdo  = $c->get(PDO::class);
$user = new User($pdo->query('SELECT * FROM users WHERE id=1')->fetch());
$c->get(Auth::class)->setIdentity($user);
$board       = $c->make(BoardQuery::class);
$a           = $board->board(1);
$insertedIds = [];
$pdo->beginTransaction();

try {
    $sql         = 'INSERT INTO tickets(project_id,board_id,column_id,swimlane_id,number,title,description,priority,color,status,created_by,created_at,updated_at,position,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)';
    $insert      = $pdo->prepare($sql);
    $firstNumber = (int) $pdo->query('SELECT COALESCE(MAX(number), 0) + 1 FROM tickets WHERE project_id=1')->fetchColumn();
    for ($i = $firstNumber; $i < $firstNumber + 5000; $i++) {
        $insert->execute([
            1,
            $a['board']['id'],
            $a['columns'][0]['id'],
            $a['swimlanes'][0]['id'],
            $i,
            'Benchmark sunflower ' . $i,
            'Searchable board measurement',
            'normal',
            '#6366f1',
            'open',
            1,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
            $i * 65536,
        ]);
        $insertedIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->commit();
    $result = [
        'driver'           => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'inserted_tickets' => 5000,
        'measurement'
            => 'BoardQuery including bounded cards, count, metadata and pivots; excludes HTTP/template/browser',
    ];
    foreach (['unfiltered' => [], 'fulltext' => ['q' => 'sunflower']] as $name => $filter) {
        $times = [];
        for ($i = 0; $i < 11; $i++) {
            $start = hrtime(true);
            $view  = $board->board(1, $filter);
            if ($i) {
                $times[] = (hrtime(true) - $start) / 1e6;
            }
        }
        sort($times);
        $result[$name] = [
            'median_ms' => round(($times[4] + $times[5]) / 2, 2),
            'p95_ms'    => round($times[(int) ceil(count($times) * 0.95) - 1], 2),
            'max_ms'    => round(max($times), 2),
            'returned'  => count($view['cards']),
            'total'     => $view['total'],
        ];
    }
    $result['peak_memory_bytes'] = memory_get_peak_usage(true);
    $result['recorded_at']       = gmdate(DATE_ATOM);
    $result['php']               = PHP_VERSION;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach (array_chunk($insertedIds, 500) as $ids) {
        $delete = $pdo->prepare('DELETE FROM tickets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $delete->execute($ids);
    }
}
