<?php
define('REPO_ROOT', '/repos/');
define('TWITTER_HANDLE', '@techsuwako');
define('SITEINFO', [
  'title' => 'Little Beast',
  'description' => 'PHP Framework',
  'tags' => 'game,tech,developer',
]);
define('MAILINFO', [
  'from' => 'hogehoge@ho.ge',
  'host' => 'smtp.ho.ge',
  'port' => 587,
  'user' => 'hogehoge@ho.ge',
  'pass' => 'hogehogehoge',
]);
define('FEDIINFO', [
  'actor' => 'loli',
  'actorNick' => 'ロリ',
  'desc' => '',
  'icon' => '/static/logo.png',
  'pubkey' => ROOT.'/public/static/pub.pem',
  'privkey' => ROOT.'/data/priv.pem',
]);
define('DBINFO', [
  'host' => 'localhost',
  'username' => 'littlebeast',
  'password' => '',
  'dbname' => 'littlebeast',
  'port' => 3306,
  'debug' => false,
]);

define('MAILER_ENABLED', false);
define('LOGGING_ENABLED', true);
define('ATOM_ENABLED', true);
define('RSS_ENABLED', false);
define('ACTIVITYPUB_ENABLED', false);
define('MYSQL_ENABLED', false);