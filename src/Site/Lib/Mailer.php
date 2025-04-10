<?php
namespace Site\Lib;

class Mailer {
  private $socket;
  private string $host;
  private int $port;
  private ?string $user;
  private ?string $pass;

  private ?string $pgpKey;
  private ?string $pgpPass;

  /**
   * コンストラクタ 
   */
  public function __construct() {
    if (!MAILER_ENABLED) {
      throw new \Exception("メーラーは無効です。");
    }

    $this->host = MAILINFO['host'];
    $this->port = MAILINFO['port'];
    $this->user = MAILINFO['user'];
    $this->pass = MAILINFO['pass'];
  }

  /**
   * ソケット接続を開く。
   *
   * @return void
   */
  public function connect(): void {
    $this->socket = fsockopen($this->host, $this->port, $errno, $err, 30);
    if (!$this->socket) {
      $msg = "接続に失敗: {$err} ({$errno})";
      logger(\LogType::Mailer, $msg);
      throw new \Exception($msg);
    }

    $this->readResponse();
  }

  /**
   * ソケット接続を切断する。
   *
   * @return void
   */
  public function disconnect(): void {
    $this->sendCommand('QUIT', 221);
    fclose($this->socket);
  }

  /**
   * サーバーで認証する。
   * ユーザー名とパスワードが指定されている場合はAUTH LOGINを使用する。
   *
   * @return void
   */
  public function authenticate(): void {
    $ehloRes = $this->sendCommand('EHLO '.$this->host, 250);

    // STARTTLSは対応するかどうか確認
    if (strpos($ehloRes, 'STARTTLS') !== false) {
      $this->sendCommand('STARTTLS', 220);

      // TLS暗号化
      if (!stream_socket_enable_crypto(
        $this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        $msg = "TLSハンドシェイクに失敗";
        logger(\LogType::Mailer, $msg);
        throw new \Exception($msg);
      }

      $this->sendCommand('EHLO '.gethostname(), 250);
    }

    if ($this->user && $this->pass) {
      $this->sendCommand('AUTH LOGIN', 334);
      $this->sendCommand(base64_encode($this->user), 334);
      $this->sendCommand(base64_encode($this->pass), 235);
    }
  }

  /**
   * TLSハンドシェイクに失敗する。
   *
   * @param string $to  受信者のメールアドレス。
   * @param string $subject  メールの件名。
   * @param string $body  メールの本文。
   * @param string|null $toName  受信者の名前。
   * @param string|null $replyTo  返信先メールアドレス。
   * @param string|null $replyToName  返信先の名前。
   * @param bool $pgpSign  PGPで署名するかどうか（現在は正常に動作していません）。
   *
   * @return void
   */
  public function send(
    string $to,
    string $subject,
    string $body,
    ?string $toName = null,
    ?string $replyTo = null,
    ?string $replyToName = null,
    bool $pgpSign = false
  ): void {
    $from = MAILINFO['from'];

    $this->sendCommand("MAIL FROM: <{$from}>", 250);
    $this->sendCommand("RCPT TO: <{$to}>", 250);
    $this->sendCommand('DATA', 354);

    $headers = "Date: ".date('r')."\r\n"; // RFC 2822

    $encSubject = mb_encode_mimeheader($subject, 'UTF-8', 'Q');
    $headers .= "Subject: {$encSubject}\r\n";

    $fromHeader = mb_encode_mimeheader(SITEINFO['title'], 'UTF-8', 'Q')." <{$from}>";
    $headers .= "From: {$fromHeader}\r\n";

    $toHeader = $toName
      ? mb_encode_mimeheader($toName, 'UTF-8', 'Q')." <{$to}>" : $to;
    $headers .= "To: {$toHeader}\r\n";

    if ($replyTo) {
      $replyToHeader = $replyToName
        ? mb_encode_mimeheader($replyToName, 'UTF-8', 'Q')." <{$replyTo}>" : $replyTo;
      $headers .= "Reply-To: {$replyToHeader}\r\n";
    }

    $headers .= "MIME-Version: 1.0\r\n";

    if ($pgpSign) {
      $boundary = uniqid('BOUNDARY_');
      $headers .= "Content-Type: multipart/signed;\r\n";
      $headers .= "  protocol=\"application/pgp-signature\";\r\n";
      $headers .= "  micalg=php-sha512;\r\n";
      $headers .= "  boundary=\"{$boundary}\"\r\n";
    } else {
      $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
      $headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
    }

    $headers .= "X-Mailer: 076 Little Beast\r\n";

    $encBody = quoted_printable_encode($body);
    $data = $headers."\r\n".$encBody;

    if ($pgpSign) {
      $signature = $this->signMessage($data);
      $data = "--{$boundary}\r\n"
        .$data."\r\n"
        ."Content-Type: application/pgp-signature; name=\"signature.asc\"\r\n"
        ."Content-Disposition: attachment; filename=\"signature.asc\"\r\n\r\n"
        .$signature."\r\n"
        ."--{$boundary}--\r\n";
    }

    $data .= "\r\n.\r\n";
    fwrite($this->socket, $data);

    $response = $this->readResponse();
    if (substr($response, 0, 3) != '250') {
      $msg = "メール送信に失敗: {$response}";
      logger(\LogType::Mailer, $msg);
      throw new \Exception($msg);
    }
  }

  /**
   * お好みのタイムゾーンを設定する。
   * 設定しない場合、php.iniで設定されたタイムゾーンがデフォルトになる。
   * それも設定されていない場合、GMTタイムゾーンがデフォルトになる。
   *
   * @param string $zone  IANAタイムゾーンデータベース標準のタイムゾーン（例：Asia/Tokyo）
   * @return void
   */
  public function setTimezone(string $zone): void {
    date_default_timezone_set($zone);
  }

  /**
   * PGPキーとオプションでパスフレーズを設定する。
   *
   * @param string $keypath  PGP署名に使用する秘密鍵へのパス。
   * @param string|null $passphrase  設定されている場合、署名用のパスフレーズ。
   *
   * @return void
   */
  public function enablePGP(string $keypath, ?string $passphrase = null): void {
    $this->pgpKey = file_get_contents($keypath);
    $this->pgpPass = $passphrase;
  }

  // 機能性メソッド

  private function sendCommand(string $command, int $retcode): string {
    fwrite($this->socket, $command."\r\n");
    $res = $this->readResponse();
    if (substr($res, 0, 3) != $retcode) {
      $msg = "「{$command}」に対する予期しないレスポンス: {$res}";
      logger(\LogType::Mailer, $msg);
      throw new \Exception($msg);
    }

    return $res;
  }

  private function readResponse(): string {
    $res = '';

    while ($line = fgets($this->socket, 515)) {
      $res .= $line;
      if (substr($line, 3, 1) == ' ') break; // レスポンスの終了だ
    }

    return $res;
  }

  private function signMessage(string $message): string {
    if (extension_loaded('gnupg')) {// gnupg延長は有効の場合
      $gpg = new \gnupg();
      $gpg->addsignkey($this->pgpKey, $this->pgpPass);
      $gpg->setsignmode(\gnupg::SIG_MODE_DETACH);
      return $gpg->sign($message);
    } else { // なければ、CLIツールを使う（gnupgをインストールは必須）
      $tmp = tempnam(sys_get_temp_dir(), 'pgpmsg');
      file_put_contents($tmp, $message);
      $sig = shell_exec(
        "gpg --batch "
        .($this->pgpPass ? "--passphrase {$this->pgpPass} " : '')
        ."--detach-sign --armor {$tmp} 2>&1"
      );
      unlink($tmp);
      return $sig;
    }
  }
}