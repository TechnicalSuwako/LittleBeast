<?php
namespace Site\Lib;

/**
 * 軽量なユニットテストフレームワーク
 */
class Tester {
  // テスト統計
  private int $testCount = 0;
  private int $passCount = 0;
  private int $failCount = 0;
  private int $errorCount = 0;

  // 現在のテストケース情報
  private string $currentTestCase = '';
  private string $currentTest = '';

  // テストスイート設定
  private bool  $colorOutput = true;
  private bool  $verboseOutput = true;
  private bool  $stopOnFailure = false;
  private array $beforeEachCallbacks = [];
  private array $afterEachCallbacks = [];
  private array $beforeAllCallbacks = [];
  private array $afterAllCallbacks = [];

  // ターミナル出力用の色
  private array $colors = [
    'reset'   => "\033[0m",
    'red'     => "\033[31m",
    'green'   => "\033[32m",
    'yellow'  => "\033[33m",
    'blue'    => "\033[34m",
    'magenta' => "\033[35m",
    'cyan'    => "\033[36m",
    'white'   => "\033[37m",
    'bold'    => "\033[1m",
  ];

  private array $failures = [];
  private array $errors = [];

  /**
   * コンストラクタ
   *
   * @param array $options  設定オプション
   */
  public function __construct(array $options = []) {
    // オプションを設定
    if (isset($options['colorOutput'])) {
      $this->colorOutput = (bool)$options['colorOutput'];
    }

    if (isset($options['verboseOutput'])) {
      $this->verboseOutput = (bool)$options['verboseOutput'];
    }

    if (isset($options['stopOnFailure'])) {
      $this->stopOnFailure = (bool)$options['stopOnFailure'];
    }

    // サポートされていない場合は色を無効にする
    if (PHP_SAPI !== 'cli' || strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'
        && !getenv('ANSICON')) {
      $this->colorOutput = false;
    }
  }

  /**
   * 各テストの前に実行する関数を登録する
   * 
   * @param callable $callback  コールバック関数
   * @return Tester  このインスタンス
   */
  public function beforeEach(callable $callback): Tester {
    $this->beforeEachCallbacks[] = $callback;
    return $this;
  }

  /**
   * 各テストの後に実行する関数を登録する
   * 
   * @param callable $callback  コールバック関数
   * @return Tester  このインスタンス
   */
  public function afterEach(callable $callback): Tester {
    $this->afterEachCallbacks[] = $callback;
    return $this;
  }

  /**
   * すべてのテストの前に実行する関数を登録する
   * 
   * @param callable $callback  コールバック関数
   * @return Tester  このインスタンス
   */
  public function beforeAll(callable $callback): Tester {
    $this->beforeAllCallbacks[] = $callback;
    return $this;
  }

  /**
   * すべてのテストの後に実行する関数を登録する
   * 
   * @param callable $callback  コールバック関数
   * @return Tester  このインスタンス
   */
  public function afterAll(callable $callback): Tester {
    $this->afterAllCallbacks[] = $callback;
    return $this;
  }

  /**
   * テストケースを定義する
   * 
   * @param string $description  テストケースの説明
   * @param callable $callback  テストケース関数
   * @return Tester このインスタンス
   */
  public function describe(string $description, callable $callback): Tester {
    $this->currentTestCase = $description;
    $this->output($this->colorize('bold', "テストケース: {$description}"));

    try {
      foreach ($this->beforeAllCallbacks as $before) {
        call_user_func($before);
      }

      call_user_func($callback, $this);

      foreach ($this->afterAllCallbacks as $after) {
        call_user_func($after);
      }
    } catch (\Throwable $e) {
      $this->recordError(
        "テストケースのセットアップ/ティアダウンでエラー: ".$e->getMessage(),
        $e->getTraceAsString());
    }

    $this->output('');
    return $this;
  }

  /**
   * 単一のテストを実行する
   * 
   * @param string $description  テストの説明
   * @param callable $callback  テスト関数
   * @return Tester  このインスタンス
   */
  public function it(string $description, callable $callback): Tester {
    $this->currentTest = $description;
    $this->testCount++;

    if ($this->verboseOutput) {
      $this->output("  ⋄ テスト中: {$description}... ", false);
    }

    try {
      foreach ($this->beforeEachCallbacks as $before) {
        call_user_func($before);
      }

      call_user_func($callback, $this);

      foreach ($this->afterEachCallbacks as $after) {
        call_user_func($after);
      }

      // Test has passed.
      $this->passCount++;

      if ($this->verboseOutput) {
        $this->output($this->colorize('green', "合格"));
      }
    } catch (AssertionFailedException $e) {
      $this->failCount++;

      if ($this->verboseOutput) {
        $this->output($this->colorize('red', "失敗"));
        $this->output($this->colorize('red', "    → ".$e->getMessage()));
      }

      $this->recordFailure($e->getMessage());

      if ($this->stopOnFailure) {
        $this->printSummary();
        exit(1);
      }
    } catch (\Throwable $e) {
      $this->errorCount++;

      if ($this->verboseOutput) {
        $this->output($this->colorize('yellow', "エラー"));
        $this->output($this->colorize('yellow', "    → ".$e->getMessage()));
      }

      $this->recordError($e->getMessage(), $e->getTraceAsString());

      if ($this->stopOnFailure) {
        $this->printSummary();
        exit(1);
      }
    }

    return $this;
  }

