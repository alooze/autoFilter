<?php
/**
 * autoFilter snippet
 * Данный файл является частью расширения autoFilter для MODX Evolution
 * @Author: alooze (a.looze@gmail.com)
 * @Version: 0.7a
 * @Date: 05.01.2013
 */

//1. Получаем параметры сниппета и проверяем их
/**
 * Путь к файлам
 */
$path = isset($path) ? $path : 'assets/snippets/autoFilter/';
$path = MODX_BASE_PATH.$path;
if (!is_dir($path)) return 'Не найден путь к файлам autoFilter (указан '.$path.')';

/**
 * Параметры запуска
 */
$parents = isset($parents) ? $parents : 0; //папки (папки в дереве modx) с категориями товаров
$itemIds = isset($itemIds) ? $itemIds : ''; //товары через запятую

/**
 * В зависимости от режима формируем список товаров для фильтрации
 */
$config['mode'] = isset ($mode) ? $mode : 'base';

switch ($config['mode']) {
  case 'shopkeeper':
    //выборка товаров из таблицы shopkeeper, родительская категория - в дереве MODX

    //получаем родительские категории из параметров
    $parAr = explode(',', $parents);
    foreach ($parAr as $parId) {
      if (intval($parId) > 0) {
        $chAr = $modx->getChildIds($parId);
        if (is_array($chAr)) {
          $parentsAr[] = implode(',', $chAr);
        }
      }
    }
    if (is_array($parentsAr) && count($parentsAr) > 0) {
      $parentsAr = array_merge($parentsAr, $parAr);
    } else {
      $parentsAr = $parAr;
    }

    $parents = implode(',', $parentsAr);

    //добавляем товары из полученных категорий к товарам, заданным непосредственно по id
    if ($parents != 0 && $parents != '') {
      $ids = explode(',', $itemIds);
      $q = "SELECT id FROM ".$modx->getFullTableName('catalog')." WHERE parent IN (".$parents.")";
      $r = $modx->db->query($q);
      while($row = $modx->db->getRow($r)) {
        $ids[] = $row['id'];
      }
      if (is_array($ids)) {
        $itemIds = implode(',', $ids);
        $itemIds = trim($itemIds, ',');
      }
    }
  break;

  case 'ep':
    //выборка товаров из дерева MODX, опциями являются расширенные параметры и TV

  case 'base':
  default:
    //выборка товаров из дерева MODX, опциями являются TV
    $parAr = explode(',', $parents);
    foreach ($parAr as $parId) {
      if (intval($parId) > 0) {
        $chAr = $modx->getChildIds($parId);
        if (is_array($chAr)) {
          $itemIds.= implode(',', $chAr);
        }
      }
    }
  break;
}

if ($itemIds == '') {
  //echo 'Не указаны id товаров для построения фильтров';
  $modx->webAlert('Не указаны id товаров для построения фильтров');
  return;
}


//2. Добавляем в конфиг параметры вызова
$config['path'] = $path;
$config['ids'] = $itemIds;

//для мультикатегорий задаем TV с родителями
$config['parentTv'] = isset($parentTv) ? $parentTv : false;
//символ, который "оборачивает" каждый из id родителей в TV с доп.категориями
$config['parentTvWrapper'] = isset($parentTvWrapper) ? $parentTvWrapper : ':';
//символ, который разделяет id родителей в TV с доп.категориями
$config['parentTvSplitter'] = isset($parentTvSplitter) ? $parentTvSplitter : ',';

$config['confFile'] = isset($confFile) ? $confFile : $path.'config.inc.txt';
$config['skipFolders'] = isset($skipFolders) ? $skipFolders : 1; //не включать папки
$config['includeTv'] = isset($includeTv) ? $includeTv : ''; //включать TV
$config['excludeTv'] = isset($excludeTv) ? $excludeTv : ''; //исключать TV
$config['id'] = isset($id) ? $id : 'af'; //id вызова сниппета на странице
$config['saveState'] = isset($saveState) ? $saveState : 0; //сохраненное состояние фильтров

$config['hideImpossible'] = isset($hideImpossible) ? $hideImpossible : 0; //скрывать невозможные комбинации опций
$config['markImpossible'] = isset($markImpossible) ? $markImpossible : 0; //помечать новозможные для использования опции

$config['customFilter'] = isset($customFilter) ? $customFilter : ''; //пользовательские функции фильтрации

$config['cacheResult'] = isset($cacheResult) ? $cacheResult : '0'; //сохранять результаты в кеш
$config['cache_max_age'] = isset($cacheAge) ? $cacheAge : '0'; //время жизни кеша, 0 - пока не сбросят принудительно
$config['cacheKeys'] = isset($cacheKeys) ? $cacheKeys : 'prs'; //источники генерации ключей сессии

//устанавливаем предфильтры
$config['preFilter'] = isset($preFilter) ? $preFilter : '';
//параметры запроса
$config['saveState'] = isset($saveState) ? $saveState : 0;
$config['resetStateKey'] = isset($resetStateKey) ? $resetStateKey : 'Reset';
$config['saveStateKey'] = isset($saveStateKey) ? $saveStateKey : 'start';

//параметры возвращаемых данных
$config['delim'] = isset($delim) ? $delim : ',';

//параметры вывода данных (шаблоны)
$config['showForm'] = isset($showForm) ? $showForm : 1; //показывать форму фильтрации
$config['formTpl'] = isset ($formTpl) ? $formTpl : '@FILE '.$config['path'].'templates/formTpl.inc.php'; //шаблон формы фильтрации

$config['parseTpl'] = isset($parseTpl) ? $parseTpl : '@CODE Найдены [+'.$config['id'].'.items_show_count+] товаров из [+'.$config['id'].'.items_count+]: ids = [+'.$config['id'].'.items_str+]'; //чанк для вывода результата

$config['showResultOnRun'] = isset($showResultOnRun) ? $showResultOnRun : '1'; //показывать результаты при первой загрузке

$config['noEmptyIds'] = isset($noEmptyIds) ? $noEmptyIds : '0'; //не допускать пустой строки с id (будет заменяться '00')

$config['where'] = isset($where) ? $where : ''; //для предварительной фильтрации при выборке из БД

//3. Формируем вывод в отдельном файле
include_once $path.'autoFilter.inc.php';

//4. Заполняем необходимые плейсхолдеры
$modx->setPlaceholder($config['id'].'.form', $formBlock);
$modx->setPlaceholder($config['id'].'.result', $resultBlock);
$modx->setPlaceholder($config['id'].'.items', $itemsStr);
$modx->setPlaceholder($config['id'].'.items_str', $itemsStr);
$modx->setPlaceholder($config['id'].'.items_count', $itemsCnt);
$modx->setPlaceholder($config['id'].'.items_show_count', $itemsShowCnt);

return;
?>