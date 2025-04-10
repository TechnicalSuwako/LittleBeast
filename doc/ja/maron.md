# Maron テンプレートの仕組み
Maron は Little Beast のカスタム HTML テンプレートエンジンです。

## 構文

構文は以下の通りです：

```
{@ include(common/header.maron) @} // これは別の Maron ファイルを含めます。
                                   // シングルクォートやダブルクォートを
                                   // 追加しないで下さい！

{@ kys($var) @} // これは変数を読み易い形式で出力し、
                // その後テンプレートの残りの実行を終了します。

{@ if (true) @}
  // 何かを実行
{@ elif (null) @}
  // 別の事を実行
{@ else @}
  // その他を実行
{@ endif @}

{@ foreach ($array as $value) @}
{{ $value }}<br />
{@ endforeach @}

{@ foreach ($array as $key => $value) @}
{{ $key }}: {{ $value }}<br />
{@ endforeach @}

{@ for ($i = 0; $i < 10; $i++) @}
{{ $i }}<br />
{@ endfor @}

{# This is a comment #}
{{ $var }} // これは特殊文字をエスケープして変数を出力します
{{{ $var }}} // これは変数をそのままの形で出力します
{$ $var = 200 $} // これは $var を 200 として定義します
{! echo "hello world"; !} // これは生の PHP コードをそのまま実行します
```

## コントローラー機能
更に、Little Beast では Maron テンプレート内で使用出来る
コントローラー用の機能も提供しています。
多言語ウェブサイトを使用する場合、
`new Template()` の後にその言語のルートディレクトリを指定出来ます。
但し、これには `/view` ディレクトリ内にそれらのルートディレクトリも
設定する必要があります。
例えば、構造が `/view/en/index.maron` の場合、
`new Template('/en')` と定義します。
然し構造が `/view/index.maron` の場合は、
単に `new Template()` とします。

### assign()
`->assign()` メソッドを使用すると、
Maron テンプレートで使用したい変数を割り当てます。

例：
```
$tmpl = new Template();

$sum = 1 + 1;
$tmpl->assign('total', $sum);
```

この場合、Maron テンプレートで `{{ $total }}` を
使用して `2` を出力します。

### addCss()
デフォルトでは、 `style.css` は常に含まれています。
但し、全てのページ又は大部分のページで使用する
予定の物だけを `style.css` に入れ、
特定のページでのみ使用する予定の全ての物は、
`style-` の接頭辞を付けた独自のファイルに入れる事をお勧めします。
例えば、お問い合わせページがあり、
そのページでのみ使用する CSS がある場合は、
`/public/static` に新しい `style-contact.css` ファイルを作成し、
`render` メソッドの前に `$tmpl->addCss('contact');` を追加します。

### render()
`render()` メソッドは Maron テンプレートの内容を取得し
、ウェブブラウザが理解出来る有効な HTML と PHP に全てを変換します。
Maron ファイルが `/view/index.maron` の場合、
`$tmpl->render('index');` を実行しますが、
`/view/en/index.maron` の場合、
`$tmpl = new Template('/en');` に続いて `$tmpl->render('index');` か、
`$tmpl = new Template();` に続いて `$tmpl->render('en/index');` の
いずれかを実行出来ます。
