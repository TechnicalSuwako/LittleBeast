<?php
namespace Site\Lib;

class Template {
  private string $tmplExt = '.maron';
  private string $tmplPath;
  private array $vars = [];
  private array $blocks = [];
  private array $custFunc = [];
  private array $custCss = [];

  /**
   * テンプレートクラスのコンストラクタ
   *
   * @param string $tmplPath テンプレートのパス
   * @return void
   */
  public function __construct(string $tmplPath) {
    $this->tmplPath = rtrim($tmplPath, '/');

    if (substr($this->tmplPath, 0, 1) !== '/') {
      $this->tmplPath = '/'.$this->tmplPath;
    }
  }

  /**
   * テンプレート変数に値を割り当てる
   *
   * @param string $name 変数名
   * @param mixed $value 値
   * @return void
   */
  public function assign(string $name, mixed $value): void {
    $this->vars[$name] = $value;
  }

  /**
   * カスタムCSSファイルを追加する
   *
   * @param string $name CSSファイル名
   * @return void
   */
  public function addCss(string $name): void {
    $this->custCss[] = 
      '<link rel="stylesheet" type="text/css" href="/static/style-'.$name.'.css" />';
    $this->assign('custCss', $this->custCss);
  }

  /**
   * カスタム関数を登録する
   *
   * @param string $name 関数名
   * @param callable $callback コールバック関数
   * @return void
   */
  public function registerFunction(string $name, callable $callback): void {
    $this->custFunc[$name] = $callback;
  }

  /**
   * テンプレートブロックを定義する
   *
   * @param string $name ブロック名
   * @param string $content ブロック内容
   * @return void
   */
  public function defineBlock(string $name, string $content): void {
    if (!isset($this->blocks[$name]))
      $this->blocks[$name] = $content;
  }

  /**
   * テンプレートをレンダリングする
   *
   * @param string $tmplName テンプレート名
   * @return void
   */
  public function render(string $tmplName): void {
    $tmplPath = ROOT.'/view'.$this->tmplPath.'/'.$tmplName.$this->tmplExt;
    if (!file_exists($tmplPath))
      throw new \RuntimeException("テンプレートファイルを見つけません：{$tmplPath}");

    extract($this->vars);

    $content = file_get_contents($tmplPath);

    // インクルードディレクティブを処理
    while (preg_match('/\{@\s*include\((.*?)\)\s*@\}/s', $content)) {
      $content = preg_replace_callback('/\{@\s*include\((.*?)\)\s*@\}/s', function($m) {
        $inclPath = ROOT.'/view/'.trim($m[1], "'\" ").$this->tmplExt;
        if (!file_exists($inclPath))
          throw new \RuntimeException("ファイルを見つけません： {$inclPath}");
        return file_get_contents($inclPath);
      }, $content);
    }

    $content = $this->procDirs($content);
    $content = $this->procVars($content);
    $content = $this->procFuncs($content);

    $tmpFile = tempnam(sys_get_temp_dir(), 'tmpl_');
    file_put_contents($tmpFile, $content);

    include $tmpFile;
    unlink($tmpFile);
  }

  // 機能性メソッド

