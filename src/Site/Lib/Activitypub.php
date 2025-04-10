<?php
namespace Site\Lib;

use Site\Lib\Curl;

/**
 * ActivityPubプロトコルの実装クラス
 * 
 * このクラスはActivityPubプロトコルを利用して分散型
 * ソーシャルネットワーキングを実装します。
 */
class ActivityPub {
  private string $domain;
  private string $actor;
  private string $actorNick;
  private string $desc;
  private string $icon;
  private array $posts = [];

  /**
   * コンストラクタ
   *
   * @param array $posts  投稿データの配列
   */
  public function __construct(array $posts = []) {
    $this->domain = $_SERVER['SERVER_NAME'];
    $this->actor = FEDIINFO['actor'];
    $this->actorNick = FEDIINFO['actorNick'];
    $this->desc = FEDIINFO['desc'];
    $this->icon = "https://{$this->domain}".FEDIINFO['icon'];
    $this->posts = $posts;
  }

  /**
   * ActivityPubアクタープロフィールを受け取る
   *
   * @return string  アクターオブジェクト
   * @throws \Exception  公開鍵の読み込みに失敗した場合
   */
  public function getActor(): string {
    $pubkey = file_get_contents(FEDIINFO['pubkey']);
    if ($pubkey === false) {
      throw new \Exception('公開鍵の受取に失敗。パス：'.FEDIINFO['pubkey']);
    }

    $actor = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/actor",
      'name' => $this->actorNick,
      'summary' => $this->desc,
      'manuallyApprovesFollowers' => false,
      'icon' => [
        'type' => 'Image',
        'mediaType' => 'image/png',
        'url' => $this->icon,
      ],
      'image' => [
        'type' => 'Image',
        'url' =>
          "https://{$this->domain}/static/article/o_53803618dc1691.28179609.jpg",
        'mediaType' => 'image/jpeg',
      ],
      'type' => 'Person',
      'url' => "https://{$this->domain}",
      'preferredUsername' => $this->actor,
      'inbox' => "https://{$this->domain}/ap/inbox",
      'outbox' => "https://{$this->domain}/ap/outbox",
      'followers' => "https://{$this->domain}/ap/followers",
      'following' => "https://{$this->domain}/ap/following",
      'published' => '2025-03-28T18:00:00Z',
      'updated' => gmdate('c'),
      'publicKey' => [
        'id' => "https://{$this->domain}/ap/actor#main-key",
        'owner' => "https://{$this->domain}/ap/actor",
        'publicKeyPem' => $pubkey,
      ],
    ];

