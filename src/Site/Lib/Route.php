<?php
namespace Site\Lib;

class Route {
  protected static array $routes = [];
  protected static array $fallback = [];

  /**
   * ルート設定を言語固有のハンドラで初期化する
   *
   * @param array $routes  ルート設定の配列
   * @return void
   */
  public static function init(array $routes): void {
    self::$routes = $routes;
  }

  /**
   * ルートを追加する
   *
   * @param string $method  HTTPメソッド
   * @param string $path  URLパス
   * @param string|callable $class  ハンドラクラスとメソッド、またはコールバック
   * @param array $params  オプションのパラメータ
   * @return array  ルート設定
   */
  public static function add(string $method, string $path, string|callable $class,
                             array $params = []): array {
    $route = [
      'method'  => $method,
      'path'    => $path,
      'class'   => $class,
      'params'  => $params,
    ];

    self::$routes[] = $route;
    return $route;
  }

  /**
   * 404処理用のフォールバックルートを設定する
   *
   * @param array|string|callable $class
   * @return void
   */
  public static function setFallback(array|string|callable $class): void {
    self::$fallback = [
      'class' => $class,
      'params' => [],
    ];
  }

  /**
   * 適切なルートをマッチさせて実行する
   *
   * @param string $uri  リクエストURI
   * @return void
   */
  public static function dispatch(string $uri): void {
    // URIをパスとクエリ文字列に分割
    $uriParts = explode('?', $uri, 2);
    $path = trim($uriParts[0], " \t\n\r\0\x0B/");

    // ルートパスの処理（/?page=2のようなクエリパラメータを含む場合も処理）
    if ($path === '') {
      self::executeClass([
        'class' => [new \Site\Controller\Home(), 'show'],
        'params' => ['lang' => 'ja'],
      ]);
      return;
    }

    if ($path === 'en') {
      self::executeClass([
        'class' => [new \Site\Controller\Home(), 'show'],
        'params' => ['lang' => 'en'],
      ]);
      return;
    }

    // パスに対してルートをマッチングする
    foreach (self::$routes as $route) {
      $matches = [];

      if (self::matchRoute($route['path'], $path, $matches)) {
        $params = self::extractParams($route['path'], $path);
        $params = array_merge($route['params'], $params);

        if (is_string($route['class'])) {
          [ $class, $method ] = explode('@', $route['class']);
          $controller = new $class();
          self::executeClass([
            'class' => [ $controller, $method ],
            'params' => $params,
          ]);

          return;
        } elseif (is_callable($route['class'])) {
          self::executeClass([
            'class' => $route['class'],
            'params' => $params,
          ]);

          return;
        }
      }
    }

    // マッチするルートがない場合、フォールバックを実行
    self::executeClass(self::$fallback);
  }

  /**
   * ルートパターンとパスをマッチングする
   *
   * @param string $pattern  ルートパターン
   * @param string $path  現在のパス
   * @param array $matches  マッチを格納する参照
   * @return bool
   */
  protected static function matchRoute(string $pattern, string $path,
                                       array &$matches = []): bool {
    // ルートパターンを正規表現パターンに変換
    $pattern = preg_replace('/\{([^:}]+)(?::([^}]+))?\}/', '(?P<$1>[^/]+)', $pattern);
    $pattern = str_replace('/', '\/', $pattern);
    return (bool)preg_match('/^'.$pattern.'$/', $path, $matches);
  }

  /**
   * パターンに基づいてパスから名前付きパラメータを抽出する
   *
   * @param string $pattern  ルートパターン
   * @param string $path  現在のパス
   * @return array
   */
  protected static function extractParams(string $pattern, string $path): array {
    $params = [];
    $patternParts = explode('/', $pattern);
    $pathParts = explode('/', $path);

    foreach ($patternParts as $k => $v) {
      if (preg_match('/\{([^:}]+)(?::([^}]+))?\}/', $v, $matches)) {
        if (isset($pathParts[$k])) {
          $params[$matches[1]] = $pathParts[$k];
        }
      }
    }

    return $params;
  }

  /**
   * ルートクラスを実行する
   *
   * @param array $route  ルート設定
   * @return void
   */
  protected static function executeClass(array $route): void {
    if (is_callable($route['class'])) {
      call_user_func($route['class'], $route['params'] ?? []);
    }
  }
}