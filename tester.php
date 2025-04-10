<?php
require_once __DIR__.DIRECTORY_SEPARATOR.'/autoload.php';

$testDir = __DIR__.DIRECTORY_SEPARATOR.'src/Site/Test';
$testFiles = glob($testDir.'/*.php');

if (empty($testFiles)) {
  echo "テストファイルは{$testDir}にありません\n";
  exit(1);
}

echo "{$testDir}にある".count($testFiles)."個テストファイルを実行中:\n";
echo "------------------------------------------------\n";

$totalFiles = 0;
$successFiles = 0;
$failedFiles = [];

foreach ($testFiles as $testFile) {
  $filename = basename($testFile);
  echo "{$filename}のテストを実行中... ";
  
  $totalFiles++;
    
  try {
    ob_start();
    require $testFile;
    $output = ob_get_clean();
        
    echo "完成\n";
    echo $output;
    echo "\n";

    $successFiles++;
  } catch (\Throwable $e) {
    $output = ob_get_clean();
    if (!empty($output)) {
      echo $output . "\n";
    }
    echo "エラー: " . $e->getMessage() . "\n";
    echo "ファイル: " . $e->getFile() . " (行: " . $e->getLine() . ")\n\n";
    $failedFiles[] = [
      'file' => $filename,
      'error' => $e->getMessage(),
      'line' => $e->getLine(),
    ];
  }
}

echo "\n";
echo "テスト結果:\n";
echo "------------------------------------------------\n";
echo "テストファイル数: {$totalFiles}\n";
echo "成功: {$successFiles}\n";
echo "失敗: ".count($failedFiles)."\n";

if (!empty($failedFiles)) {
  echo "\n失敗したテストファイル:\n";
  foreach ($failedFiles as $failed) {
    echo "- {$failed['file']} (行: {$failed['line']}): {$failed['error']}\n";
  }
}
?>