    return json_encode($actor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * 特定のUUIDに対応するActivityを取得する
   *
   * @param string $uuid  取得するアクティビティのUUID
   * @return string  JSONエンコードされたアクティビティデータ
   */
  public function getActivity(string $uuid): string {
    $items = [];

    foreach ($this->posts as $post) {
      if ($post['uuid'] != $uuid) continue;

      $items = [
        '@context' => [
          'https://www.w3.org/ns/activitystreams',
          'https://w3id.org/security/v1',
          [
            'Emoji' => 'toot:Emoji',
            'EmojiReact' => 'litepub:EmojiReact',
            'Hashtag' => 'as:Hashtag',
            'litepub' => 'http://litepub.social/ns#',
            'sensitive' => 'as:sensitive',
            'toot' => 'http://joinmastodon.org/ns#',
          ],
        ],
        'id' => "https://{$this->domain}/ap/activities/create/{$post['uuid']}",
        'type' => 'Create',
        'actor' => "https://{$this->domain}/ap/actor",
        'cc' => [
          "https://{$this->domain}/ap/followers",
        ],
        'published' => date("Y-m-d\TH:i:s.u\Z", strtotime($post['date'])),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'object' => [
          'id' => "https://{$this->domain}/ap/objects/{$post['uuid']}",
          'type' => 'Note',
          'name' => $post['title'],
          'attributedTo' => "https://{$this->domain}/ap/actor",
          'cc' => [
            "https://{$this->domain}/ap/followers",
          ],
          'to' => ['https://www.w3.org/ns/activitystreams#Public'],
          'content' =>
            $post['preview']."<br /><br /><a href=\"https://{$this->domain}/blog/{$post['slug']}\">読み続き</a>",
          'url' => "https://{$this->domain}/blog/{$post['slug']}",
          'published' => date("Y-m-d\TH:i:s.u\Z", strtotime($post['date'])),
          'replies' => "https://{$this->domain}/ap/objects/{$uuid}/replies",
          'sensitive' => false,
        ],
      ];

      if (isset($post['category']) && !empty($post['category'])) {
        $item['tag'] = [];
        foreach ($post['category'] as $cat) {
          $items['tag'][] = $cat;
        }
      }

      if (isset($post['thumbnail']) && $post['thumbnail'] != '') {
        $imgurl = "https://technicalsuwako.moe/static/article/{$post['thumbnail']}";
        $imgpath = ROOT."/public/static/article/{$post['thumbnail']}";
        $imgraw = file_get_contents($imgpath);

        $items['attachment'] = [
          [
            'digestMultibase' => 'z'.base58btc_encode(hash('sha256', $imgraw, true)),
            'mediaType' => mime_content_type($imgpath),
            'type' => "Image",
            'url' => $imgurl,
          ],
        ];
      }

      break;
    }

    return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * アウトボックスデータを取得する
   *
   * @return string  JSONエンコードされたアウトボックスデータ
   */
  public function getOutbox(): string {
    $items = [];
    $counter = 0;

    foreach ($this->posts as $post) {
      $uid = $post['uuid'];

      $items[] = [
        '@context' => [
          'https://www.w3.org/ns/activitystreams',
          'https://w3id.org/security/v1',
          [
            'Emoji' => 'toot:Emoji',
            'EmojiReact' => 'litepub:EmojiReact',
            'Hashtag' => 'as:Hashtag',
            'litepub' => 'http://litepub.social/ns#',
            'sensitive' => 'as:sensitive',
            'toot' => 'http://joinmastodon.org/ns#',
          ],
        ],
        'id' => "https://{$this->domain}/ap/activities/create/{$uid}",
        'type' => 'Create',
        'actor' => "https://{$this->domain}/ap/actor",
        'cc' => [
          "https://{$this->domain}/ap/followers",
        ],
        'published' => date("Y-m-d\TH:i:s.u\Z", strtotime($post['date'])),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'object' => [
          'id' => "https://{$this->domain}/ap/objects/{$uid}",
          'type' => 'Note',
          'name' => $post['title'],
          'attributedTo' => "https://{$this->domain}/ap/actor",
          'cc' => [
            "https://{$this->domain}/ap/followers",
          ],
          'to' => ['https://www.w3.org/ns/activitystreams#Public'],
          'content' =>
            $post['preview']."<br /><br /><a href=\"https://{$this->domain}/blog/{$post['slug']}\">読み続き</a>",
          'url' => "https://{$this->domain}/blog/{$post['slug']}",
          'published' => date("Y-m-d\TH:i:s.u\Z", strtotime($post['date'])),
          'replies' => "https://{$this->domain}/ap/objects/{$uid}/replies",
          'sensitive' => false,
        ],
      ];

      if (isset($post['category']) && !empty($post['category'])) {
        $items[$counter]['tag'] = [];
        foreach ($post['category'] as $cat) {
          $items[$counter]['tag'][] = $cat;
        }
      }

      if (isset($post['thumbnail']) && $post['thumbnail'] != '') {
        $imgurl = "https://technicalsuwako.moe/static/article/{$post['thumbnail']}";
        $imgpath = ROOT."/public/static/article/{$post['thumbnail']}";
        $imgraw = file_get_contents($imgpath);

        $items[$counter]['attachment'] = [
          [
            'digestMultibase' => 'z'.base58btc_encode(hash('sha256', $imgraw, true)),
            'mediaType' => mime_content_type($imgpath),
            'type' => "Image",
            'url' => $imgurl,
          ],
        ];
      }

      $counter++;
    }

    $outbox = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/outbox",
      'type' => 'OrderedCollection',
      'totalItems' => count($items),
      'orderedItems' => $items,
    ];

    return json_encode($outbox, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * WebFingerデータを取得する
   *
   * @return string  JSONエンコードされたWebFingerデータ
   */
  public function getWebfinger(): string {
    $webfinger = [
      'subject' => "acct:{$this->actor}@{$this->domain}",
      'links' => [
        [
          'rel' => 'self',
          'type' => 'application/activity+json',
          'href' => "https://{$this->domain}/ap/actor",
        ],
      ],
    ];

    return json_encode($webfinger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * フォロワーのリストを取得する
   *
   * @return string  JSONエンコードされたフォロワーのリスト
   */
  public function getFollowers(): string {
    $f = array_filter(explode("\n", file_get_contents(ROOT.'/data/followers.txt')));

    $followers = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/followers",
      'type' => 'OrderredCollection',
      'totalItems' => count($f),
      'orderedItems' => $f,
    ];

    return json_encode($followers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * フォローしているアカウントのリストを取得する
   *
   * @return string  JSONエンコードされたフォローリスト
   */
  public function getFollowing(): string {
    $f = array_filter(explode("\n", file_get_contents(ROOT.'/data/following.txt')));

    $following = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/following",
      'type' => 'OrderredCollection',
      'totalItems' => count($f),
      'orderedItems' => $f,
    ];

    return json_encode($following, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * インボックスにアクティビティを投稿する
   *
   * @param array $activity  処理するアクティビティデータ
   * @return void
   */
  public function postInbox(array $activity): void {
    switch ($activity['type']) {
    case 'Follow':
      $this->acceptFollower($activity);
      break;
    default:
      header('HTTP/1.1 501 Not Implemented');
      header('Content-Type: application/activity+json');
      echo json_encode(['error' =>
        '未対応なアクティビティタイプ: '.$activity['type']]);
      exit;
    }

    header('HTTP/1.1 200 OK');
    header('Content-Type: application/activity+json');
    echo json_encode(['status' => 'OK'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
  }

  /**
   * アクタープロフィールの更新アクティビティを作成する
   *
   * @return string  JSONエンコードされた更新アクティビティ
   */
  public function update(): string {
    $update = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/activities/update/".uuid(),
      'type' => 'Update',
      'actor' => "https://{$this->domain}/ap/actor",
      'to' => ['https://www.w3.org/ns/activitystreams#Public'],
      'object' => json_decode($this->getActor(), true),
    ];

    return json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * アクター更新をフォロワーに送信する
   *
   * @param array $params  パラメータ配列
   * @return void
   */
  public function sendActorUpdate(array $params): void {
    $f = array_filter(explode("\n", file_get_contents(ROOT.'/data/followers.txt')));
    $ap = new Activitypub();
    $inboxes = implode("\n", $f);
    $update = json_decode($ap->update(), true);

    foreach ($f as $inbox) {
      $this->sendActivity($inbox, $update);
    }
  }

  // 機能性メソッド

  /**
   * 指定されたインボックスURLにアクティビティを送信する
   *
   * @param string $inboxUrl  送信先のインボックスURL
   * @param array $activity  送信するアクティビティデータ
   * @return void
   */
  private function sendActivity(string $inboxUrl, array $activity): void {
    $privFile = FEDIINFO['privkey'];
    $priv = file_get_contents($privFile);
    if ($priv === false) {
      logger(\LogType::ActivityPub, "エラー：秘密鍵「{$privFile}」の読込に失敗");
      header('HTTP/1.1 500 Internal Server Error');
      header('Content-Type: application/activity+json');
      echo json_encode(['error' => '秘密鍵の読込に失敗']);
      exit;
    }

    $body = json_encode($activity, JSON_UNESCAPED_SLASHES);
    $digest = base64_encode(hash('sha256', $body, true));
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $host = parse_url($inboxUrl, PHP_URL_HOST);

    $headers = [
      'Host' => $host,
      'Date' => $date,
      'Content-Type' => 'application/activity+json',
      'Digest' => "SHA-256=$digest",
    ];

    $stringToSign = "host: {$headers['Host']}\n"
      ."date: {$headers['Date']}\n"
      ."digest: {$headers['Digest']}";
    logger(\LogType::ActivityPub, "署名対象: {$stringToSign}");

    if (!openssl_sign($stringToSign, $signature, $priv, OPENSSL_ALGO_SHA256)) {
      $error = openssl_error_string();
      logger(\LogType::ActivityPub, "エラー：署名に失敗: {$error}");
      header('HTTP/1.1 500 Internal Server Error');
      header('Content-Type: application/activity+json');
      echo json_encode(['error' => '署名に失敗']);
      exit;
    }

    $sigValue = base64_encode($signature);
    $headers['Signature'] = "keyId=\"https://{$this->domain}/ap/actor#main-key\",";
    $headers['Signature'] .= 'algorithm="rsa-sha256",';
    $headers['Signature'] .= 'headers="host date digest",';
    $headers['Signature'] .= 'signature="'.$sigValue.'"';
    logger(\LogType::ActivityPub,
      "署名: {$headers['Signature']}\n送信データ: {$body}");

    $curl = new Curl($inboxUrl);
    $curl->setMethod('POST')
         ->setPostRaw($body)
         ->setHeaders(array_map(fn($k, $v) => "$k: $v",
                      array_keys($headers), $headers))
         ->setCaInfo('/etc/ssl/cert.pem')
         ->setVerbose(true)
         ->setStderr(fopen(ROOT.'/log/ap_log.txt', 'a'));

    $success = $curl->execute();
    $res = $curl->getResponseBody();
    $code = $curl->getResponseCode();
    $err = $curl->getError();

    var_dump(print_r($res));
    logger(\LogType::ActivityPub,
      "アクティビティは「{$inboxUrl}」に送信しました： HTTP {$code}");
    logger(\LogType::ActivityPub, "エラー： {$err}");
    logger(\LogType::ActivityPub, "レスポンス: {$res}");
  }

  /**
   * フォロワーを受け入れる
   *
   * @param array $activity  フォローアクティビティデータ
   * @return void
   */
  private function acceptFollower(array $activity): void {
    $followerActor = $activity['actor'] ?? null;
    if (!$followerActor) {
      header('HTTP/1.1 400 Bad Request');
      header('Content-Type: application/activity+json');
      echo json_encode(['error' => 'アクターがない']);
      exit;
    }

    $this->storeFollower($followerActor);

    $inbox = $this->getInboxFromActor($followerActor);
    if (!$inbox) {
      header('HTTP/1.1 500 Internal Server Error');
      header('Content-Type: application/activity+json');
      echo json_encode(['error' => 'フォロワーの受付ボックスの受取に失敗']);
      exit;
    }

    $accept = [
      '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
      ],
      'id' => "https://{$this->domain}/ap/activities/".uniqid(),
      'type' => 'Accept',
      'actor' => "https://{$this->domain}/ap/actor",
      'object' => $activity,
    ];

    $this->sendActivity($inbox, $accept);
  }

  /**
   * フォロワーをストレージに保存する
   *
   * @param string $followerActor  フォロワーのアクターURL
   * @return void
   */
  private function storeFollower(string $followerActor): void {
    $file = ROOT.'/data/followers.txt';
    if (!file_exists($file)) {
      touch($file);
      chmod($file, 0644);
    }

    $followers = $this->getFollowersList();
    if (!in_array($followerActor, $followers)) {
      file_put_contents($file, "$followerActor\n", FILE_APPEND);
    }
  }

  /**
   * フォロワーのリストを配列として取得する
   *
   * @return array  フォロワーのURLの配列
   */
  private function getFollowersList(): array {
    $file = ROOT.'/data/followers.txt';
    $f = array_filter(explode("\n", file_get_contents($file)));
    return file_exists($file)
      ? array_filter(explode("\n", file_get_contents($file))) : [];
  }

  /**
   * アクターのインボックスURLを取得する
   *
   * @param string $actor  アクターのURL
   * @return string|null  インボックスURL、取得に失敗した場合はnull
   */
  private function getInboxFromActor(string $actor): ?string {
    $curl = new Curl($actor);
    $curl->setHeaders(['Accept: application/activity+json'])
         ->setFollowRedirects(true)
         ->setMaxRedirects(5)
         ->setCaInfo('/etc/ssl/cert.pem');

    logger(\LogType::ActivityPub, "アクターURLにリクエスト: {$actor}");
    $success = $curl->execute();
    if (!$success) {
      logger(\LogType::ActivityPub, "アクターリクエストに失敗: " . $curl->getError());
      return null;
    }

    $res = $curl->getResponseBody();
    $code = $curl->getResponseCode();
    $err = $curl->getError();

    if ($code !== 200) {
      logger(\LogType::ActivityPub, "アクター取得に失敗: HTTP {$code}, エラー: {$err}");
      return null;
    }

    $data = json_decode($res, true);
    return $data['inbox'] ?? null;
  }
}