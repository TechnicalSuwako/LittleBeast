<?php
spl_autoload_register(function ($class) {
  $prefix = 'Site\\';
  $base = realpath(__DIR__.'/src');

  if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
  $ps = str_replace('\\', DIRECTORY_SEPARATOR, $class);
  $file = $base.DIRECTORY_SEPARATOR.$ps.'.php';
  if (file_exists($file)) require $file;
  else error_log("クラス{$class}を見つけられません。試したパス：{$file}");
});
?>