  /**
   * テストをスキップする
   * 
   * @param string $description  テストの説明
   * @param string $reason  スキップする理由。デフォルト: "まだ実装されていません"
   * @return Tester  このインスタンス
   */
  public function skip(string $description,
                       string $reason = 'まだ実装されていません'): Tester {
    if ($this->verboseOutput) {
      $this->output("  ⋄ スキップ: {$description}... "
        .$this->colorize('cyan', "スキップ"));
      $this->output($this->colorize('cyan', "    → {$reason}"));
    }

    return $this;
  }

  /**
   * 条件がtrueである事をアサートする
   * 
   * @param bool $condition  チェックする条件
   * @param string $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertTrue(bool $condition,
                string $message = '条件がtrueであることを期待しました'): Tester {
    if ($condition !== true) {
      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 条件がfalseである事をアサートする
   * 
   * @param bool $condition  チェックする条件
   * @param string $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertFalse(bool $condition,
                string $message = '条件がfalseであることを期待しました'): Tester {
    if ($condition !== false) {
      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 二つの値が等しい事をアサートする
   * 
   * @param mixed $expected  期待値
   * @param mixed $actual  実際の値
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertEquals(mixed $expected, mixed $actual,
                               ?string $message = null): Tester {
    if ($expected != $actual) {
      if ($message === null) {
        $expected = $this->exportValue($expected);
        $actual = $this->exportValue($actual);
        $message = "{$expected}を期待しましたが、{$actual}が得られました";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 二つの値が同一である事をアサートする
   * 
   * @param mixed $expected  期待値
   * @param mixed $actual  実際の値
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertSame(mixed $expected, mixed $actual,
                             ?string $message = null): Tester {
    if ($expected !== $actual) {
      if ($message === null) {
        $expected = $this->exportValue($expected);
        $actual = $this->exportValue($actual);
        $message =
          "{$expected}を期待しましたが、{$actual}が得られました（厳密な比較）";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 値がnullである事をアサートする
   * 
   * @param mixed $actual  チェックする値
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertNull(mixed $actual, ?string $message = null): Tester {
    if ($actual !== null) {
      if ($message === null) {
        $actual = $this->exportValue($actual);
        $message = "nullを期待しましたが、{$actual}が得られました";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 値がnullでない事をアサートする
   * 
   * @param mixed $actual  チェックする値
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertNotNull(mixed $actual, ?string $message = null): Tester {
    if ($actual === null) {
      if ($message === null) {
        $message = "値がnullでない事を期待しました";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 値が特定のキーを持つ事をアサートする
   * 
   * @param mixed $key  チェックするキー
   * @param array $array  チェックする配列
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertArrayHasKey(mixed $key, array $array,
                                    ?string $message = null): Tester {
    if (!is_array($array) && !($array instanceof \ArrayAccess)) {
      throw new AssertionFailedException(
        '第2引数は配列又はArrayAccessを実装している必要があります');
    }

    if (!array_key_exists($key, $array)) {
      if ($message === null) {
        $message = "配列がキー '{$key}' を持つ事を期待しました";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * 文字列がサブ文字列を含むことをアサートする
   * 
   * @param string $needle  検索するサブ文字列
   * @param string $haystack  検索対象の文字列
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertStringContains(string $needle, string $haystack,
                                       ?string $message = null): Tester {
    if (!is_string($needle) || !is_string($haystack)) {
      throw new AssertionFailedException('両方の引数は文字列である必要があります');
    }

    if (strpos($haystack, $needle) === false) {
      if ($message === null) {
        $message = "文字列 '{$haystack}' が '{$needle}' を含む事を期待しました";
      }

      throw new AssertionFailedException($message);
    }

    return $this;
  }

  /**
   * コールバックが例外をスローする事をアサートする
   * 
   * @param callable $callback  実行するコールバック
   * @param string $exceptionClass  期待される例外クラス
   * @param string|null $message  失敗時のオプションメッセージ
   * @throws AssertionFailedException  アサーションが失敗した場合
   * @return Tester  このインスタンス
   */
  public function assertThrows(callable $callback, string $exceptionClass,
                               ?string $message = null): Tester {
    try {
      call_user_func($callback);

      if ($message === null) {
        $message = "'{$exceptionClass}' 型の例外がスローされる事を期待しましたが、スローされませんでした";
      }

      throw new AssertionFailedException($message);
    } catch (\Throwable $e) {
      if (!($e instanceof $exceptionClass)) {
        if ($message === null) {
          $message = "'{$exceptionClass}' 型の例外を期待しましたが、"
            .get_class($e)." が得られました";
        }

        throw new AssertionFailedException($message);
      }
    }

    return $this;
  }

