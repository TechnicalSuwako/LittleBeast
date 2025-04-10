<?php
namespace Site\Controller;

use Site\Lib\Template;
use Site\Controller\Mods;

class Page extends Mods {
  public function about(array $params): void {
    try {
      $tmpl = new Template('/');
      $pagetit = '新ページ';
      $description = 'PHPフレームワークについて';

      $tmpl->assign('pagetit', $pagetit);
      $tmpl->assign('curPage', 'about');
      $tmpl->assign('custCss', true);
      $tmpl->assign('menu', $this->getMenu());
      $tmpl->assign('description', $description);

      $tmpl->render('about');
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }
}
?>