  /**
   * テンプレートディレクティブを処理する
   *
   * @param string $content テンプレート内容
   * @return string|null 処理後の内容
   */
  private function procDirs(string $content): string|null {
    // includeディレクティブの処理
    while (preg_match('/\{@\s*include\((.*?)\)\s*@\}/s', $content)) {
      $content = preg_replace_callback('/\{@\s*include\((.*?)\)\s*@\}/s', function($m) {
        $inclPath = ROOT.'/view/'.trim($m[1], "'\" ").'.php';
        if (!file_exists($inclPath))
          throw new \RuntimeException("ファイルを見つけません： {$inclPath}");
        return file_get_contents($inclPath);
      }, $content);
    }

    $content = preg_replace('/\{@\s*if\s*\((.*?)\):\s*@\}/', '{@ if ($1) @}', $content);
    $content = preg_replace('/\{@\s*endif;\s*@\}/', '{@ endif @}', $content);

    $processDirectives = function($c) {
      // kysディレクティブの処理
      $c = preg_replace_callback('/\{@\s*kys\((.*?)\)\s*@\}/s', function($m) {
        return "<?php echo '<pre>'; print_r({$m[1]}); echo '</pre>'; die(); ?>";
      }, $c);

      // foreachループとネストした内容の処理
      $c = preg_replace_callback('/\{@\s*foreach\s*\((.*?)\)\s*@\}/s', function($m) {
        return "<?php foreach({$m[1]}): ?>";
      }, $c);
    
      $c = preg_replace_callback('/\{@\s*endforeach\s*@\}/s', function($m) {
        return "<?php endforeach; ?>";
      }, $c);
    
      // forループの処理
      $c = preg_replace_callback('/\{@\s*for\s*\((.*?)\)\s*@\}/s', function($m) {
        return "<?php for({$m[1]}): ?>";
      }, $c);
    
      $c = preg_replace_callback('/\{@\s*endfor\s*@\}/s', function($m) {
        return "<?php endfor; ?>";
      }, $c);
    
      // if-elif-else-endifの処理
      $c = preg_replace_callback('/\{@\s*if\s*\((.*?)\)\s*@\}/s', function($m) {
        return "<?php if ({$m[1]}): ?>";
      }, $c);
    
      $c = preg_replace_callback('/\{@\s*elif\s*\((.*?)\)\s*@\}/s', function($m) {
        return "<?php elseif ({$m[1]}): ?>";
      }, $c);
    
      $c = preg_replace_callback('/\{@\s*else\s*@\}/s', function($m) {
        return "<?php else: ?>";
      }, $c);
    
      $c = preg_replace_callback('/\{@\s*endif\s*@\}/s', function($m) {
        return "<?php endif; ?>";
      }, $c);
    
      return $c;
    };

    $previousContent = '';
    $maxIterations = 10;
    $iterations = 0;
  
    while ($previousContent !== $content && $iterations < $maxIterations) {
      $previousContent = $content;
      $content = $processDirectives($content);
      $iterations++;
    }

    return $content;
  }

  /**
   * テンプレート変数を処理する
   *
   * @param string $content テンプレート内容
   * @return string 処理後の内容
   */
  private function procVars(string $content): string {
    // 変数の出力（エスケープ処理なし）
    $content = preg_replace_callback('/\{\{\\{s*(.*?)\s*\}\}\}/', function($m) {
      return '<?= '.trim($m[1]).' ?>';
    }, $content);

    // 変数の出力（エスケープ処理あり）
    $content = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/', function($m) {
      return '<?= htmlspecialchars('.trim($m[1]).', ENT_QUOTES, \'UTF-8\') ?>';
    }, $content);

    // 変数の代入
    $content = preg_replace_callback('/\{\$\s*(.*?)\s*\$\}/', function($m) {
      $parts = explode('=', $m[1], 2);
      if (count($parts) !== 2)
        throw new \RuntimeException("不正な値の形式");
      return '<?php '.trim($parts[0]).' = '.trim($parts[1]).'; ?>';
    }, $content);

    // コメント
    $content = preg_replace_callback('/\{#\s*(.*?)\s*#\}/', function($m) {
      return '<?php /*'.trim($m[1]).'*/ ?>';
    }, $content);

    // PHPコードの実行
    $content = preg_replace_callback('/\{\!\s*(.*?)\s*\!\}/', function($m) {
      return '<?php '.trim($m[1]).' ?>';
    }, $content);

    return $content;
  }

  /**
   * カスタム関数を処理する
   *
   * @param string $content テンプレート内容
   * @return string 処理後の内容
   */
  private function procFuncs(string $content): string {
    foreach ($this->custFunc as $name => $cb) {
      $pattern = "/\{@\s*{$name}\((.*?)\)\s*@\}/";
      $content = preg_replace_callback($pattern, function($m) use ($cb) {
        $args = explode(',', $m[1]);
        $args = array_map('trim', $args);
        return call_user_func_array($cb, $args);
      }, $content);
    }

    return $content;
  }
}
