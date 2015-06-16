<?php
require_once 'vendor/pear/console_table/Table.php';
if (isset($argv[1]) === false) {
    die('plz input database name' . PHP_EOL);
}

$working_dir = "./";
if (isset($argv[2]) === true) {
    $working_dir = $argv[2];
}
if (!preg_match('/\/$/', $working_dir)) {
    $working_dir .= '/';
}

exec("cat ${working_dir}mysql-slowquery* | grep -v timeline | grep -v 'use ${argv[1]}' | grep -v 'SET timestamp=' | grep SELECT -B 1 > /tmp/slow-query.log");

$fp = fopen('/tmp/slow-query.log', 'r');
if ($fp === false) {
    die('could not file open' . PHP_EOL);
}

$box = [];

while ($meta = trim(fgets($fp))) {
    $query = trim(fgets($fp));
    $line = fgets($fp);

    $query = preg_replace('/[0-9]+/', '...', $query);

    preg_match('/Query_time: (\d+\.\d+).+Rows_sent: (\d+).+Rows_examined: (\d+)/', $meta, $match);
    list($dummy, $query_time, $row_sent, $row_examined) = $match;
    $query_time = (float)$query_time;
    $row_sent = (int)$row_sent;
    $row_examined = (int)$row_examined;

    $key = md5($query);
    if (isset($box[$key]) === true) {
        foreach (['query_time', 'row_sent', 'row_examined'] as $k) {
            $box[$key][$k] = [
                'ave' => (($box[$key][$k]['ave'] * $box[$key]['count']) + $$k) / ($box[$key]['count'] + 1),
                'max' => max($box[$key][$k]['max'], $$k),
                'min' => min($box[$key][$k]['min'], $$k),
            ];
        }
        $box[$key]['count']++;
    } else {
        $box[$key] = [
            'query' => $query,
            'count' => 1,
            'query_time' => [
                'ave' => $query_time,
                'max' => $query_time,
                'min' => $query_time,
            ],
            'row_sent' => [
                'ave' => $row_sent,
                'max' => $row_sent,
                'min' => $row_sent,
            ],
            'row_examined' => [
                'ave' => $row_examined,
                'max' => $row_examined,
                'min' => $row_examined,
            ],
        ];
    }
}
usort($box, function($a, $b) {
    if ($a['count'] == $b['count']) {
        return 0;
    }
    return ($a['count'] > $b['count']) ? -1 : 1;
});


$tbl = new Console_Table();
$tbl->setHeaders([
    'id',
    'count',
    'time(ave)',
    'time(min)',
    'time(max)',
    'sent(ave)',
    'sent(min)',
    'sent(max)',
    'rows(ave)',
    'rows(min)',
    'rows(max)'
]);
$i = 0;
foreach ($box as $record) {
    $tbl->addRow([
        ++$i,
        $record['count'],
        round($record['query_time']['ave'], 4),
        round($record['query_time']['min'], 4),
        round($record['query_time']['max'], 4),
        round($record['row_sent']['ave']),
        round($record['row_sent']['min']),
        round($record['row_sent']['max']),
        round($record['row_examined']['ave']),
        round($record['row_examined']['min']),
        round($record['row_examined']['max'])
    ]);
}
echo $tbl->getTable() . PHP_EOL;

echo "=== Queries === ".PHP_EOL;
$i = 0;
foreach ($box as $record) {
    echo ++$i . ' : ' . $record['query'] . PHP_EOL . PHP_EOL;
}

exec("rm -f /tmp/slow-query.log");
