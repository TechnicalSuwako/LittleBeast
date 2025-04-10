<?php
namespace Site\Lib;

class Markdown {
  private string $content;
  private array $html;
  private string $path;
  private bool $inCodeBlock = false;
  private string $codeBlockLanguage = '';
  private array $codeBlockContent = [];
  private const METADATA_LINE = "----";

  public function __construct(string $path, ?string $lang = null) {
    $this->html = [];
    if ($lang) $this->path = ROOT.'/blog/'.$lang.'/'.$path.'.md';
    else $this->path = ROOT.'/blog/'.$path.'.md';

    if (!file_exists($this->path)) {
      header('Location: /404');
      exit();
    }
  }

  /**
   * メタデータを取得する
   * 
   * @return \stdClass  メタデータオブジェクト
   */
  public function getMetadata(): \stdClass {
    $content = file_get_contents($this->path);
    $metadata = new \stdClass();

    $parts = explode(self::METADATA_LINE, $content, 2);
    if (count($parts) < 2) return $metadata;

    $lines = explode("\n", trim($parts[0]));
    foreach ($lines as $line) {
      $line = trim($line);
      if (empty($line)) continue;

      $colonPos = strpos($line, ':');
      if ($colonPos === false) continue;

      $key = trim(substr($line, 0, $colonPos));
      $value = trim(substr($line, $colonPos + 1));
      $value = trim($value, '"\'');

      if ($key == 'category') {
        $cat = explode(',', $value);
        $value = $cat;
      }

      $metadata->$key = $value;
    }

    return $metadata;
  }

  /**
   * Markdownをパースする
   * 
   * @return string  HTMLとしてレンダリングされたコンテンツ
   */
  public function parse(): string {
    $content = file_get_contents($this->path);
    $parts = explode(self::METADATA_LINE, $content, 2);
    $this->content = count($parts) > 1 ? trim($parts[1]): trim($content);
    $this->html = [];

    $lines = explode("\n", $this->content);
    $currentParagraph = [];

    $inList = false;
    $listItems = [];
    $listLevel = 0;
    $inBlockquote = false;
    $blockquoteContent = [];
    $tableHeaders = [];
    $tableRows = [];
    $inTable = false;

    foreach ($lines as $line) {
      $hasBR = substr($line, -1) === '\\';
      $line = rtrim($line, " \t\r\n\\");

      // コードブロック
      if (preg_match('/^```(\w*)$/', $line, $matches)) {
        if (!$this->inCodeBlock) {
          if (!empty($currentParagraph)) {
            $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
            $currentParagraph = [];
          }
          $this->inCodeBlock = true;
          $this->codeBlockLanguage = $matches[1];
          continue;
        } else {
          $this->html[] = $this->createCodeBlock();
          $this->inCodeBlock = false;
          $this->codeBlockLanguage = '';
          $this->codeBlockContent = [];
          continue;
        }
      }

      if ($this->inCodeBlock) {
        $this->codeBlockContent[] = $line;
        continue;
      }

      // テーブルの処理
      if (preg_match('/^\|(.+)\|$/', $line)) {
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }
        $cells = array_map('trim', explode('|', trim($line, '|')));
                
        if (!$inTable) {
          $tableHeaders = $cells;
          $inTable = true;
        } elseif (preg_match('/^\|(\s*:?-+:?\s*\|)+$/', $line)) {
          // Skip separator line
          continue;
        } else {
          $tableRows[] = $cells;
        }
        continue;
      } elseif ($inTable) {
        $this->html[] = $this->createTable($tableHeaders, $tableRows);
        $tableHeaders = [];
        $tableRows = [];
        $inTable = false;
      }

      // 水平線の処理
      if (preg_match('/^([\-\*\_])\1{2,}$/', $line)) {
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }
        $this->html[] = "<hr>";
        continue;
      }

