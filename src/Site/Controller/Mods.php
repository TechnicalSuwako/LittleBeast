<?php
namespace Site\Controller;

class Mods {
  public function getMenu(): array {
    return [
      [
        'class' => 'menu-item',
        'href' => '/',
        'page' => 'blog',
        'text' => 'トップ',
        'show' => true,
      ],
      [
        'class' => 'menu-item',
        'href' => '/about',
        'page' => 'about',
        'text' => '自己紹介',
        'show' => true,
      ],
    ];
  }
}
?>
