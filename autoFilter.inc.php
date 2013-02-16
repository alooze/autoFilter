<?php
/**
 * autoFilter inc
 * Данный файл является частью расширения autoFilter для MODX Evolution
 * @Author: alooze (a.looze@gmail.com)
 * @Version: 0.7a
 * @Date: 05.01.2013
 */

// Параметры вызова уже переданы в конфиг, готовим данные для вывода результатов

// Проверяем, надо ли загружать сохраненное состояние для списка из параметра saveState
/*$saveAr = explode(',', $config['saveStateKey']);
$loadStateNeed = false; //флаг для загрузки состояния
foreach ($saveAr as $key) {
  if ($key != '' && isset($_REQUEST[$key])) {
    $loadStateNeed = true;
    break;
  }
}*/



//1. Формируем карту свойств

//проверяем наличие файла с классами
if (!class_exists('OptionsMap')) {
  $forInc = $config['path'].'classes/OptionsMap.class.inc.php';
  if (file_exists($forInc)) {
    include_once $forInc;
  } else {
    die('Нет необходимых файлов для создания фильтрации');
  }
}

// Подключаем необходимый класс для выборки данных
switch ($config['mode']) {
  case 'shopkeeper':
    //таблицы shopkeeper, родительская категория - в дереве MODX
    if (!class_exists('OptionsMapShk')) {
      die('Класс для режима фильтрации '.$config['mode'].' не найден.');
    }
    $opt = new OptionsMapShk($config);
  break;

  case 'ep':
    //таблицы shopkeeper, родительская категория - в дереве MODX
    if (!class_exists('OptionsMapEp')) {
      die('Класс для режима фильтрации '.$config['mode'].' не найден.');
    }
    $opt = new OptionsMapEp($config);
  break;

  case 'base':
  default:
    //таблицы shopkeeper, родительская категория - в дереве MODX
    if (!class_exists('OptionsMapBase')) {
      die('Класс для режима фильтрации '.$config['mode'].' не найден.');
    }
    $opt = new OptionsMapBase($config);
  break;
}

/*print_r($opt);
die();*/
//проверяем наличие готовых html блоков в кеше

