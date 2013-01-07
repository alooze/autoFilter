//<?php
/**
 * cleanAFCache plugin
 * Данный файл является частью расширения autoFilter для MODX Evolution
 * @Author: alooze (a.looze@gmail.com)
 * @Version: 0.7a
 * @Date: 05.01.2013
 */

$e = &$modx->Event;
$output = "<br><br>";
if ($e->name == 'OnSiteRefresh'){
  $output.= "Очистка кеша авто-фильтров: ";
  $dirName = MODX_BASE_PATH.'assets/snippets/autoFilter/.cache';
  if (is_dir($dirName)) {
    $i = 0;
    $files = glob($dirName."/*");
    if (!is_array($files)) return;
    foreach ($files as $filename) {
      unlink($filename);
      $i++;
    }
    $output.= "удалено <b>".$i."</b> файлов";
  } else {
    $output.= "директория <b>".$dirName."</b> не найдена";
  }
}
echo $output;
