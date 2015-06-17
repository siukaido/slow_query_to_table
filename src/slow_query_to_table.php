<?php
require_once 'vendor/pear/console_table/Table.php';

define('TMP_FILE_PATH', '/tmp/slow-query.log');

try {
    $cmd = Command::build(TMP_FILE_PATH);
    echo $cmd . PHP_EOL.PHP_EOL;
    system($cmd);
    if (filesize(TMP_FILE_PATH) === 0) {
        throw new RuntimeException('could not create tmp file, ' . TMP_FILE_PATH);
    }

    $fp = fopen(TMP_FILE_PATH, 'r');
    if ($fp === false) {
        throw new RuntimeException('could not open tmp file, ' . TMP_FILE_PATH);
    }
} catch (Exception $e) {
    trigger_error($e->getMessage(), E_USER_WARNING);
    return null;
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
    'id', 'count',
    'time(ave)', 'time(min)', 'time(max)',
    'sent(ave)', 'sent(min)', 'sent(max)',
    'rows(ave)', 'rows(min)', 'rows(max)',
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

fclose($fp);
exec("rm -f /tmp/slow-query.log");


//====================================================================
//
//  Command Class
//
//====================================================================
class Command
{
    //====================================================================
    //  public static method
    //====================================================================
    public static function build($tmp_file_path)
    {
        $options = getopt('v:', ['dir:']);

        $cmd = sprintf("cat %s/mysql-slowquery*", self::_parseWorkingDir($options));

        foreach (self::_parseExcludeWord($options) as $exclude_word) {
            $cmd .= " | grep -v '${exclude_word}'";
        }

        $cmd .= " | grep -v 'SET timestamp=' | grep SELECT -B 1 > " . $tmp_file_path;
        return $cmd;
    }

    //====================================================================
    //  private static method
    //====================================================================
    private static function _parseExcludeWord($options)
    {
        $exclude_words = [];
        if (isset($options['v']) === true) {
            if (is_array($options['v'])) {
                $exclude_words   = $options['v'];
            } else {
                $exclude_words[] = $options['v'];
            }
        }
        return $exclude_words;
    }

    private static function _parseWorkingDir($options)
    {
        $working_dir = "./";
        if (isset($options['dir']) === true) {
            if (is_string($options['dir']) === false) {
                throw new InvalidArugumentException('dir argument should be string, ' . gettype($options['dir']) . ' given');
            }
            $working_dir = $options['dir'];
        }
        $working_dir = realpath($working_dir);
        if (is_dir($working_dir) === false) {
            throw new RuntimeException('could not find directory, ' . $working_dir);
        }
        return $working_dir;
    }
}
