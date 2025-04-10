<?php
namespace Site\Test;

require_once __DIR__.'/../../../autoload.php';

use Site\Lib\Tester;
use Site\Lib\Mysql;

$test = new Tester([
  'colorOutput' => true,
  'verboseOutput' => true
]);

$test->describe('パケットのデバッグ', function($test) {
  try {
    $db = new Mysql();

    $db->setDebug(true);
    $db->connect();

    $result = $db->query('SELECT * FROM user WHERE id = 1');

    foreach ($result['rows'] as $row) {
      echo "ユーザー名: ".$row['nickname']."\n";
    }

    $db->savePacketLogToFile('mysql_log.txt');
    $db->close();
  } catch (\Exception $e) {
    echo 'エラー: '.$e->getMessage()."\n";
  }
});

$test->describe('プリペアドステートメント', function($test) {
  try {
    $db = new Mysql();
    $db->connect();

    // データの入り
    $stmt = $db->prepare('INSERT INTO users (name, age) VALUES (?, ?)');
    $test->assertTrue($stmt);

    $db->execute($stmt, ['山田太郎', 25]);
    // TODO: assert

    $close = $db->demolish($stmt);
    $this->assertTrue($close);

    // データの受け取り
    $stmt = $db->prepare('SELECT * FROM users WHERE age > ?');
    $test->assertTrue($stmt);

    $res = $db->execute($stmt, [20]);
    // TODO: assert
    print_r($res);

    $close = $db->demolish($stmt);
    $this->assertTrue($close);

    $db->close();
  } catch (\Exception $e) {
    echo 'エラー: '.$e->getMessage()."\n";
  }
});