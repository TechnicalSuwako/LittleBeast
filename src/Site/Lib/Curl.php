<?php
namespace Site\Lib;

/**
 * php_curlへの依存を排除するための独自のCURL実装
 */
class Curl {
  // リクエスト関連のプロパティ
  private string   $url = '';
  private string   $method = 'GET';
  private int      $timeout = 30;
  private array    $headers = [];
  private array    $cookies = [];
  private array    $postFields = [];
  private string   $postRaw = '';
  private string   $userAgent = 'LittleBeast/1.0';
  private bool     $followRedirects = true;
  private int      $maxRedirects = 5;
  private bool     $verbose = false;
  private $stderr = null;
  private string   $caInfoPath = '';
  private bool     $verifySSL = true;
  private string   $username = '';
  private string   $password = '';
  private string   $referer = '';

  // レスポンス関連のプロパティ
  private array  $responseHeaders = [];
  private string $responseBody = '';
  private int    $responseCode = 0;
  private string $responseError = '';
  private array  $info = [];

  /**
   * コンストラクタ
   *
   * @param string|null $url  リクエスト先のURL
   */
  public function __construct(?string $url = null) {
    if ($url !== null) {
      $this->url = $url;
    }
  }

  /**
   * リクエスト先のURLを設定する
   *
   * @param string $url  リクエスト先のURL
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setUrl(string $url): Curl {
    $this->url = $url;
    return $this;
  }

  /**
   * リクエストメソッドを設定する
   *
   * @param string $method  GE又はPOST等のHTTPメソッド
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setMethod(string $method): Curl {
    $this->method = strtoupper($method);
    return $this;
  }

  /**
   * リクエストのタイムアウト秒数を設定する
   *
   * @param int $seconds  タイムアウト秒数
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setTimeout(int $seconds): Curl {
    $this->timeout = (int)$seconds;
    return $this;
  }

  /**
   * リクエストヘッダーを設定する
   *
   * @param array $headers  リクエストヘッダーの配列
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setHeaders(array $headers): Curl {
    $this->headers = $headers;
    return $this;
  }

  /**
   * 単一のヘッダーを追加する
   *
   * @param string $name  ヘッダー名
   * @param mixed $value  ヘッダー値
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function addHeader(string $name, mixed $value): Curl {
    $this->headers[$name] = $value;
    return $this;
  }

  /**
   * リクエストのクッキーを設定する
   *
   * @param array $cookies  クッキーの配列
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setCookies(array $cookies): Curl {
    $this->cookies = $cookies;
    return $this;
  }

  /**
   * 単一のクッキーを追加する
   * 
   * @param string $name  クッキー名
   * @param mixed $value  クッキー値
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function addCookie(string $name, mixed $value): Curl {
    $this->cookies[$name] = $value;
    return $this;
  }

  /**
   * POSTフィールドを設定する
   *
   * @param array $fields  POSTデータの配列
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setPostFields(array $fields): Curl {
    $this->postFields = $fields;
    return $this;
  }

  /**
   * 生のPOSTデータを設定する
   *
   * @param string $data  生のPOSTデータ
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setPostRaw(string $data): Curl {
    $this->postRaw = $data;
    return $this;
  }

  /**
   * ユーザーエージェントを設定する
   *
   * @param string $userAgent  カスタムユーザーエージェント
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setUserAgent(string $userAgent): Curl {
    $this->userAgent = $userAgent;
    return $this;
  }

  /**
   * リダイレクトを追跡するかどうかを設定する
   *
   * @param bool $follow  追跡するかどうか（デフォルトはtrue）
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setFollowRedirects(bool $follow): Curl {
    $this->followRedirects = (bool)$follow;
    return $this;
  }

  /**
   * 追跡するリダイレクトの最大数を設定する
   *
   * @param int $max  リダイレクトの最大数
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setMaxRedirects(int $max): Curl {
    $this->maxRedirects = (int)$max;
    return $this;
  }

  /**
   * SSL証明書を検証するかどうかを設定する
   *
   * @param bool $verify  SSL検証を行うかどうか（デフォルトはtrue）
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setVerifySSL(bool $verify): Curl {
    $this->verifySSL = (bool)$verify;
    return $this;
  }

  /**
   * 基本認証の資格情報を設定する
   *
   * @param string $username  ユーザー名
   * @param string $password  パスワード
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setBasicAuth(string $username, string $password): Curl {
    $this->username = $username;
    $this->password = $password;
    return $this;
  }

  /**
   * リファラーURLを設定する
   *
   * @param string $referer  リファラーURL
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setReferer(string $referer): Curl {
    $this->referer = $referer;
    return $this;
  }

  /**
   * 詳細ログを有効にする
   *
   * @param bool $verbose  詳細ログを有効にするかどうか
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setVerbose(bool $verbose): Curl {
    $this->verbose = (bool)$verbose;
    return $this;
  }

  /**
   * エラー出力先を設定する
   *
   * @param resource $handle  エラー出力先のファイルハンドル
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setStderr($handle): Curl {
    $this->stderr = $handle;
    return $this;
  }

  /**
   * SSL証明書のファイルパスを設定する
   *
   * @param string $path  証明書ファイルのパス
   * @return Curl  このインスタンス（メソッドチェーン用）
   */
  public function setCaInfo(string $path): Curl {
    $this->caInfoPath = $path;
    return $this;
  }