if ($dataAr = $opt->getCachedResult()) {
  //в кеше есть актуальные данные
  //$dataAr = $opt->getCachedResult();
  // В $dataAr должен быть массив из блоков кода: для формы и для результатов фильтрации
  extract($dataAr);
} else {
  //кеш устарел или не существует

  //дорабатываем шаблоны вывода
  $resultBlockTpl = $opt->parser->strToTpl($config['parseTpl']);
  $formBlockTpl = $opt->parser->strToTpl($config['formTpl']);

  //предварительная подготовка шаблонов для расширенных параметров
  //echo $formBlockTpl;
  //echo '<br><br>';
  $formBlockTpl = $opt->preParse($formBlockTpl);
  //die($formBlockTpl);
  //получаем ключи фильтров из формы фильтрации
  $usedKeys = $opt->getTaskFromTpl($formBlockTpl); // 'optNN' => 'FILTER_KEY',...
  /*print_r($usedKeys);
  die();*/

  /*********************************
  Шпаргалка
  $opt->items = array ([docId] =>array (opt1=>opt1['value'],...),...)
  $opt->options = array ([optId] => array (doc1=>opt1['value'],...),...)
  $opt->map = array ([optId] => array (value1=>('doc1',...),...),...))
  **********************************/

  //2. Обрабатываем значения из HTTP запроса, сессии и предфильтров
  $fromForm = false;
  $filterAr = array();
  //приоритет по умолчанию: запрос > сессия > предфильтр
  if (isset($_REQUEST['afid']) && trim($_REQUEST['afid']) == $config['id'] && !isset($_REQUEST[$config['resetStateKey']])) {
    $fromForm = true;
    //есть данные HTTP-запроса, сброс параметров фильтра не запрошен
    //данные в запросе могут быть либо с именем вида "optNN_key" (NN - id опции/TV; key - тип фильтра)
    //либо "optparent_key", "opttemplate_key" (зависит от настроек выборки)
    //либо с любым именем, которое сопоставлено имени "opt###_key" в конфиге сниппета
    //(секция [html_names])

    if (!is_array($usedKeys) || empty($usedKeys)) {
      return 'Не найдены фильтры в шаблоне формы фильтрации';
    }

    foreach ($usedKeys as $optName => $filterKeyAr) {
      //в usedKeys должен прийти массив вида
      // optId => array('filterkey1', ...);
      //например, opt16 => array('select','custom');

      //if (isset($opt->param['fields'][$optName])) {
      //  $reqKey = trim($opt->param['fields'][$optName]);
      //} else {
        $reqKey = $optName;
      //}
      if (is_array($filterKeyAr)) {
        foreach ($filterKeyAr as $filterKey) {
          $tmpFilterAr = $opt->callRequestFunction($filterKey, $reqKey);
          $filterAr = array_merge($filterAr, $tmpFilterAr);
        }
      } else {
        //nothing here
      }
    }
    //print_r($filterAr);
    /*die();*/

    //сохраняем данные в сессию
    if ($config['saveState'] != 0 || $config['saveStateKey']) {
      $_SESSION[$config['id'].'Data'] = $filterAr;
      $_SESSION[$config['id'].'prevRequest'] = serialize($_REQUEST);
    }

  } else if (isset($_REQUEST[$config['resetStateKey']])) {
    //команда на сброс формы и фильтров
    $fromForm = true;
    unset($_SESSION[$config['id'].'Data']);
    unset($_SESSION[$config['id'].'prevRequest']);
  } else if (isset($_SESSION[$config['id'].'Data']) && is_array($_SESSION[$config['id'].'Data']) && ($config['saveState'] != 0 || $opt->loadStateNeedCheck())) {
    //есть данные в сессии, устанавливаем фильтр по ним
    $filterAr = $_SESSION[$config['id'].'Data'];
    $fromForm = true;
  } else if ($config['preFilter'] != '') {
    //первая загрузка формы или сброс параметров, есть данные предфильтра
    $pfAr = explode('|', $opt->param['preFilter']);
    foreach($pfAr as $pf) {
      list($name, $val) = explode(':', $pf);
      if (!isset($val) || ($val == '')) {
        continue;
      }
      if (strstr($val, ',')) {
        //есть запятая, делаем массив
        $val = explode(',', $val);
      }

      //получаем id опции по имени в конфиг-файле (если есть)
      $tmpAr = array_flip($opt->param['fields']);
      if (isset($tmpAr[$name])) {
        $reqKey = trim($tmpAr[$name]);
      } else {
        $reqKey = $name;
      }
      //подставляем данные из пред-фильтра
      $filterAr[$reqKey] = $val;
    }
  } else {
    //первая загрузка формы или сброс параметров, предфильтров нет
    unset($_SESSION[$config['id'].'Data']);
    unset($_SESSION[$config['id'].'prevRequest']);
    //ничего не делаем
  }


  //3. Формируем список id товаров, проходящих фильтрацию
  // и устанавливаем соответствующие плейсхолдеры результата

  //эталонный (полный) массив
  $allItemsAr = $opt->getAllItems();

  /*********************************
  Шпаргалка
  $opt->items = array ([docId] =>array (opt1=>opt1['value'],...),...)
  $opt->options = array ([optId] => array (doc1=>opt1['value'],...),...)
  $opt->map = array ([optId] => array (value1=>('doc1',...),...),...))
  **********************************/
  if (count($filterAr) >= 1) {
    /*print_r($filterAr);
    die();*/
    $filteredItemsAr = $opt->getFilteredIds($filterAr);
  } else {
    $filteredItemsAr = $allItemsAr;
  }
  //в массиве $filteredItemsAr сейчас каждый элемент - массив с отфильтрованными данными
  //по одному из фильтров

  if (count($filteredItemsAr) < 1) {
    $itemsStr = '';
  } else {
    $itemsStr = implode($config['delim'], $filteredItemsAr);
  }

  if ($config['noEmptyIds'] == 1 && $itemsStr == '') $itemsStr = '00';

  //устанавливаем плейсхолдеры
  $ph['items_count'] = count($allItemsAr);
  $ph['items_show_count'] = count($filteredItemsAr);
  $itemsCnt = count($allItemsAr);
  $itemsShowCnt = count($filteredItemsAr);
  $ph['id'] = $config['id'];
  $ph['items'] = $itemsStr;

  //блок с результатом фильтрации
  if (!$fromForm && $config['showResultOnRun'] == 0) {
    $resultBlock = '';
  } else {
    $resultBlock = $opt->parser->parseTpl($resultBlockTpl, array_keys($ph), array_values($ph), '[+'.$config['id'].'.');
  }

  //блок с формой фильтрации
  if ($config['showForm'] == 1) {
    //if ($config['hideImpossible'] == 1) {
      //создаем элементы формы
      //$tmpPhAr = $opt->createPossiblePlaceholders($filterAr);
      /*echo 'hideImpossible в данной версии отключено';
      die();
    } else {*/
      //создаем элементы формы
      $tmpPhAr = $opt->createPlaceholders($filterAr);
      //echo 'tmpPhAr = ';
      //print_r($tmpPhAr);
      //die();
    //}
    if (!$tmpPhAr) {
      echo $opt->parser->error;
      //echo 'Не найдены подстановщики для формы';
      //die();
    }
    if (!is_array($tmpPhAr)) {
      echo 'Parser AF error!';
      //die();
    }
    //добавляем элементы формы в плейсхолдеры
    $ph = array_merge($ph, $tmpPhAr);
    /*print_r($ph);
    die();*/
    $formBlock = $opt->parser->parseTpl($formBlockTpl, array_keys($ph), array_values($ph), '[+'.$config['id'].'.');
  } else {
    $formBlock = '';
  }

  //пишем данные в кеш
  $forCache['formBlock'] = $formBlock;
  $forCache['resultBlock'] = $resultBlock;
  $forCache['itemsStr'] = $itemsStr;
  $forCache['itemsCnt'] = $itemsCnt;
  $forCache['itemsShowCnt'] = $itemsShowCnt;
  $opt->setCachedResult($forCache);

}
?>