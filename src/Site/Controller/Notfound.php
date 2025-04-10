<?php
namespace Site\Controller;

use Site\Controller\Mods;
use Site\Lib\Template;

class Notfound extends Mods {
  public function show(): void {
    try {
      $tmpl = new Template('/');
      $pagetit = 'Not found';

      $tmpl->assign('pagetit', $pagetit);
      $tmpl->assign('curPage', '404');
      $tmpl->assign('menu', $this->getMenu());
      $tmpl->assign('description', '');

      $tmpl->render('404');
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }
}
?>