      // 引用ブロックの処理
      if (preg_match('/^>\s(.+)/', $line, $matches)) {
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }
        $inBlockquote = true;
        $blockquoteContent[] = $this->parseInline($matches[1]);
        continue;
      } elseif ($inBlockquote && empty($line)) {
        $this->html[] = $this->createBlockquote($blockquoteContent);
        $blockquoteContent = [];
        $inBlockquote = false;
        continue;
      }

      // 空行をスキップ
      if (empty($line)) {
        if ($inList) {
          $this->html[] = $this->createList($listItems);
          $listItems = [];
          $inList = false;
        }
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }

        continue;
      }

      // ヘッダー
      if (preg_match('/^(#{1,6})\s(.+)/', $line, $m)) {
        if ($inList) {
          $this->html[] = $this->createList($listItems);
          $listItems = [];
          $inList = false;
        }
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }

        $level = strlen($m[1]);
        $this->html[] = "<h{$level}>".$this->parseInline($m[2])."</h{$level}>";
        continue;
      }

      // 箇条書きリスト
      if (preg_match('/^(\s*)([\*\-])\s(.+)/', $line, $m)) {
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }
        $inList = true;
        $currentLevel = strlen($m[1]) / 2;
        $listLevel = max($listLevel, $currentLevel);
        $listItems[] = [
          'content' => $this->parseInline($m[3]),
          'level' => $currentLevel,
          'type' => 'ul',
        ];

        continue;
      }

      // 番号付きリスト
      if (preg_match('/^(\s*)\d+\.\s(.+)/', $line, $m)) {
        if (!empty($currentParagraph)) {
          $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";
          $currentParagraph = [];
        }
        $inList = true;
        $currentLevel = strlen($m[1]) / 2;
        $listLevel = max($listLevel, $currentLevel);
        $listItems[] = [
          'content' => $this->parseInline($m[2]),
          'level' => $currentLevel,
          'type' => 'ol',
        ];

        continue;
      }

      if ($inList) {
        $this->html[] = $this->createList($listItems);
        $listItems = [];
        $inList = false;
        $listLevel = 0;
      }

      $parsedLine = $this->parseInline($line);
      $currentParagraph[] = $parsedLine;
      if ($hasBR) {
        $currentParagraph[] = "<br />";
      }
    }

    if ($inList) $this->html[] = $this->createList($listItems);
    if ($inBlockquote) $this->html[] = $this->createBlockquote($blockquoteContent);
    if ($inTable) $this->html[] = $this->createTable($tableHeaders, $tableRows);
    if (!empty($currentParagraph))
      $this->html[] = "        <p>".implode("", $currentParagraph)."</p>";

    return implode("\n", $this->html);
  }

  // 機能性メソッド

  /**
   * インラインのMarkdown記法をパースする
   * 
   * @param string $text  パースするテキスト
   * @return string  HTMLとしてレンダリングされたテキスト
   */
  private function parseInline(string $text): string {
    // 太字
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

    // 斜体
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

    // 下線
    $text = preg_replace('/\_(.+?)\_/', '<u>$1</u>', $text);

    // 取り消し線
    $text = preg_replace('/\~(.+?)\~/', '<s>$1</s>', $text);

    // 画像
    $text = preg_replace('/\!\[(.*?)\]\((.+?)\)/', '<img style="width: 100%;" src="$2" alt="$1" />', $text);

    // 音楽
    $text = preg_replace('/\$\[([^\]]+)\]\(([^\)]+)\)/',
      '<audio controls><source src="$2" type="$1" /></audio>', $text);

    // 動画
    $text = preg_replace('/\#\[([^\]]+)\]\(([^\)]+)\)/',
      '<video controls><source src="$2" type="$1" /></video>', $text);

    // リンク
    $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);

    // インラインコード
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);

    return $text;
  }

  /**
   * リストを作成する
   * 
   * @param array $items  リストアイテムの配列
   * @param int $maxLevel  最大ネストレベル
   * @return string  HTMLのリスト
   */
  private function createList(array $items, int $maxLevel = 1): string {
    if (empty($items)) return '';

    $html = '';
    $currentLevel = 0;
    $listStack = [];
    $currentType = '';

    foreach ($items as $item) {
      $level = isset($item['level']) ? $item['level'] : $currentLevel;

      while ($currentLevel > $level)
        $html .= str_repeat('  ', $currentLevel)."</".array_pop($listStack).">\n";

      while ($currentLevel < $level) {
        $currentLevel++;
        $listStack[] = $item['type'];
        $html .= str_repeat('  ', $currentLevel - 1)."<".$item['type'].">\n";
      }

      if ($currentType != $item['type'] && $currentLevel == $item['level']) {
        if (!empty($listStack)) {
          $html .= str_repeat('  ', $currentLevel)."</".array_pop($listStack).">\n";
          $listStack[] = $item['type'];
          $html .= str_repeat('  ', $currentLevel - 1)."<".$item['type'].">\n";
        }
      }

      $currentType = $item['type'];
      $html .= str_repeat('  ', $currentLevel)."<li>".$item['content']."</li>\n";
    }

    while (!empty($listStack)) {
      $html .= str_repeat('  ', $currentLevel)."</".array_pop($listStack).">\n";
      $currentLevel--;
    }

    return rtrim($html);
  }

  /**
   * コードブロックを作成する
   * 
   * @return string  HTMLのコードブロック
   */
  private function createCodeBlock(): string {
        $code = htmlspecialchars(implode("\n", $this->codeBlockContent));
        $class = $this->codeBlockLanguage ? " class=\"language-{$this->codeBlockLanguage}\"" : '';
        return "<pre><code{$class}>{$code}</code></pre>";
  }

  private function createBlockquote(array $content): string {
    return "<blockquote>\n    <p>" . implode("</p>\n    <p>", $content) . "</p>\n</blockquote>";
  }

  /**
   * テーブルを作成する
   * 
   * @param array $headers  ヘッダー配列
   * @param array $rows  行データの配列
   * @return string  HTMLのテーブル
   */
  private function createTable(array $headers, array $rows): string {
    $html = "<table>\n";
        
    // ヘッダーを追加
    if (!empty($headers)) {
      $html .= "  <thead>\n    <tr>\n";
      foreach ($headers as $header) {
        $html .= "      <th>".$this->parseInline($header)."</th>\n";
      }
      $html .= "    </tr>\n  </thead>\n";
    }

    // 行を追加
    if (!empty($rows)) {
      $html .= "  <tbody>\n";
      foreach ($rows as $row) {
        $html .= "    <tr>\n";
        foreach ($row as $cell) {
          $html .= "      <td>".$this->parseInline($cell)."</td>\n";
        }
        $html .= "    </tr>\n";
      }
      $html .= "  </tbody>\n";
   }

   $html .= "</table>";
   return $html;
  }
}
