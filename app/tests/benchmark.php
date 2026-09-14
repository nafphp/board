<?php

declare(strict_types=1);
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    throw new RuntimeException('Benchmark requires the disposable nafinity_test database.');
}
require dirname(__DIR__).'/bootstrap.php';
$c = \Naf\app()->container();
$pdo = $c->get(PDO::class);
$user = new \App\Models\User($pdo->query('SELECT * FROM users WHERE id=1')->fetch());
$c->get(\Naf\Auth\Auth::class)->setIdentity($user);
$board = $c->make(\App\Services\BoardQuery::class);
$a = $board->board(1);
$pdo->beginTransaction();
try {
    $sql = 'INSERT INTO tickets(project_id,board_id,column_id,swimlane_id,number,title,description,priority,color,status,created_by,created_at,updated_at,position,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)';
    $insert = $pdo->prepare($sql);
    for ($i = 10000;$i < 15000;$i++) {
        $insert->execute([1,$a['board']['id'],$a['columns'][0]['id'],$a['swimlanes'][0]['id'],$i,'Benchmark sunflower '.$i,'Searchable board measurement','normal','#6366f1','open',1,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$i * 65536]);
    }
    $pdo->commit();
    $result = ['driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'inserted_tickets' => 5000,'measurement' => 'BoardQuery including bounded cards, count, metadata and pivots; excludes HTTP/template/browser'];
    foreach (['unfiltered' => [],'fulltext' => ['q' => 'sunflower']] as $name => $filter) {
        $times = [];
        for ($i = 0;$i < 11;$i++) {
            $start = hrtime(true);
            $view = $board->board(1, $filter);
            if ($i) {
                $times[] = (hrtime(true) - $start) / 1e6;
            }
        }
        sort($times);
        $result[$name] = ['median_ms' => round(($times[4] + $times[5]) / 2, 2),'max_ms' => round(max($times), 2),'returned' => count($view['cards']),'total' => $view['total']];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('DELETE FROM tickets WHERE project_id=1 AND number>=10000 AND number<15000');
}
