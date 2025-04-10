<?php
namespace Site\Lib;

/**
 * PHP純正のMySQLドライバ。php-mysql拡張モジュールが不要。
 * TODO: execute()で他のMySQLタイプを処理 (MYSQL_TYPE_DATA, MYSQL_TYPE_BLOB)
 * TODO: トランザクション管理
 * TODO: 各種接続オプション
 * TODO: プロトコルの完全なカバレッジ
 */
class Mysql {
  private $socket = null;
  private bool $connected = false;
  private bool $debug = false;
  private array $packetLog = [];
  private array $serverInfo = [];
  public  string $username;
  private string $password;
  public  string $dbname;
  public  string $host;
  private int $port;

  private array $prepared = [];

  /**
   * コンストラクタ
   * 
   * MySQL接続に必要な設定をDBINFOから初期化します。
   */
  public function __construct() {
    if (!MYSQL_ENABLED) return;

    $this->host = DBINFO['host'];
    $this->username = DBINFO['username'];
    $this->password = DBINFO['password'];
    $this->dbname = DBINFO['dbname'];
    $this->port = DBINFO['port'];
    $this->debug = DBINFO['debug'];
  }

  /**
   * デストラクタ
   * 
   * オブジェクト破棄時に接続を閉じます。
   */
  public function __destruct() {
    $this->close();
  }

  /**
   * デバッギングの有無
   * 
   * @param bool $debug  デバッグモードを有効にするかどうか
   * @return Mysql  自身を返す（メソッドチェーン用）
   */
  public function setDebug(bool $debug): Mysql {
    $this->debug = (bool)$debug;
    return $this;
  }

  /**
   * パケットログの取得
   * 
   * @return array  送信および受信したパケットのログ
   */
  public function getPacketLog(): array {
    return $this->packetLog;
  }