  /**
   * Print a summary of the test results
   * 
   * @return Tester
   */
  public function printSummary(): Tester {
    $this->output('');
    $this->output($this->colorize('bold', "テスト結果の概要:"));
    $this->output("  テスト総数: {$this->testCount}");
    $this->output("  ".$this->colorize('green', "合格: {$this->passCount}"));

    if ($this->failCount > 0) {
      $this->output("  ".$this->colorize('red', "失敗: {$this->failCount}"));
    } else {
      $this->output("  失敗: 0");
    }

    if ($this->errorCount > 0) {
      $this->output("  ".$this->colorize('yellow', "エラー: {$this->errorCount}"));
    } else {
      $this->output("  エラー: 0");
    }

    $this->output('');

    // 失敗を書き出す
    if (count($this->failures) > 0) {
      $this->output($this->colorize('bold', "失敗:"));

      foreach ($this->failures as $i => $f) {
        $num = $i + 1;
        $this->output("  {$num}) {$f['testCase']} → {$f['test']}");
        $this->output("    ".$this->colorize('red', $f['message']));
        $this->output('');
      }
    }

    // エラーを書き出す
    if (count($this->errors) > 0) {
      $this->output($this->colorize('bold', "エラー:"));

      foreach ($this->errors as $i => $e) {
        $num = $i + 1;
        $this->output("  {$num}) {$e['testCase']} → {$e['test']}");
        $this->output("    ".$this->colorize('yellow', $e['message']));

        if (isset($e['trace'])) {
          $this->output("    ".$this->colorize('yellow', "スタックトレース:"));
          $this->output("    ".$this->colorize('yellow', $e['trace']));
        }

        $this->output('');
      }
    }

    if ($this->failCount === 0 && $this->errorCount === 0) {
      $this->output($this->colorize('green', "全てのテストに合格しました！"));
    } else {
      $this->output($this->colorize('red', "テストが失敗・エラーで完了しました。"));
    }

    return $this;
  }

  // 機能性メソッド

  /**
   * コンソールにテキストを出力する
   * 
   * @param string $text  出力するテキスト
   * @param bool $newline  改行を追加するかどうか
   * @return void
   */
  private function output(string $text, bool $newline = true): void {
    echo $text.($newline ? PHP_EOL : '');
  }

  /**
   * 有効な場合はテキストに色を適用する
   * 
   * @param string $color  色名
   * @param string $text  色付けするテキスト
   * @return string
   */
  private function colorize(string $color, string $text): string {
    if (!$this->colorOutput || !isset($this->colors[$color])) {
      return $text;
    }

    return $this->colors[$color].$text.$this->colors['reset'];
  }

  /**
   * 値を表示用の文字列としてエクスポートする
   * 
   * @param mixed $value  エクスポートする値
   * @return string
   */
  private function exportValue(mixed $value): string {
    if (is_null($value)) return 'null';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_array($value)) return 'Array('.count($value).')';
    if (is_object($value)) return get_class($value).' Object';

    if (is_string($value)) {
      if (strlen($value) > 40) {
        return "'".substr($value, 0, 37)."...'";
      }

      return "'{$value}'";
    }

    return (string)$value;
  }

  /**
   * テストの失敗を記録する
   * 
   * @param string $message  失敗メッセージ
   * @return void
   */
  private function recordFailure(string $message): void {
    $this->failures[] = [
      'testCase' => $this->currentTestCase,
      'test' => $this->currentTest,
      'message' => $message,
    ];
  }

  /**
   * テストのエラーを記録する
   * 
   * @param string $message  エラーメッセージ
   * @param string|null $trace  スタックトレース
   * @return void
   */
  private function recordError(string $message, ?string $trace = null): void {
    $this->errors[] = [
      'testCase' => $this->currentTestCase,
      'test' => $this->currentTest,
      'message' => $message,
      'trace' => $trace,
    ];
  }
}

/**
 * アサーション失敗用のカスタム例外
 */
class AssertionFailedException extends \Exception {
}