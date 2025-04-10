<?php
enum LogType {
  case ActivityPub;
  case Mailer;
  case MySQL;
}

function uuid(): string {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function kys(mixed $arg): void {
  echo '<style>html { color: #fcfcfc; background-color: #232023; } body { margin: 0; }</style>';
  echo '<header style="padding: 10px 0; display: flex; justify-content: space-evenly; background-color: #550f75; margin-bottom: 20px; position: sticky; top: 0;"><div><b>K</b>ILL</div> <div><b>Y</b>OUR</div> <div><b>S</b>ELF</div></header>';
  echo '<pre>';
  print_r($arg);
  echo '<pre>';
  die();
}

function base58btc_encode(string $bin): string {
  $a = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $base = 58;
  $num = \gmp_import($bin, 1, GMP_LSW_FIRST | GMP_NATIVE_ENDIAN);
  $res = '';

  if (\gmp_cmp($num, 0) == 0) return '1';

  while (\gmp_cmp($num, 0) > 0) {
    $mod = \gmp_intval(\gmp_mod($num, $base));
    $res = $a[$mod].$res;
    $num = \gmp_div_q($num, $base);
  }

  $bytes = str_split($bin);
  foreach ($bytes as $byte) {
    if (ord($byte) === 0) {
      $res = '1'.$res;
    } else {
      break;
    }
  }

  return $res;
}

function logger(LogType $section, mixed $arg): void {
  if (LOGGING_ENABLED) {
    $logfile = ROOT.'/log/';
    if ($section == LogType::ActivityPub) $logfile .= 'ap_log.txt';
    else if ($section == LogType::Mailer) $logfile .= 'mail_log.txt';
    else if ($section == LogType::MySQL) $logfile .= 'mysql_log.txt';

    file_put_contents($logfile, $arg."\n", FILE_APPEND);
  }
}