  /**
   * MySQLサーバーに接続
   * 
   * ソケットを作成し、サーバーに接続後、認証とデータベース選択を行います。
   * 
   * @return bool  接続成功時はtrue
   * @throws \Exception  接続または認証に失敗した場合
   */
  public function connect(): bool {
    if (!MYSQL_ENABLED) return false;

    $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($this->socket === false) {
      $msg = 'ソケットの作成に失敗: '.socket_strerror(socket_last_error());
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $res = socket_connect($this->socket, $this->host, $this->port);
    if ($res === false) {
      $msg = 'ソケットに接続に失敗: '
        .socket_strerror(socket_last_error($this->socket));
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $greeting = $this->readPacket();
    $this->parseServerGreeting($greeting);
    $this->authenticate();
    $response = $this->readPacket();

    if (ord($response[0]) !== 0x00) {
      $code = unpack('v', substr($response, 1, 2))[1];
      $mes = substr($response, 3);
      $msg = "認証応答に失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    if (!empty($this->dbname)) {
      $this->selectDatabase($this->dbname);
    }

    $this->connected = true;
    return true;
  }

  /**
   * 接続を閉じる
   * 
   * COM_QUITコマンドを送信し、ソケットを閉じます。
   * 
   * @return void
   */
  public function close(): void {
    if (!MYSQL_ENABLED) return;

    if ($this->socket) {
      $this->sendCommand(0x01); // COM_QUIT
      socket_close($this->socket);
      $this->socket = null;
      $this->connected = false;
    }
  }

  /**
   * 利用するデータベースを選択する
   * 
   * COM_INIT_DBコマンドを使用してデータベースを選択します。
   * 
   * @param string $database  データベース名
   * @return bool  成功時はtrue
   * @throws \Exception  データベース選択に失敗した場合
   */
  public function selectDatabase(string $database): bool {
    if (!MYSQL_ENABLED) return false;

    $this->sendCommand(0x02, $database); // COM_INIT_DB
    $res = $this->readPacket();

    if (ord($res[0]) === 0xFF) {
      $code = unpack('v', substr($res, 1, 2))[1];
      $mes = substr($res, 3);
      $msg = "データベースの選択に失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $this->dbname = $database;

    return true;
  }

  /**
   * プリペアドステートメントの準備
   * 
   * SQLクエリをプリペアドステートメントとして準備し、ステートメントIDを返します。
   * 
   * @param string $query  プレースホルダ付きSQLクエリ（例: "SELECT * FROM users WHERE id = ?"）
   * @return int  成功時はステートメントID
   * @throws \Exception  準備に失敗した場合
   */
  public function prepare(string $query): int {    
    if (!$this->connected || !MYSQL_ENABLED) return false;

    $this->sendCommand(0x16, $query); // COM_STMT_PREPARE
    $res = $this->readPacket();

    if (ord($res[0]) === 0xFF) {
      $code = unpack('v', substr($res, 1, 2))[1];
      $mes = substr($res, 3);
      $msg = "準備に失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $pos = 0;
    $statementId = unpack('V', substr($res, $pos + 1, 4))[1]; // ステートメントID
    $pos += 5;
    $numCols = unpack('v', substr($res, $pos, 2))[1]; // 列数
    $pos += 2;
    $numParam = unpack('v', substr($res, $pos, 2))[1]; // パラメートル数
    $pos += 4;

    $this->prepared[$statementId] = [
      'num_params' => $numParam,
      'num_columns' => $numCols,
      'params' => [],
      'columns' => [],
    ];

    if ($numParam > 0) {
      for ($i = 0; $i < $numParam; $i++) {
        $paramPacket = $this->readPacket();
        $this->prepared[$statementId]['params'][] =
          $this->parseFieldPacket($paramPacket);
      }

      $this->readPacket();
    }

    if ($numCols > 0) {
      for ($i = 0; $i < $numCols; $i++) {
        $columnPacket = $this->readPacket();
        $this->prepared[$statementId]['columns'][] =
          $this->parseFieldPacket($columnPacket);
      }

      $this->readPacket();
    }

    return $statementId;
  }

  /**
   * プリペアドステートメントの実行
   * 
   * 指定されたステートメントIDとパラメータを使用してクエリを実行します。
   * 
   * @param int $statementId  プリペアドステートメントID
   * @param array $params  パラメータ値の配列
   * @return array  結果セットまたはOKパケットデータ
   * @throws \Exception  実行に失敗した場合
   */
  public function execute(int $statementId, array $params = []): array {
    if (!MYSQL_ENABLED) return [];

    if (!isset($this->prepared[$statementId])) {
      $msg = "不正なステートメントID: {$statementId}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $stmtInfo = $this->prepared[$statementId];
    if (count($params) != $stmtInfo['num_params']) {
      $msg = "パラメータ数が一致しません: 期待 {$stmtInfo['num_params']}, 取得 ".count($params);
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $data = chr(0x17); // COM_STMT_EXECUTE
    $data .= pack('V', $statementId);
    $data .= chr(0); // 0 = カーソルなし
    $data .= pack('V', 1); // 繰り返し数（常に1）

    if ($stmtInfo['num_params'] > 0) {
      // NULLビットマップ
      $nullBitmap = str_repeat("\0", ceil($stmtInfo['num_params'] / 8));
      foreach ($params as $k => $v) {
        if ($v === NULL) {
          $nullBitmap[$k >> 3] = chr(ord($nullBitmap[$k >> 3]) | (1 << ($k & 7)));
        }
      }

      $data .= $nullBitmap;

      $data .= chr(1); // 新パラメートルフラグ（１＝はい）

      $paramTypes = '';
      $paramValues = '';
      foreach ($params as $param) {
        /**
         * MYSQL_TYPE_DECIMAL     0x00
         * MYSQL_TYPE_TINY        0x01
         * MYSQL_TYPE_SHORT       0x02
         * MYSQL_TYPE_LONG        0x03
         * MYSQL_TYPE_FLOAT       0x04
         * MYSQL_TYPE_DOUBLE      0x05
         * MYSQL_TYPE_NULL        0x06
         * MYSQL_TYPE_TIMESTAMP   0x07
         * MYSQL_TYPE_LONGLONG    0x08
         * MYSQL_TYPE_INT24       0x09
         * MYSQL_TYPE_DATE        0x0A
         * MYSQL_TYPE_TIME        0x0B
         * MYSQL_TYPE_DATETIME    0x0C
         * MYSQL_TYPE_YEAR        0x0D
         * MYSQL_TYPE_NEWDATE     0x0E
         * MYSQL_TYPE_VARCHAR     0x0F
         * MYSQL_TYPE_BIT         0x10
         * 
         * MYSQL_TYPE_NEWDECIMAL  0xF6
         * MYSQL_TYPE_ENUM        0xF7
         * MYSQL_TYPE_SET         0xF8
         * MYSQL_TYPE_TINY_BLOB   0xF9
         * MYSQL_TYPE_MEDIUM_BLOB 0xFA
         * MYSQL_TYPE_LONG_BLOB   0xFB
         * MYSQL_TYPE_BLOB        0xFC
         * MYSQL_TYPE_VAR_STRING  0xFD
         * MYSQL_TYPE_STRING      0xFE
         * MYSQL_TYPE_GEOMETRY    0xFF
         */
        if ($param === null) {
          $paramType .= pack('v', 0x06); // MYSQL_TYPE_NULL
        } else if (is_int($param)) {
          $intLen = strlen((string)$param);
          if ($intLen == 10) {
            $paramTypes .= pack('v', 0x07); // MYSQL_TYPE_TIMESTAMP
          } else if ($intLen >= -128 && $intLen < 127) {
            $paramTypes .= pack('v', 0x01); // MYSQL_TYPE_TINY
          } else if ($intLen >= -32768 && $intLen < 32767) {
            $paramTypes .= pack('v', 0x02); // MYSQL_TYPE_SHORT
          } else if ($intLen >= -8388608 && $intLen < 8388607) {
            $paramTypes .= pack('v', 0x09); // MYSQL_TYPE_INT24
          } else if ($intLen >= -2147483648 && $intLen < 2147483647) {
            $paramTypes .= pack('v', 0x03); // MYSQL_TYPE_LONG
          } else if ($intLen >= -9223372036854775808 && $intLen < 9223372036854775807) {
            $paramTypes .= pack('v', 0x08); // MYSQL_TYPE_LONGLONG
          }
          $paramValues .= pack('V', $param);
        } else if (is_float($param)) {
          $decLen = strpos(strrev((string)$param), '.');
          if ($decLen !== FALSE && $decLen < 25) {
            $paramTypes .= pack('v', 0x04); // MYSQL_TYPE_FLOAT
          } else if ($decLen !== FALSE && $decLen >= 25 && $decLen < 60) {
            $paramTypes .= pack('v', 0x05); // MYSQL_TYPE_DOUBLE
          }
          $paramValues .= pack('d', $param);
        } else {
          $paramTypes .= pack('v', 0x0F); // MYSQL_TYPE_STRING
          $len = strlen($param);
          $paramValues .= $this->encodeLengthEncodedInteger($len).$param;
        }
      }

      $data .= $paramTypes.$paramValues;
    }

    $this->sendPacket($data);
    $res = $this->readPacket();

    if (ord($res[0]) === 0xFF) {
      $code = unpack('v', substr($res, 1, 2))[1];
      $mes = substr($res, 3);
      $msg = "実行に失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    if (ord($res[0]) === 0x00) {
      return $this->parseOkPacket($res);
    }

    return $this->parseResultSet($res);
  }

  /**
   * プリペアドステートメントの解放
   * 
   * 指定されたステートメントIDを解放し、リソースをクリーンアップします。
   * 
   * @param int $statementId  プリペアドステートメントID
   * @return bool  成功時はtrue
   */
  public function demolish(int $statementId): bool {
    if (!MYSQL_ENABLED || !isset($this->prepared[$statementId])) return false;

    $data = chr(0x19).pack('V', $statementId); // COM_STMT_CLOSE
    $this->sendPacket($data);

    unset($this->prepared[$statementId]);
    return true;
  }

  /**
   * SQLクエリの実行
   * 
   * COM_QUERYを使用してSQLクエリを実行し、結果を返します。
   * 
   * @param string $query  実行するSQLクエリ
   * @return array  結果セットまたはOKパケットデータ
   * @throws \Exception  クエリ実行に失敗した場合
   */
  public function query(string $query): array {
    if (!MYSQL_ENABLED) return [];

    $this->sendCommand(0x03, $query); // COM_QUERY
    $res = $this->readPacket();

    if (ord($res[0]) === 0xFF) {
      $code = unpack('v', substr($res, 1, 2))[1];
      $mes = substr($res, 3);
      $msg = "クエリに失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    // レスポンスは0x00で始まったら、OKパケットだ
    if (ord($res[0]) === 0x00) {
      return $this->parseOkPacket($res);
    }

    // レスポンスは0xFBで始まったら、、 LOCAL INFILEリクエストだ
    // @todo LOCAL INFILEリクエストの処理を実装
    if (ord($res[0]) === 0xFB) {
      $msg = "LOCAL INFOリクエストは未対応です";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    return $this->parseResultSet($res);
  }
  
  /**
   * パケットログをファイルに保存する
   * 
   * デバッグ用に収集したパケットログを指定されたファイルに保存します。
   * 
   * @param string $filename  保存先ファイル名
   * @return bool|int  成功時は書き込んだバイト数、失敗時はfalse
   */
  public function savePacketLogToFile(string $filename): bool|int {
    if (!MYSQL_ENABLED) return 0;

    $output = '';
    
    foreach ($this->packetLog as $index => $packetInfo) {
      $direction = $packetInfo['direction'];
      $length = $packetInfo['length'];
      $seqNum = $packetInfo['seqNum'];
      $data = $packetInfo['data'];
      $timestamp = date('Y-m-d H:i:s', (int)$packetInfo['timestamp']);
      
      $output .= "=== パケット #{$index} ({$timestamp}) {$direction}
      $output .= (長さ: {$length}, シーケンス: {$seqNum}) ===\n";
      $output .= "16進数: ".$this->hexDump($data)."\n";
      $output .= "ASCII: ".$this->asciiDump($data)."\n";
      $output .= "==========================================\n\n";
    }
    
    return file_put_contents(ROOT.'/log/'.$filename, $output);
  }

  // 機能性メソッド

  /**
   * MySQLサーバーで認証する
   * 
   * クライアント機能フラグと認証情報を送信してサーバー認証を行います。
   * 
   * @return bool  認証成功時はtrue
   * @throws \Exception  認証に失敗した場合
   */
  private function authenticate(): bool {
    /**
     * CLIENT_LONG_PASSWORD       0x00000001
     * CLIENT_PROTOCOL_41         0x00000200
     * CLIENT_SECURE_CONNECTION   0x00008000
     * CLIENT_CONNECT_WITH_DB     0x00000800
     *
     * 0x00020D05 = CLIENT_LONG_PASSWORD | CLIENT_PROTOCOL_41 |
     *              CLIENT_SECURE_CONNECTION | CONNECT_WITH_DB
     */
    $data = '';
    $data .= pack('L', 0x00020D05); // クライアント機能フラグ
    $data .= pack('L', 16777216); // パケットサイズの大きさ
    $data .= chr(33); // チャーセット（33 = utf8_general_ci）
    $data .= str_repeat("\0", 23); // 予約バイト
    $data .= $this->username."\0"; // ユーザー名

    // パスワード
    if (empty($this->password)) {
      $data .= "\0"; // 空
    } else {
      $pw = $this->scramblePassword($this->password, $this->serverInfo['scramble']);
      $data .= chr(strlen($pw)).$pw;
    }

    // データベース名
    if (!empty($this->dbname)) {
      $data .= $this->dbname."\0";
    }

    // 認証パケットを送信する
    $this->sendPacket($data, 1);

    // サーバー返事を送る
    $res = $this->readPacket();

    if (ord($res[0]) === 0xFF) {
      $code = unpack('v', substr($res, 1, 2))[1];
      $mes = substr($res, 3);
      $this->close();
      $msg = "認証に失敗: {$code} - {$mes}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    return true;
  }

  /**
   * サーバーから結果セットを解析する
   * 
   * クエリ結果のフィールドと行データを解析して返します。
   * 
   * @param string $firstPacket  最初の結果セットパケット
   * @return array  フィールドと行データの配列
   * @throws \Exception  EOFパケットが期待通りに受信できない場合
   */
  private function parseResultSet(string $firstPacket): array {
    $fieldCnt = ord($firstPacket[0]);

    $fields = [];
    for ($i = 0; $i < $fieldCnt; $i++) {
      $fieldPacket = $this->readPacket();
      $fields[] = $this->parseFieldPacket($fieldPacket);
    }

    $eofPacket = $this->readPacket();
    if (ord($eofPacket[0]) !== 0xFE) {
      $msg = "フィールド説明の後にEOFパケットが期待されます";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $rows = [];
    while (true) {
      $rowPacket = $this->readPacket();

      // 行データの終了を示すEOFパケットを確認
      if (ord($rowPacket[0]) === 0xFE && strlen($rowPacket) < 9) break;
      $rows[] = $this->parseRowPacket($rowPacket, $fields);
    }

    return [
      'fields' => $fields,
      'rows' => $rows,
    ];
  }

  /**
   * フィールドパケットを解析する
   * 
   * フィールドのメタデータを解析して返します。
   * 
   * @param string $packet  フィールドパケット
   * @return array  フィールドのメタデータ
   */
  private function parseFieldPacket(string $packet): array {
    $pos = 0;
    $field = [];

    // カタログのスキップ（def等）
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['catalog'] = substr($packet, $pos, $len);
    $pos += 1 + $len;

    // データベース名
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['db'] = substr($packet, $pos, $len);
    $pos += $len;

    // テーブル名
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['table'] = substr($packet, $pos, $len);
    $pos += $len;

    // 元のテーブル名
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['org_table'] = substr($packet, $pos, $len);
    $pos += 1 + $len;

    // フィールド名
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['name'] = substr($packet, $pos, $len);
    $pos += $len;

    // 元のフィールド名
    $len = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $field['org_name'] = substr($packet, $pos, $len);
    $pos += $len;

    // フィルターバイトをスキップ（通常は0x0C）
    $pos += 1;

    // 文字セット
    $field['charset'] = unpack('v', substr($packet, $pos, 2))[1];
    $pos += 2;

    // 列の長さ
    $field['length'] = unpack('V', substr($packet, $pos, 4))[1];
    $pos += 4;

    // フィールド種類
    $field['type'] = ord($packet[$pos]);
    $pos += 1;

    // フラグ
    $field['flags'] = unpack('v', substr($packet, $pos, 2))[1];
    $pos += 2;

    // 小数点以下の桁数
    $field['decimals'] = ord($packet[$pos]);
    $pos += 1;

    // フィルターバイトをスキップ
    $pos += 2;

    // デフォルト値（存在する場合、長さエンコード文字列）
    if ($pos < strlen($packet)) {
      $len = $this->getLengthEncodedIntegerValue($packet, $pos);
      $pos += $this->getLengthEncodedIntegerSize($len);
      $field['default'] = substr($packet, $pos, $len);
    }

    return $field;
  }

  /**
   * 行パケットを解析する
   * 
   * 結果セットの行データを解析して返します。
   * 
   * @param string $packet  行パケット
   * @param array $fields  フィールドメタデータの配列
   * @return array  行データの連想配列
   */
  private function parseRowPacket(string $packet, array $fields): array {
    $pos = 0;
    $row = [];

    foreach ($fields as $field) {
      // 0xFB = NULL
      if (ord($packet[$pos]) === 0xFB) {
        $row[$field['name']] = null;
        $pos++;
        continue;
      }

      // 長さ
      $len = ord($packet[$pos]);
      $pos++;
      $row[$field['name']] = substr($packet, $pos, $len);
      $pos += $len;
    }

    return $row;
  }

  /**
   * OKパケットを解析する
   * 
   * OKパケットの内容を解析して影響を受けた行数や挿入IDなどを返します。
   * 
   * @param string $packet  OKパケット
   * @return array  OKパケットのデータ
   * @throws \Exception  パケットが不完全な場合
   */
  private function parseOkPacket(string $packet): array {
    if (strlen($packet) < 2) {
      $msg = "OKパケットが短すぎます: " . strlen($packet) . "バイト";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    $pos = 1; // ヘッダーバイト（0x00）をスキップする
    
    $affectedRows = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($affectedRows);

    $insertId = $this->getLengthEncodedIntegerValue($packet, $pos);
    $pos += $this->getLengthEncodedIntegerSize($insertId);

    if (strlen($packet) < $pos + 2) {
      $msg = "OKパケットにサーバーステータス用のデータが不足しています";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }
    $serverStatus = unpack('v', substr($packet, $pos, 2))[1];
    $pos += 2;

    if (strlen($packet) < $pos + 2) {
      $msg = "OKパケットに警告カウント用のデータが不足しています";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }
    $warningCount = unpack('v', substr($packet, $pos, 2))[1];

    return [
      'affectedRows' => $affectedRows,
      'insertId' => $insertId,
      'serverStatus' => $serverStatus,
      'warningCount' => $warningCount,
    ];
  }

  /**
   * MySQLサーバーからパケットを読み込む
   * 
   * ソケットからパケットを読み込み、完全なデータを受信するまで待機します。
   * 
   * @return string  受信したパケットデータ
   * @throws \Exception  読み込みに失敗した場合
   */
  private function readPacket(): string {
    $header = ''; // パケットのヘッダー＝４バイト
    $bytesRead = socket_recv($this->socket, $header, 4, MSG_WAITALL);
    if ($bytesRead !== 4) {
      $msg = "パケットヘッダーの読み込みに失敗: 期待 4 バイト, 取得 {$bytesRead}";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    // パケットの長さを最初３バイトからパーシングする
    $len = ord($header[0]) + (ord($header[1]) << 8) + (ord($header[2]) << 16);

    // パケットの順序番号は第４目のバイト
    $seqNum = ord($header[3]);

    // パケットの内容を読み込む
    $data = '';
    $remaining = $len;
    $timeout = 5;
    socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
      'sec' => $timeout,
      'usec' => 0,
    ]);

    while ($remaining > 0) {
      $buffer = '';
      $bytesRead = socket_recv($this->socket, $buffer, $remaining, 0);

      if ($bytesRead === false) {
        $msg = "パケット内容の読み込みに失敗: エラー "
          .socket_strerror(socket_last_error($this->socket));
        logger(\LogType::MySQL, $msg);
        throw new \Exception($msg);
      }

      if ($bytesRead === 0) {
        usleep(10000);
        continue;
      }

      $data .= $buffer;
      $remaining -= $bytesRead;
    }

    if (ord($data[0]) === 0x00 && strlen($data) < 7) {
      $extra = '';
      $extraBytes = socket_recv($this->socket, $extra, 7 - strlen($data), 0);
      if ($extraBytes !== false && $extraBytes > 0) {
        $data .= $extra;
      }
    }

    // デバッグ
    if ($this->debug) {
      $packetInfo = [
        'direction' => 'RECV',
        'length' => $len,
        'seqNum' => $seqNum,
        'data' => $data,
        'timestamp' => microtime(true),
      ];

      $this->logPacket($packetInfo);
    }

    return $data;
  }

  /**
   * MySQLサーバーにパケットを送信する
   * 
   * 指定されたデータとシーケンス番号でパケットを送信します。
   * 
   * @param string $data  送信するデータ
   * @param int $seqNum  シーケンス番号（デフォルトは0）
   * @return bool  成功時はtrue
   * @throws \Exception  送信に失敗した場合
   */
  private function sendPacket(string $data, $seqNum = 0): bool {
    $len = strlen($data);

    // パケットヘッダー：長さ＝３バイト、順序番号＝１バイト
    $header = chr($len & 0xFF)
             .chr(($len >> 8) & 0xFF)
             .chr(($len >> 16) & 0xFF)
             .chr($seqNum);

    // デバッグ
    if ($this->debug) {
      $packetInfo = [
        'direction' => 'SEND',
        'length' => $len,
        'seqNum' => $seqNum,
        'data' => $data,
        'timestamp' => microtime(true),
      ];

      $this->logPacket($packetInfo);
    }

    // ヘッダーの送信
    $sent = socket_write($this->socket, $header, 4);
    if ($sent !== 4) {
      $msg = "パケットヘッダーの送信に失敗";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    // データの送信
    $sent = socket_write($this->socket, $data, $len);
    if ($sent !== $len) {
      $msg = "パケットデータの送信に失敗";
      logger(\LogType::MySQL, $msg);
      throw new \Exception($msg);
    }

    return true;
  }

  /**
   * MySQLサーバーにコマンドを送信する
   * 
   * 指定されたコマンドとデータを送信します。
   * 
   * @param string $command  コマンド（例: 0x03 = COM_QUERY）
   * @param string $data  付加データ（デフォルトは空）
   * @return bool  成功時はtrue
   * @throws \Exception  送信に失敗した場合
   */
  private function sendCommand(string $command, string $data = ''): bool {
    $packet = chr($command).$data;
    return $this->sendPacket($packet);
  }

  /**
   * サーバーの挨拶パケットを解析する
   * 
   * サーバーからの初期挨拶パケットを解析し、サーバー情報を保存します。
   * 
   * @param string $packet  挨拶パケット
   * @return void
   */
  private function parseServerGreeting(string $packet): void {
    $pos = 0;

    // プロトコールバージョン（１バイト）
    $this->serverInfo['protocol'] = ord($packet[$pos]);
    $pos++;

    // サーバーバージョン
    $end = strpos($packet, "\0", $pos);
    $this->serverInfo['version'] = substr($packet, $pos, $end - $pos);
    $pos = $end + 1;

    // スレッドID（4バイト）
    $this->serverInfo['threadId'] = unpack('V', substr($packet, $pos, 4))[1];
    $pos += 4;

    // スクランブルバッファの最初の部分（8バイト）
    $this->serverInfo['scramble'] = substr($packet, $pos, 8);
    $pos += 8;

    // フィルターバイトをスキップする
    $pos++;

    // サーバー機能（２バイト）
    $this->serverInfo['capabilities'] = unpack('v', substr($packet, $pos, 2))[1];
    $pos += 2;

    // サーバー言語（１バイト）
    $this->serverInfo['language'] = ord($packet[$pos]);
    $pos++;

    // サーバー状況（２バイト）
    $this->serverInfo['status'] = unpack('v', substr($packet, $pos, 2))[1];
    $pos += 2;

    // １３バイトのスキップ
    $pos += 13;

    // その他（１２バイト）
    $this->serverInfo['scramble'] .= substr($packet, $pos, 12);
  }

  /**
   * パスワードをスクランブルする
   * 
   * MySQL認証用のパスワードをスクランブルします。
   * 
   * @param string $password  プレーンテキストのパスワード
   * @param string $scramble  サーバーから提供されたスクランブル文字列
   * @return string  スクランブルされたパスワード（20バイト）
   */
  private function scramblePassword(string $password, string $scramble): string {
    $stage1 = sha1($password, true);
    $stage2 = sha1($stage1, true);
    $stage3 = sha1($scramble.$stage2, true);

    // $stage1 XOR $stage3
    $res = '';
    for ($i = 0; $i < 20; $i++) {
      $res .= chr(ord($stage1[$i]) ^ ord($stage3[$i]));
    }

    return $res;
  }

  /**
   * デバッグのためにパケットをログする
   * 
   * パケット情報をログに追加し、デバッグ出力を表示します。
   * 
   * @param array $packetInfo  パケット情報（方向、長さ、シーケンス番号、データ、タイムスタンプ）
   * @return void
   */
  private function logPacket(array $packetInfo): void {
    $this->packetLog[] = $packetInfo;

    $direction = $packetInfo['direction'];
    $length = $packetInfo['length'];
    $seqNum = $packetInfo['seqNum'];
    $data = $packetInfo['data'];

    echo "=== {$direction} パケット (長さ: {$length}, シーケンス: $seqNum) ===\n";
    echo $this->hexDumpWithAscii($data)."\n";

    $this->interpretPacket($data);

    echo "==========================================\n\n";
  }

  /**
   * バイナリデータを16進数で出力する
   * 
   * デバッグ用にデータを16進数形式で表示します。
   * 
   * @param string $data  バイナリデータ
   * @return string  16進数文字列
   */
  private function hexDump(string $data): string {
    $res = '';
    $len = strlen($data);

    for ($i = 0; $i < $len; $i++) {
      $res .= sprintf('%02X ', ord($data[$i]));

      // 読み易さの為、各１６バイトで新行列を入る
      if (($i + 1) % 16 === 0 && $i !== $len - 1) {
        $res .= "\n";
      }
    }

    return $res;
  }

  /**
   * バイナリデータをASCIIで出力する
   * 
   * デバッグ用にデータをASCII形式で表示します。
   * 
   * @param string $data  バイナリデータ
   * @return string  ASCII文字列
   */
  private function asciiDump(string $data): string {
    $res = '';
    $len = strlen($data);

    for ($i = 0; $i < $len; $i++) {
      $char = ord($data[$i]);

      // 表示出来るＡＳＣＩＩ文字だけを書き出す
      if ($char >= 32 && $char <= 126) {
        $res .= $data[$i];
      } else {
        $res .= '.';
      }

      // 読み易さの為、各１６バイトで新行列を入る
      if (($i + 1) % 16 === 0 && $i !== $len - 1) {
        $res .= "\n";
      }
    }

    return $res;
  }

  /**
   * バイナリデータを2進数で出力する
   * 
   * デバッグ用にデータを2進数形式で表示します。
   * 
   * @param string $data  バイナリデータ
   * @return string  2進数文字列
   */
  private function binaryDump(string $data): string {
    $res = '';
    $len = strlen($data);

    for ($i = 0; $i < $len; $i++) {
      $res .= sprintf('%08b ', ord($data[$i]));

      // 読み易さの為、各８８バイトで新行列を入る
      if (($i + 1) % 8 === 0 && $i !== $len - 1) {
        $res .= "\n";
      }
    }

    return $res;
  }

  /**
   * パケットを最初のバイトに基づいて解釈する
   * 
   * パケットの種類を特定し、デバッグ情報を出力します。
   * 
   * @param string $data  パケットデータ
   * @return void
   */
  private function interpretPacket(string $data): void {
    if (empty($data)) {
      echo "解釈: 空パケット\n";
      return;
    }

    $firstByte = ord($data[0]);

    switch ($firstByte) {
      case 0x00:
        echo "解釈: OKパケット\n";
        $this->debugOkPacket($data);
        break;
      case 0x17:
        echo "解釈: COM_STMT_EXECUTEパケット\n";
        $this->debugStmtExecutePacket($data);
        break;
      case 0xFF:
        echo "解釈: エラーパケット\n";
        $this->debugErrorPacket($data);
        break;
      case 0xFE:
        echo "解釈: EOFパケット\n";
        break;
      case 0xFB:
        echo "解釈: LOCAL INFILEリクエスト\n";
        break;
      default:
        if ($firstByte === 3
            && $data[1] === 'd'
            && $data[2] === 'e'
            && $data[3] === 'f') {
          // フィールドパケットかどうかの確認
          echo "解釈: フィールド説明パケット\n";
          $this->debugFieldPacket($data);
        } else if ($firstByte > 0 && $firstByte < 251) {
          // 結果セットパケットかどうかの確認
          echo "解釈: 結果セットヘッダーパケット（フィールド数: {$firstByte}）\n";
        } else {
          // 以上じゃないと、列データパケットでかもしん
          echo "解釈: 列データパケット又はその他のパケット種類\n";
          $this->debugLengthEncodedStrings($data);
        }
        break;
    }
  }

  /**
   * OKパケット構造をデバッグする
   * 
   * OKパケットの内容を解析し、デバッグ情報を出力します。
   * 
   * @param string $data  OKパケットデータ
   * @return void
   */
  private function debugOkPacket(string $data): void {
    if (strlen($data) < 2) {
      echo '  Error: OK packet too short ('.strlen($data)." bytes)\n";
      return;
    }

    $pos = 1; // ヘッダーバイトをスキップ

    // 影響を受けた行数を取得
    $affectedRows = $this->getLengthEncodedIntegerValue($data, $pos);
    echo "  影響を受けた行数: {$affectedRows}\n";
    $pos += $this->getLengthEncodedIntegerSize($affectedRows);

    // 最後の挿入IDを取得
    $insertId = $this->getLengthEncodedIntegerValue($data, $pos);
    echo "  最後の挿入ID: {$insertId}\n";
    $pos += $this->getLengthEncodedIntegerSize($insertId);

    // サーバーステータス
    if (strlen($data) >= $pos + 2) {
      $serverStatus = unpack('v', substr($data, $pos, 2))[1];
      echo "  サーバーステータス: ".sprintf('0x%04X', $serverStatus)."\n";
      $pos += 2;
    } else {
      echo "  サーバーステータス: 利用不可\n";
    }

    // 警告カウント
    if (strlen($data) >= $pos + 2) {
      $warningCount = unpack('v', substr($data, $pos, 2))[1];
      echo "  警告カウント: {$warningCount}\n";
      $pos += 2;
    } else {
      echo "  警告カウント: 利用不可\n";
    }

    // サーバーメッセージ（存在する場合）
    if (strlen($data) > $pos) {
      $message = substr($data, $pos);
      echo "  メッセージ: ".$this->safeString($message)."\n";
    }
  }

  /**
   * エラーパケット構造をデバッグする
   * 
   * エラーパケットの内容を解析し、デバッグ情報を出力します。
   * 
   * @param string $data  エラーパケットデータ
   * @return void
   */
  private function debugErrorPacket(string $data): void {
    $errorCode = unpack('v', substr($data, 1, 2))[1];
    echo "  エラーコード: {$errorCode}\n";

    // SQLステートマーカーをスキップ（#）
    $sqlState = substr($data, 4, 5);
    echo "  SQLステート: {$sqlState}\n";

    $errorMessage = substr($data, 9);
    echo "  エラーメッセージ: ".$this->safeString($errorMessage)."\n";
  }

  /**
   * フィールドパケット構造をデバッグする
   * 
   * フィールドパケットの内容を解析し、デバッグ情報を出力します。
   * 
   * @param string $data  フィールドパケットデータ
   * @return void
   */
  private function debugFieldPacket(string $data): void {
    $pos = 0;

    // パケットから長さエンコード文字列を抽出
    echo "  フィールドパケット構造:\n";

    // カタログを抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $catalog = substr($data, $pos, $len);
    echo "    カタログ: " . $this->safeString($catalog) . " (長さ: $len)\n";
    $pos += $len;

    // データベースを抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $database = substr($data, $pos, $len);
    echo "    データベース: " . $this->safeString($database) . " (長さ: $len)\n";
    $pos += $len;

    // テーブルを抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $table = substr($data, $pos, $len);
    echo "    テーブル: " . $this->safeString($table) . " (長さ: $len)\n";
    $pos += $len;

    // 元のテーブルを抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $orgTable = substr($data, $pos, $len);
    echo "    元のテーブル: " . $this->safeString($orgTable) . " (長さ: $len)\n";
    $pos += $len;

    // 名前を抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $name = substr($data, $pos, $len);
    echo "    名前: " . $this->safeString($name) . " (長さ: $len)\n";
    $pos += $len;

    // 元の名前を抽出
    $len = $this->getLengthEncodedIntegerValue($data, $pos);
    $pos += $this->getLengthEncodedIntegerSize($len);
    $orgName = substr($data, $pos, $len);
    echo "    元の名前: " . $this->safeString($orgName) . " (長さ: $len)\n";
    $pos += $len;

    // 次の長さエンコード整数を抽出（固定フィールドの長さ、通常は0x0C）
    $fixedLength = $this->getLengthEncodedIntegerValue($data, $pos);
    echo "    固定フィールドの長さ: {$fixedLength}\n";
    $pos += $this->getLengthEncodedIntegerSize($fixedLength);

    // 文字セット
    $charSet = unpack('v', substr($data, $pos, 2))[1];
    echo "    文字セット: ".sprintf('0x%04X', $charSet)."\n";
    $pos += 2;

    // 列の長さ
    $columnLength = unpack('V', substr($data, $pos, 4))[1];
    echo "    列の長さ: {$columnLength}\n";
    $pos += 4;

    // 列の種類
    $columnType = ord($data[$pos]);
    echo "    列の種類: ".sprintf('0x%02X', $columnType)."\n";
    $pos++;

    // フラグ
    $flags = unpack('v', substr($data, $pos, 2))[1];
    echo "    フラグ: ".sprintf('0x%04X', $flags)."\n";
    $pos += 2;

    // 小数点以下の桁数
    $decimals = ord($data[$pos]);
    echo "    小数点以下の桁数: {$decimals}\n";
    $pos++;

    // フィルター
    echo "    フィルター: ".sprintf('0x%04X', unpack('v', substr($data, $pos, 2))[1])."\n";
  }

  /**
   * パケット内の長さエンコード文字列をデバッグする
   * 
   * パケットからすべての長さエンコード文字列を抽出し、デバッグ情報を出力します。
   * 
   * @param string $data  パケットデータ
   * @return void
   */
  private function debugLengthEncodedStrings(string $data): void {
    $pos = 0;
    $length = strlen($data);
    $stringCount = 0;

    echo "  長さエンコード文字列:\n";

    while ($pos < $length) {
      // 長さエンコード文字列を特定
      if ($pos >= $length) break;

      $firstByte = ord($data[$pos]);

      // MySQLプロトコルに基づく長さエンコーディング
      if ($firstByte < 251) {
        // 1バイト長
        $len = $firstByte;
        $pos++;

        if ($pos + $len <= $length) {
          $value = substr($data, $pos, $len);
          echo "    文字列 ".(++$stringCount).": ".$this->safeString($value)
            ." (長さ: {$len})\n";
          $pos += $len;
        } else {
          echo "    位置 {$pos} での無効な長さエンコーディング\n";
          break;
        }
      } else if ($firstByte == 251) {
        // NULL値
        echo "    文字列  ".(++$stringCount).": NULL\n";
        $pos++;
      } else if ($firstByte == 252) {
        // 2バイト長
        if ($pos + 3 <= $length) {
          $len = unpack('v', substr($data, $pos + 1, 2))[1];
          $pos += 3;

          if ($pos + $len <= $length) {
            $value = substr($data, $pos, $len);
            echo "    文字列 ".(++$stringCount).": ".$this->safeString($value)
              ." (長さ: {$len})\n";
            $pos += $len;
          } else {
            echo "    位置 {$pos} での無効な長さエンコーディング\n";
            break;
          }
        } else {
          echo "    位置 {$pos} での不完全な2バイト長\n";
          break;
        }
      } else if ($firstByte == 253) {
        // 3バイト長
        if ($pos + 4 <= $length) {
          $len = unpack('V', substr($data, $pos + 1, 3) . "\0")[1];
          $pos += 4;

          if ($pos + $len <= $length) {
            $value = substr($data, $pos, $len);
            echo "    文字列 ".(++$stringCount).": ".$this->safeString($value)
              ." (長さ: {$len})\n";
            $pos += $len;
          } else {
            echo "    位置 {$pos} での無効な長さエンコーディング\n";
            break;
          }
        } else {
          echo "    位置 {$pos} での不完全な3バイト長\n";
          break;
        }
      } else if ($firstByte == 254) {
        // 8バイト長
        if ($pos + 9 <= $length) {
          // PHPでは8バイト整数を完全に扱えないため、最初の4バイトのみ読み取る
          $len = unpack('V', substr($data, $pos + 1, 4))[1];
          $pos += 9;

          if ($pos + $len <= $length) {
            $value = substr($data, $pos, $len);
            echo "    文字列 ".(++$stringCount).": ".$this->safeString($value)
              ." (長さ: {$len})\n";
            $pos += $len;
          } else {
            echo "    位置 {$pos} での無効な長さエンコーディング\n";
            break;
          }
        } else {
          echo "    位置 {$pos} での不完全な8バイト長\n";
          break;
        }
      } else {
        // 長さエンコード文字列でない場合、次のバイトへ
        $pos++;
      }
    }

    if ($stringCount === 0) {
      echo "    No length-encoded strings found\n";
    }
  }

  /**
   * 文字列を安全に出力用に変換する
   * 
   * 表示可能な文字のみを含み、非表示文字は16進数で表現します。
   * 
   * @param string $str  変換する文字列
   * @return string  安全な文字列
   */
  private function safeString(string $str): string {
    $result = '';
    $length = strlen($str);

    for ($i = 0; $i < $length; $i++) {
      $char = ord($str[$i]);
      if ($char >= 32 && $char <= 126) {
        $result .= $str[$i];
      } else {
        $result .= '\\x' . sprintf('%02X', $char);
      }
    }

    return $result;
  }

  /**
   * 指定位置の長さエンコード整数値を取得する
   * 
   * パケット内の長さエンコード整数を読み取ります。
   * 
   * @param string $data  パケットデータ
   * @param int $pos  開始位置
   * @return mixed  整数値または0（不明な場合）
   */
  private function getLengthEncodedIntegerValue(string $data, int $pos): mixed {
    $firstByte = ord($data[$pos]);

    if ($firstByte < 251) {
      return $firstByte;
    } else if ($firstByte == 252) {
      return unpack('v', substr($data, $pos + 1, 2))[1];
    } else if ($firstByte == 253) {
      return unpack('V', substr($data, $pos + 1, 3) . "\0")[1];
    } else if ($firstByte == 254) {
      // 簡略化のため、8バイト整数の最初の4バイトのみ読み取る
      return unpack('V', substr($data, $pos + 1, 4))[1];
    }

    return 0;
  }

  /**
   * 長さエンコード整数のサイズを取得する
   * 
   * 値に基づいて長さエンコードに必要なバイト数を返します。
   * 
   * @param int $value  整数値
   * @return int  バイト数
   */
  private function getLengthEncodedIntegerSize(int $value): int {
    if ($value < 251) {
      return 1;
    } else if ($value < 65536) {
      return 3; // 0xFCマーカー1バイト + 値2バイト
    } else if ($value < 16777216) {
      return 4; // 0xFDマーカー1バイト + 値3バイト
    } else {
      return 9; // 0xFEマーカー1バイト + 値8バイト
    }
  }

  /**
   * 整数を長さエンコード形式に変換する
   * 
   * MySQLプロトコルに基づいて整数を長さエンコードします。
   * 
   * @param int $value  変換する整数
   * @return string  長さエンコードされた文字列
   */
  private function encodeLengthEncodedInteger(int $value): string {
    if ($value < 251) {
      return chr($value);
    } else if ($value < 65536) {
      return chr(0xFC).pack('v', $value);
    } else if ($value < 16777216) {
      return chr(0xFD).pack('V', $value & 0xFFFFFF);
    } else {
      return chr(0xFE).pack('P', $value);
    }
  }

  /**
   * STMTパケットをデバッグする
   * 
   * COM_STMT_EXECUTEパケットの内容を解析し、デバッグ情報を出力します。
   * 
   * @param string $data  パケットデータ
   * @return void
   */
  private function debugStmtExecutePacket(string $data): void {
    $pos = 1; // コマンドバイトをスキップ
    $statementId = unpack('V', substr($data, $pos, 4))[1];
    echo "  ステートメントID: {$statementId}\n";
    $pos += 5; // ステートメントIDとカーソルフラグをスキップ
    $iterationCount = unpack('V', substr($data, $pos, 4))[1];
    echo "  繰り返し数: {$iterationCount}\n";
    $pos += 4;

    $numParams = $this->prepared[$statementId]['num_params'] ?? 0;
    if ($numParams > 0) {
      $nullBitmapLen = ceil($numParams / 8);
      $nullBitmap = substr($data, $pos, $nullBitmapLen);
      echo "  NULLビットマップ: ".$this->hexDump($nullBitmap)."\n";
      $pos += $nullBitmapLen;

      $newParamsFlag = ord($data[$pos]);
      echo "  新パラメータフラグ: {$newParamsFlag}\n";
      $pos++;

      if ($newParamsFlag) {
        echo "  パラメータ種類:\n";

        for ($i = 0; $i < $numParams; $i++) {
          $type = unpack('v', substr($data, $pos, 2))[1];
          $typeName = $this->getMysqlTypeName($type);
          echo "    パラメータ {$i}: ".sprintf("0x%02X", $type)." ({$typeName})\n";
          $pos += 2;
        }

        echo "  パラメータ値: (生バイナリが続く)\n";
      }
    }
  }

  /**
   * 16進値をMySQLタイプ名に変換する
   * 
   * MySQLのデータ型コードを対応する型名に変換します。
   * 
   * @param int $type  16進数の型コード
   * @return string  型名（不明な場合は'UNKNOWN'）
   */
  private function getMysqlTypeName(int $type): string {
    $types = [
      0x00 => 'MYSQL_TYPE_DECIMAL',
      0x01 => 'MYSQL_TYPE_TINY',
      0x02 => 'MYSQL_TYPE_SHORT',
      0x03 => 'MYSQL_TYPE_LONG',
      0x04 => 'MYSQL_TYPE_FLOAT',
      0x05 => 'MYSQL_TYPE_DOUBLE',
      0x06 => 'MYSQL_TYPE_NULL',
      0x07 => 'MYSQL_TYPE_TIMESTAMP',
      0x08 => 'MYSQL_TYPE_LONGLONG',
      0x09 => 'MYSQL_TYPE_INT24',
      0x0A => 'MYSQL_TYPE_DATE',
      0x0B => 'MYSQL_TYPE_TIME',
      0x0C => 'MYSQL_TYPE_DATETIME',
      0x0D => 'MYSQL_TYPE_YEAR',
      0x0E => 'MYSQL_TYPE_NEWDATE',
      0x0F => 'MYSQL_TYPE_VARCHAR',
      0x10 => 'MYSQL_TYPE_BIT',
      0xF6 => 'MYSQL_TYPE_NEWDECIMAL',
      0xF7 => 'MYSQL_TYPE_ENUM',
      0xF8 => 'MYSQL_TYPE_SET',
      0xF9 => 'MYSQL_TYPE_TINY_BLOB',
      0xFA => 'MYSQL_TYPE_MEDIUM_BLOB',
      0xFB => 'MYSQL_TYPE_LONG_BLOB',
      0xFC => 'MYSQL_TYPE_BLOB',
      0xFD => 'MYSQL_TYPE_VAR_STRING',
      0xFE => 'MYSQL_TYPE_STRING',
      0xFF => 'MYSQL_TYPE_GEOMETRY',
    ];

    return $types[$type] ?? '不明';
  }

  /**
   * 16進数とASCII値を横に並べて出力する
   * 
   * 16進エディタのように、16進数とASCII値を並べて表示します。
   * 
   * @param string $data  バイナリデータ
   * @return string  フォーマットされた文字列
   */
  private function hexDumpWithAscii(string $data): string {
    $output = '';
    $len = strlen($data);
    $offset = 0;

    while ($offset < $len) {
      $hex = '';
      $ascii = '';
      $bytesInLine = min(16, $len - $offset); // 1行あたり16バイト

      // 16進数部分
      for ($i = 0; $i < 16; $i++) {
        if ($i < $bytesInLine) {
          $hex .= sprintf('%02X ', ord($data[$offset + $i]));
        } else {
          $hex .= '   '; // 揃えのためにスペースを埋める
        }
      }

      // ASCII部分
      for ($i = 0; $i < $bytesInLine; $i++) {
        $char = ord($data[$offset + $i]);
        $ascii .= ($char >= 32 && $char <= 126) ? chr($char) : '.';
      }

      $output .= sprintf("%08X  %s |%s|\n", $offset, $hex, $ascii);
      $offset += 16;
    }

    return $output;
  }
}