  /**
   * リクエストを実行する
   *
   * @return bool  成功または失敗
   */
  public function execute(): bool {
    if (empty($this->url)) {
      $this->responseError = 'URLがありません';
      return false;
    }

    // レスポンスデータのリセット
    $this->responseHeaders = [];
    $this->responseBody = '';
    $this->responseCode = 0;
    $this->responseError = '';
    $this->info = [
      'url' => $this->url,
      'content_type' => '',
      'http_code' => 0,
      'header_size' => 0,
      'request_size' => 0,
      'total_time' => 0,
      'redirect_count' => 0,
      'redirect_url' => '',
    ];

    $startTime = microtime(true);

    // ソケットベースの実装を使用する
    $redirectCount = 0;
    $currentUrl = $this->url;
    $originalMethod = $this->method;

    do {
      if ($this->verbose && $this->stderr) {
        fwrite($this->stderr, "* 接続中: {$currentUrl}\n");
      }

      $parsed = parse_url($currentUrl);
      if (!$parsed) {
        $this->responseError = "無効なURL: {$currentUrl}";
        return false;
      }

      $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : 'http';
      $host = $parsed['host'];
      $port = isset($parsed['port']) 
        ? $parsed['port'] : ($scheme === 'https' ? 443 : 80);
      $path = isset($parsed['path']) ? $parsed['path'] : '/';
      if (isset($parsed['query'])) {
        $path .= '?'.$parsed['query'];
      }

      // Basic認証
      $authHeader = '';
      if (!empty($this->username) && !empty($this->password)) {
        $authHeader = "Authorization: Basic "
          .base64_encode($this->username.':'.$this->password)."\r\n";
      } elseif (isset($parsed['user']) && isset($parsed['pass'])) {
        $authHeader = "Authorization: Basic "
          .base64_encode($parsed['user'].':'.$parsed['pass'])."\r\n";
      }

      // 送信するHTTPリクエストを構築
      $method = $this->method;
      $httpData = '';

      if ($method === 'POST' || $method === 'PUT') {
        if (!empty($this->postRaw)) {
          $httpData = $this->postRaw;
        } elseif (!empty($this->postFields)) {
          $httpData = http_build_query($this->postFields);
          if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';
          }
        }
        $this->headers['Content-Length'] = strlen($httpData);
      }

      // HTTPリクエストを構築
      $accept = 'Accept: */*';
      foreach ($this->headers as $h) {
        if (str_contains($h, 'Accept:')) $accept = $h;
      }

      $request = "{$method} {$path} HTTP/1.1\r\n";
      $request .= "Host: {$host}\r\n";
      $request .= "User-Agent: {$this->userAgent}\r\n";
      $request .= "{$accept}\r\n";
      $request .= "Connection: close\r\n";

      if (!empty($authHeader)) {
        $request .= $authHeader;
      }

      // ヘッダーを追加
      foreach ($this->headers as $name => $value) {
        $request .= "{$name}: {$value}\r\n";
      }

      // リファラーが設定されていれば追加
      if (!empty($this->referer) && !isset($this->headers['Referer'])) {
        $request .= "Referer: {$this->referer}\r\n";
      }

      // クッキーヘッダーを追加
      if (!empty($this->cookies) && !isset($this->headers['Cookie'])) {
        $cookieStrings = [];
        foreach ($this->cookies as $name => $value) {
          $cookieStrings[] = $name . '=' . urlencode($value);
        }
        $request .= 'Cookie: '.implode('; ', $cookieStrings)."\r\n";
      }

      $request .= "\r\n";

      // POSTデータを追加
      if ($method === 'POST' || $method === 'PUT') {
        $request .= $httpData;
      }

      if ($this->verbose && $this->stderr) {
        fwrite($this->stderr, "* リクエストヘッダー:\n{$request}\n");
      }

      // ソケット接続を確立
      $errno = 0;
      $errstr = '';
      
      if ($scheme === 'https') {
        $sslOptions = [
          'verify_peer' => $this->verifySSL,
          'verify_peer_name' => $this->verifySSL
        ];
        
        if (!empty($this->caInfoPath) && file_exists($this->caInfoPath)) {
          $sslOptions['cafile'] = $this->caInfoPath;
        }
        
        $context = stream_context_create(['ssl' => $sslOptions]);
        
        $socket = @stream_socket_client(
          "tls://{$host}:{$port}",
          $errno,
          $errstr,
          $this->timeout,
          STREAM_CLIENT_CONNECT,
          $context
        );
      } else {
        $socket = @fsockopen($host, $port, $errno, $errstr, $this->timeout);
      }

      if (!$socket) {
        $this->responseError = "接続出来ません: {$errstr} ({$errno})";
        if ($this->verbose && $this->stderr) {
          fwrite($this->stderr, "* エラー: {$this->responseError}\n");
        }
        return false;
      }

      // タイムアウトを設定
      stream_set_timeout($socket, $this->timeout);
      
      // リクエストを送信
      fwrite($socket, $request);
      
      // レスポンスを読み込む
      $rawResponse = '';
      $headers = '';
      $body = '';
      $headersComplete = false;

      // ヘッダーとボディを分けて読み込む
      while (!feof($socket)) {
        $line = fgets($socket);
        if ($line === false) {
          break;
        }

        $rawResponse .= $line;

        if (!$headersComplete) {
          if (trim($line) === '') {
            $headersComplete = true;
          } else {
            $headers .= $line;
          }
        } else {
          $body .= $line;
        }
      }

      fclose($socket);

      // レスポンスヘッダーを解析
      $headerLines = explode("\r\n", $headers);

      // ステータスコードを取得
      $statusLine = isset($headerLines[0]) ? $headerLines[0] : '';
      $statusParts = explode(' ', $statusLine, 3);
      $this->responseCode = isset($statusParts[1]) ? (int)$statusParts[1] : 0;
      $this->info['http_code'] = $this->responseCode;

      // ヘッダーを解析
      $this->responseHeaders = [];
      $redirectUrl = '';

      foreach ($headerLines as $index => $header) {
        if ($index === 0) continue;

        if (strpos($header, ':') !== false) {
          list($name, $value) = explode(':', $header, 2);
          $name = trim($name);
          $value = trim($value);
          $this->responseHeaders[$name] = $value;

          if (strtolower($name) === 'content-type') {
            $this->info['content_type'] = $value;
          }

          // リダイレクトをチェック
          if ($this->followRedirects && 
              strtolower($name) === 'location' && 
              $this->responseCode >= 300 && 
              $this->responseCode < 400) {

            $redirectUrl = $value;

            // 相対URLを絶対URLに変換
            if (strpos($redirectUrl, 'http') !== 0) {
              if ($redirectUrl[0] === '/') {
                $redirectUrl = "{$scheme}://{$host}"
                  .($port != 80 && $port != 443 ? ":{$port}" : '').$redirectUrl;
              } else {
                $redirectUrl = "{$scheme}://{$host}"
                  .($port != 80 && $port != 443 ? ":{$port}" : '')
                  .dirname($path).'/'.$redirectUrl;
              }
            }
            
            $this->info['redirect_url'] = $redirectUrl;
          }
        }
      }

      $this->info['header_size'] += strlen($headers);
      $this->responseBody .= $body;

      if ($this->verbose && $this->stderr) {
        fwrite($this->stderr, "* レスポンスコード: {$this->responseCode}\n");
        fwrite($this->stderr, "* レスポンスヘッダー:\n{$headers}\n");
        if (!empty($redirectUrl)) {
          fwrite($this->stderr, "* リダイレクト先: {$redirectUrl}\n");
        }
      }

      // リダイレクトが必要な場合
      if (!empty($redirectUrl) && $redirectCount < $this->maxRedirects) {
        $currentUrl = $redirectUrl;
        $redirectCount++;
        $this->info['redirect_count'] = $redirectCount;

        // 302や303リダイレクトはGETにメソッドを変更
        if ($this->responseCode == 302 || $this->responseCode == 303) {
          $this->method = 'GET';
          $this->postRaw = '';
          $this->postFields = [];
        }
      } else {
        break;
      }
    } while (true);

