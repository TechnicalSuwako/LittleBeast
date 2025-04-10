<?php
namespace Site\Test;

require_once __DIR__.'/../../../autoload.php';

use Site\Lib\Tester;
use Site\Lib\Curl;

$test = new Tester([
  'colorOutput' => true,
  'verboseOutput' => true
]);

$test->describe('Curlの基本的な機能性', function($test) {
  $test->it('URLで使って新しいインスタンスを作成するはず', function($test) {
    $curl = new Curl('https://076.moe');
    $test->assertNotNull($curl);
  });

  $test->it('メソッドでURLを設定出来るはす', function($test) {
    $curl = new Curl();
    $curl->setUrl('https://076.moe');
    $test->assertNotNull($curl);
  });

  $test->it('ヘッダー文字を作成出来るはず', function($test) {
    $curl = new Curl('https://076.moe');
    $curl->setHeaders([
      'Accept' => 'application/json',
      'User-Agent' => 'LoliTest/1.0'
    ]);

    $reflectionClass = new \ReflectionClass($curl);
    $method = $reflectionClass->getMethod('buildHeaderString');
    $method->setAccessible(true);

    $headerString = $method->invoke($curl);
    $test->assertStringContains('Accept: application/json', $headerString);
    $test->assertStringContains('User-Agent: LoliTest/1.0', $headerString);
  });

  $test->skip('移転を対応するはず', '作成中・・・');

  $test->it('メソッドチェーニングを対応するはず', function($test) {
    $curl = new Curl();
    $result = $curl->setUrl('https://076.moe')
      ->setMethod('GET')
      ->setTimeout(30);

    $test->assertSame($curl, $result);
  });
});

$test->describe('Curl HTTP リクエスト', function($test) {
  $networkAvailable = true;

  if (!$networkAvailable) {
    $test->skip('076.moeでGETリクエストの確認', 'ネットワークが無効です。');
    $test->skip('postman-echo.comでPOSTリクエストの確認', 'ネットワークが無効です。');
    return;
  }

  $test->it('076.moeでGETリクエストの確認', function($test) {
    $curl = new Curl('https://076.moe');
    $result = $curl->execute();

    $test->assertTrue($result);
    $test->assertEquals(200, $curl->getResponseCode());
    $test->assertNotNull($curl->getResponseBody());
    $test->assertStringContains('<html', $curl->getResponseBody());
  });

  $test->it('postman-echo.comでPOSTリクエストの確認', function($test) {
    $curl = new Curl();
    $curl->setUrl('https://postman-echo.com/post')
       ->setMethod('POST')
       ->setPostFields([
         'name' => '山田太郎',
         'email' => 't.yamada@example.com'
       ]);

    $result = $curl->execute();
    $test->assertTrue($result);
    $test->assertEquals(200, $curl->getResponseCode());

    $responseBody = $curl->getResponseBody();
    $test->assertStringContains('山田太郎', $responseBody);
    $test->assertStringContains('t.yamada@example.com', $responseBody);
  });
});

$test->printSummary();