    // リクエスト完了後、元のメソッドに戻す
    $this->method = $originalMethod;
    $this->info['total_time'] = microtime(true) - $startTime;

    return true;
  }

  /**
   * レスポンスボディを取得する
   *
   * @return string  レスポンスボディ
   */
  public function getResponseBody(): string {
    return $this->responseBody;
  }

  /**
   * レスポンスヘッダーを取得する
   *
   * @return array  レスポンスヘッダーの配列
   */
  public function getResponseHeaders(): array {
    return $this->responseHeaders;
  }

  /**
   * HTTPレスポンスコードを取得する
   *
   * @return int  HTTPレスポンスコード
   */
  public function getResponseCode(): int {
    return $this->responseCode;
  }

  /**
   * エラーメッセージがあれば取得する
   *
   * @return string  エラーメッセージ
   */
  public function getError(): string {
    return $this->responseError;
  }

  /**
   * リクエスト/レスポンス情報を取得する
   *
   * @return array  情報の配列
   */
  public function getInfo(): array {
    return $this->info;
  }

  // 機能性メソッド

  /**
   * リダイレクトURLを確認する
   * 
   * @param string $name  ヘッダー名
   * @param string $value  ヘッダー値
   * @param string $currentUrl  現在のURL
   * @return string  リダイレクトURL、リダイレクトがない場合は空文字
   */
  private function checkReds(string $name, string $value, string $currentUrl): string {
    $redirectUrl = '';

    if ($this->followRedirects && (strtolower($name) === 'location'
        && $this->responseCode >= 300 && $this->responseCode < 400)) {
        $redirectUrl = $value;

        if (strpos($redirectUrl, 'http') !== 0) {
          if ($redirectUrl[0] === '/') {
            $parsed = parse_url($currentUrl);
            $redirectUrl = $parsed['scheme'].'://'.$parsed['host']
              .(isset($parsed['port']) ? ':'.$parsed['port'] : '')
              .$redirectUrl;
          } else {
            $redirectUrl = dirname($currentUrl).'/'.$redirectUrl;
          }
        }
    }

    return $redirectUrl;
  }

  /**
   * ヘッダー文字列を構築する
   * 
   * @return string  構築されたヘッダー文字列
   */
  private function buildHeaderString(): string {
    $headers = [];

    // ユーザー指定のヘッダーを追加
    foreach ($this->headers as $name => $value) {
      $headers[] = "{$name}: {$value}";
    }

    // リファラーが設定されていれば追加
    if (!empty($this->referer) && !isset($this->headers['Referer'])) {
      $headers[] = "Referer: {$this->referer}";
    }

    // 必要に応じてクッキーヘッダーを追加
    if (!empty($this->cookies) && !isset($this->headers['Cookie'])) {
      $cookieStrings = [];
      foreach ($this->cookies as $name => $value) {
        $cookieStrings[] = $name.'='.urlencode($value);
      }

      $headers[] = 'Cookie: '.implode('; ', $cookieStrings);
    }

    return implode("\r\n", $headers)."\r\n";
  }

  /**
   * レスポンスを解析してヘッダーとボディに分割する
   *
   * @param string $response 完全なHTTPレスポンス
   * @return array [ヘッダー配列, ボディ文字列]
   */
  private function parseResponse(string $response): array {
    $parts = explode("\r\n\r\n", $response, 2);
    
    if (count($parts) < 2) {
      return [[], ''];
    }
    
    $headers = explode("\r\n", $parts[0]);
    $body = $parts[1];
    
    // チャンク転送エンコーディングを処理
    if (isset($this->responseHeaders['Transfer-Encoding']) && 
        strtolower($this->responseHeaders['Transfer-Encoding']) === 'chunked') {
      $body = $this->decodeChunkedBody($body);
    }
    
    return [$headers, $body];
  }

  /**
   * チャンク転送エンコーディングされたボディをデコードする
   *
   * @param string $body チャンクエンコードされたボディ
   * @return string デコードされたボディ
   */
  private function decodeChunkedBody(string $body): string {
    $decodedBody = '';
    $position = 0;
    
    while ($position < strlen($body)) {
      $lineEnd = strpos($body, "\r\n", $position);
      if ($lineEnd === false) {
        break;
      }
      
      $chunkSize = hexdec(substr($body, $position, $lineEnd - $position));
      
      if ($chunkSize === 0) {
        break;
      }
      
      $position = $lineEnd + 2;
      $decodedBody .= substr($body, $position, $chunkSize);
      $position += $chunkSize + 2; // チャンクサイズ + CRLF
    }
    
    return $decodedBody;
  }
}