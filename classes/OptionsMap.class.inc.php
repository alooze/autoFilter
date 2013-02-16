<?php
/**
 * OptionsMap.class.inc.php
 * Class OptionsMap for autoFilter MODX Evo
 * @Author: alooze (a.looze@gmail.com)
 * @Version: 0.7a
 * @Date: 05.01.2013
 */

//*********************************
//1. Базовый класс для создания объекта-массива со свойствами товаров
//*********************************


abstract class OptionsMap {

  public $items; // массив свойств товаров
  public $options; //массив свойств опций
  public $map; //карта опций

  public $filteredIds = array(); //отфильтрованные товары
  public $currentMap = array(); //временная карта опций

  public $cache; //объект кеша
  public $parser; //встроенный парсер шаблонов

  public $param;
  private $sqlData;

  public $templates; //код шаблонов
  public $filters; //имена фильтров из файла с описаниями
  public $aliases; //имена полей из конфиг-файла
  public $loadStateNeed = false; //флаг, показывает, что нужно подгружать предыдущее состояние фильтров
  public $usedKeys; //массив соответствия опций и фильтров, перечисленных в шаблоне формы

  function __construct(array $config) {
    global $modx;

    //получаем параметры создания объекта
    $this->param = $config;

    //загружаем расширение для кеша
    if (!isset($this->param['cache_max_age'])) {
      $this->param['cache_max_age'] = 0; // 0 - хранить кеш до ручной очистки
    }
    $this->startCacheTool() or die('Не работает кеш');

    //создаем экземпляр парсера
    $this->startParser() or die('Не работает парсер');

    //получаем конфигурацию Sql
    $this->getSqlTask() or die('Bad autofilter config found!');

    //формируем карту свойств
    $sault = md5(serialize($this->param));
    $status = $this->cache->check_cache('map'.$sault);
    $status.= $this->cache->check_cache('items'.$sault);
    $status.= $this->cache->check_cache('options'.$sault);
    if ($status == 'HITHITHIT') {
      $this->map = $this->cache->get('map'.$sault);
      $this->items = $this->cache->get('items'.$sault);
      $this->options = $this->cache->get('options'.$sault);
    } else {
      $this->createOptionsMap();
      $this->cache->set('map'.$sault, $this->map);
      $this->cache->set('items'.$sault, $this->items);
      $this->cache->set('options'.$sault, $this->options);
    }

    //подключаем файл с шаблонами
    $this->getFilters() or die ('Не могу создать фильтры из шаблонов');

    //подключаем файл с функциями
    $this->getFunctions() or die ('Не могу создать функции фильтрации');

    //устанавливаем флаг загрузки предыдущего состояния
    $this->loadStateNeedCheck();

    //передаем при необходимости объект в свойство $modx
    if ($param['toModx']) {
      $modx->opt = &$this;
    }
  }


  /**
  ** Создаем экземпляр кеш-объекта
  **/
  private function startCacheTool() {
    include_once $this->param['path'].'classes/RssCache.class.inc.php';
    if (!class_exists('RSSCache')) {
      return false;
    } else {
      $this->cache = new RSSCache($this->param['path'].'.cache/', $this->param['cache_max_age']);
      return true;
    }
  }

  /**
  ** Создаем экземпляр парсера
  **/
  private function startParser() {
    include_once $this->param['path'].'classes/TemplateParser.class.inc.php';

    if (!class_exists('TemplateParser')) {
      return false;
    } else {
      $this->parser = new TemplateParser();
      return true;
    }
  }

  /**
  ** Получаем параметры запуска из файла или чанка конфигурации
  **/
  private function getSqlTask() {
    //загружаем файл с конфигурацией sql
    if (isset($this->param['confFile']) && file_exists($this->param['confFile'])) {
      //разбираем ini файл
      $this->sqlData = parse_ini_file($this->param['confFile'], true);
    } else if (isset($this->param['confChunk']) && ($confStr = $modx->getChunk($this->param['confChunk']) != '')) {
      //создаем временный ini файл
      $tempFile = $this->param['path'].uniqid().'.ini';
      file_put_contents($tempFile, $confStr);
      $this->sqlData = parse_ini_file($tempFile, true);
      unlink($tempFile);
    } else {
      die('No autofilter config found!');
    }
    if (!$this->sqlData || !is_array($this->sqlData)) {
      return false;
    } else {
      //устанавливаем соответствия имен полей и опций
      $this->aliases = isset($this->sqlData['html_aliases']) ? $this->sqlData['html_aliases'] : array();

      $this->param['fields'] = isset($this->sqlData['html_names']) ? $this->sqlData['html_names'] : array();
      return true;
    }
  }

  /**
  ** Получаем фильтры и ключи фильтров
  **/
  private function getFilters() {
    global $modx;

    if (!file_exists($this->param['path'].'templates/formTemplates.inc.php')) {
      return false;
    } else {
      include_once $this->param['path'].'templates/formTemplates.inc.php';
    }

    if (is_array($tpl)) {
      $this->templates = $tpl; //код шаблонов
      $this->filters = array_keys($tpl); //имена фильтров
    } else {
      return false;
    }

    //если были указаны пользовательские фильтры
    if ($this->param['customFilter'] != '') {
      $res = $modx->runSnippet($this->param['customFilter']);
      $res = unserialize($res);
      if (is_array($res)) {
        $this->templates = array_merge($this->templates, $res);
        $this->filters = array_merge($this->filters, array_keys($res)); //имена шаблонов
      }
    }

    //для режима EP дописываем в массив слово ep
    /*if ($this->param['mode'] == 'ep') {
      $this->filters[] = 'ep';
    }*/
    return true;
  }

  /**
   * Получаем из формы список реально используемых фильтров
   */
  public function getTaskFromTpl($tplStr) {
    $retAr = array();
    //получаем все плейсхолдеры в шаблоне
    $do = preg_match_all('~\[\+(.*?)\+\]~', $tplStr, $phTmpAr);
    $phAr = $phTmpAr[1];

    if (!is_array($phAr)) return false;
    // в $phAr массив с именами плейсхолдеров вида optNN_KEY и не только
    foreach ($phAr as $phName) {
      list($optId, $key) = explode('_', $phName);
      $optId = trim(str_replace($this->param['id'].'.', '', $optId));
      if (in_array($key, $this->filters)) {
        $retAr[$optId][] = $key;
      }
    }
    /*print_r($retAr);
    die();*/
    return $retAr;
  }

  /**
  ** Получаем функции обработки фильтров
  **/
  private function getFunctions() {
    //подключаем файл с функциями обработки
    if (!file_exists($this->param['path'].'functions/formFunctions.inc.php')) {
      return false;
    } else {
      include_once $this->param['path'].'functions/formFunctions.inc.php';
      return true;
    }
  }


  /**
  ** Получаем карту данных для опций и товаров
  **/
  abstract function createMap($ids);


  /**
  ** Получаем полную карту данных для опций и товаров
  **/
  public function createOptionsMap() {
    $fullAr = $this->createMap($this->param['ids']);
    $this->map = $fullAr['map'];
    $this->items = $fullAr['items'];
    $this->options = $fullAr['options'];
  }

  /**
  ** Получаем полный список товаров
  **/
  abstract function getAllItems();


  /**
  ** Возвращает ассоциативный массив фильтрации, полученный из $_REQUEST
  ** @param: $filterKey - ключ с именем фильтра
  ** @param: $optName - ключ с именем опции (optNN или alias)
  **/
  function callRequestFunction($filterKey, $optName) {
    //echo "~".$filterKey."~<br>";
    //print_r($filterKey);
    //ris, opt56
    $ret = array($filterKey => 'no request function');
    if (function_exists($filterKey.'_request')) {
      $funcName = $filterKey.'_request';
      $ret = $funcName($this, $optName);
    }
    return $ret;
  }

  /**
  ** Возвращает массив с id товаров после фильтрации
  ** @param: $filterKey - ключ с именем фильтра
  **/
  function callFilterFunction($filterVal, $optId, $filterKey) {
    $retIdsAr = array('no filter function');
    if (function_exists($filterKey.'_filter')) {
      $funcName = $filterKey.'_filter';
      $retIdsAr = $funcName($this, $optId, $filterVal);
    }
    return $retIdsAr;
  }

  /**
  ** Возвращает массив с плейсхолдерами элементов формы
  ** @param: $filterAr - массив со значениями фильтров
  **/
  function createPlaceholders($filterAr) {
    
    //выбираем все опции и ключи фильтров для них
    $formBlock = $this->parser->strToTpl($this->param['formTpl']);
    $this->usedKeys = $this->getTaskFromTpl($this->preParse($formBlock)); // 'optNN' => 'FILTER_KEY',...
    /*print_r($usedKeys);
    die();*/

    if ($this->param['hideImpossible'] == '1' || $this->param['markImpossible'] == '1') {
      //нужно формировать временную карту опций для уже отсортированных товаров
      if (!is_array($this->filteredIds)) {
        $this->parser->setError('Внутренняя ошибка фильтрации: товары после фильтра не в массиве');
        return false;
      } else {
        //формируем усеченную карту опций
        $this->createCurrentMap($filterAr);

        //если надо полностью убрать невозможные опции, подменяем карту
        if (is_array($this->currentMap) && $this->param['hideImpossible'] == '1') {
          $this->map = $this->currentMap;
        }

      }
    } 

    //формируем плейсхолдеры на основании нужной карты
    if (!is_array($this->map)) {
      $this->parser->setError('Полученные опции не могут быть использованы в форме');
      return false;
    }    

    $retPhAr = array();    

    if (!is_array($this->usedKeys) || empty($this->usedKeys)) {
      /*echo 'empty ';
      print_r($retPhAr);
      die();*/
      $this->parser->setError('В форме не обнаружено подстановщиков');
      return $retPhAr;
    } else {
      foreach ($this->usedKeys as $optName => $filterKeyAr) {
        if (is_array($filterKeyAr)) {
          foreach ($filterKeyAr as $filterKey) {
            $optId = str_replace('opt', '', $optName);
            if (isset($this->param['fields'][$optName])) {
              $reqKey = trim($this->param['fields'][$optName]);
            } else {
              $reqKey = $optName;
            }


            $tpl = $this->templates[$filterKey];
            if (function_exists($filterKey.'_parser')) {
              $funcName = $filterKey.'_parser';
              $retCode = $funcName($this, $tpl, $optId, $filterAr[$reqKey.'_'.$filterKey]);
              $retPhAr[$optName.'_'.$filterKey] = $retCode;
            } else {
              if ($this->param['mode'] != 'ep') {
                $retPhAr[$optName.'_'.$filterKey] = 'no function found';
              }
            }
          }
        } else {
          //nothing here
        }
      }
    }
    /*print_r($retPhAr);
    die();*/
    return $retPhAr;
  }

  /**
  ** Выбираем товары, согласно полученным значениям фильтров
  **/
  public function getFilteredIds($filterAr) {
    $retAr = array();

    /*echo '<pre>';
    print_r($filterAr);
    echo '</pre>';*/

    foreach ($this->map as $optionId => $valuesAr) {
      if (isset($this->param['fields']['opt'.$optionId])) {
        $filterRequestName = $this->param['fields']['opt'.$optionId];
      } else {
        $filterRequestName = 'opt'.$optionId;
      }
      foreach ($this->filters as $filterName) {
        if (isset($filterAr[$filterRequestName.'_'.$filterName])
              &&
              (is_array($filterAr[$filterRequestName.'_'.$filterName])
                ||
              trim($filterAr[$filterRequestName.'_'.$filterName]) != ''
              )
          ) {
          $tmpAr = $this->callFilterFunction($filterAr[$filterRequestName.'_'.$filterName], $optionId, $filterName);

          if (isset($retAr[$optionId.'_'.$filterName])) {
            $retAr[$optionId.'_'.$filterName] = array_merge($retAr[$optionId.'_'.$filterName], $tmpAr);
          } else {
            $retAr[$optionId.'_'.$filterName] = $tmpAr;
          }
        }
      }
      if (is_array($retAr[$optionId.'_'.$filterName])) {
        $retAr[$optionId.'_'.$filterName] = array_unique($retAr[$optionId.'_'.$filterName]);
      }
    }

    //в массиве $retAr теперь хранится такая структура:
    //array ( optId1_key => array (itemK, itemL...), optId2_key => array(itemN, itemM...) ...)
    //находим товары, которые есть в каждом из вложенных массивов
    $resultAr = $this->getAllItems(); //все товары
    /*echo "ALL: \n";
    print_r($retAr);
    echo "\n\n";*/
    foreach ($retAr as $optId => $itemsAr) {
      $itemsAr = array_unique($itemsAr);
      $resultAr = array_intersect($resultAr, $itemsAr);
      /*echo $optId.":\n";
      print_r($itemsAr);
      print_r($resultAr);*/
    }

    $this->filteredIds = array_unique($resultAr);

    return $this->filteredIds;
  }

  /**
   *  Проверяем, надо ли загружать сохраненное состояние для списка из параметра saveState
   */
  function loadStateNeedCheck() {
    if ($this->param['saveState'] != 0) {
      $this->loadStateNeed = true;
      return $this->loadStateNeed;
    }

    $saveAr = explode(',', $this->param['saveStateKey']);
    $loadStateNeed = false; //флаг для загрузки состояния
    foreach ($saveAr as $key) {
      if ($key != '' && isset($_REQUEST[$key])) {
        $this->loadStateNeed = true;
        break;
      }
    }
    return $this->loadStateNeed;
  }

  /**
  ** Возвращает хеш-ключ для текущего состояния $param, $_REQUEST, $_SESSION
  **/
  function getStateKey() {
    if (!isset($this->param['cacheKeys']) || $this->param['cacheKeys'] == '') {
      $this->param['cacheKeys'] = 'prs';
    }

    $keysAr = array();

    //откусываем по одной букве и добавляем нужный параметр в ключ
    for ($i=0; $i<strlen($this->param['cacheKeys']); $i++) {
      $tmpKey = substr($this->param['cacheKeys'], $i, 1);

      switch ($tmpKey) {
        case 'p':
          //сериализуем параметры вызова сниппета
          $keysAr[] = serialize($this->param);
        break;

        case 'r':
          //сериализуем параметры HTTP запроса
          $keysAr[] = serialize($_REQUEST);
        break;

        case 's':
          //сериализуем значения данных в сессии
          if (is_array($_SESSION[$this->param['id'].'Data'])) {
            $keysAr[] = serialize($_SESSION[$this->param['id'].'Data']);
          }
        break;
      }
    }

    $key = implode('', $keysAr);
    //берем параметры запроса из REQUEST или из сессии для сохраненного состояния
    /*if ($this->loadStateNeed) {
      $key2 = isset($_SESSION[$this->param['id'].'prevRequest']) ?
                    $_SESSION[$this->param['id'].'prevRequest'] :
                    serialize($_REQUEST);
    }*/
    
    return md5($key);
  }

  /**
  ** Возвращает массив с html блоками формы и результатов
  ** для текущих значений $param, $_REQUEST, $_SESSION
  **/
  function getCachedResult() {
    if ($this->param['cacheResult'] == 0) {
      return false;
    }
    $currentStateKey = $this->getStateKey();

    $status = $this->cache->check_cache($currentStateKey);
    if ($status == 'HIT') {
      $dataPack = $this->cache->get($currentStateKey);
      return unserialize($dataPack);
    } else {
      $this->cache->kill_cache($currentStateKey);
      return false;
    }
  }

  /**
  ** Пишет в кеш массив с html блоками формы и результатов
  ** для текущих значений $param, $_REQUEST, $_SESSION
  **/
  function setCachedResult(array $dataAr) {
    $currentStateKey = $this->getStateKey();

    $data = serialize($dataAr);
    $do = $this->cache->set($currentStateKey, $data);

    return true;
  }

  ////////Вспомогательные функции//////////
  /**
  ** Выбирает максимальное и минимальное значение опции
  **/
  function getMM($optId, $need='max') {
    if (!is_array($this->map[$optId])) {
      return 0;
    }

    if ($this->param['hideImpossible'] == '1') {
      $values = array_keys($this->currentMap[$optId]);
    } else {
      $values = array_keys($this->map[$optId]);
    }
    
    natsort($values);
    $values = array_values($values); //для старых версий php не работают флаги

    if ($need == 'min') {
      return $values[0];
    } else {
      $k = count($values) - 1;
      return $values[$k];
    }
  }

  /**
  ** Выбирает минимальное значение опции
  **/
  function getMinVal($optId) {
    return $this->getMM($optId, 'min');
  }

  /**
  ** Выбирает максимальное значение опции
  **/
  function getMaxVal($optId) {
    return $this->getMM($optId, 'max');
  }

  /**
   * Заглушка для EP, для переопределения в соотв.классе
   **/
  function preParse($tpl) {
    //$this->param['formTpl'] = $tpl;
    return $tpl;
  }

  /**
   * Для переопределения в соотв.классе
   **/
  function getAllValues($optId) {
    if (is_array($this->map[$optId])) {
      return array_keys($this->map[$optId]);
    } else {
      return array();
    }
  }

  /**
  * Создание усеченной карты опций
  **/
  function createCurrentMap($filterAr) {
    //пытаемся получить усеченную карту опций из кеша
    $currentStateKey = $this->getStateKey().'currentMap';

    $status = $this->cache->check_cache($currentStateKey);
    if ($status == 'HIT') {
      $tmpMap = $this->cache->get($currentStateKey);
      $this->currentMap = unserialize($tmpMap);
    } else {
      $this->cache->kill_cache($currentStateKey);

      //формируем новую усеченную карту
      if (!is_array($this->filteredIds) || count($this->filteredIds) < 1) {
        $this->currentMap = $this->map;
      } else {
        //получаем набор фильтров, которые были использованы в текущем вызове
        if (!is_array($filterAr) || count($filterAr) < 1) {
          //фильтры еще не использовались, нет необходимости создавать усеченную карту
          $this->currentMap = $this->map;
        } else {
          $optsAr = array();
          foreach ($filterAr as $fullName => $val) {
            if ($val == '') continue; //пустое значение приравнивается к неактивному фильтру
            $tmp = substr($fullName, 0, strpos($fullName, '_'));
            $optsAr[] = str_replace('opt', '', $tmp);
          }

          //в optsAr у нас набор опций, по которым в текущем вызове уже есть фильтрация
          foreach ($this->filteredIds as $iid) {
            foreach ($this->map as $optId => $valuesAr) {
              //если данный фильтр уже используется, не нужно прятать лишние значения
              if (in_array($optId, $optsAr)) {
                $this->currentMap[$optId] = $valuesAr;
              } else {
                foreach ($valuesAr as $value => $idsAr) {
                  if (in_array($iid, $idsAr)) {
                    $this->currentMap[$optId][$value][] = $iid;
                  }
                }
              }            
            }
          }
        }
      }      
      //сохраняем для дальнейшего использования усеченную карту
      $this->cache->set($currentStateKey, serialize($this->currentMap));
    }    
  }
}

//*********************************
//2. Класс для работы с документами modx в качестве элементов каталога и TV в качестве опций
//*********************************
class OptionsMapBase extends OptionsMap {

  //получаем карту данных для опций и товаров среди заданных товаров
  public function createMap($ids) {
    global $modx;

    $retAr = array();
    //готовим выборку
    $query = "SELECT c.id did, c.pagetitle, c.longtitle, c.parent, c.template, p.pagetitle AS ptitle, ";
    $query.= " p.id AS pid, tvv.tmplvarid, tvv.contentid, tvv.value, ";
    $query.= " tv.type, tv.name, tv.caption, tv.description, tv.category,tv.elements,tv.default_text ";
    $query.= " FROM ".$modx->getFullTableName('site_content')." c ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_content')." p ";
    $query.= " ON p.id=c.parent ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvar_contentvalues')." tvv ";
    $query.= " ON c.id=tvv.contentid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvars')." tv ";
    $query.= " ON tv.id=tvv.tmplvarid ";
    $query.= " WHERE c.id IN (".$ids.") ";

    if ($this->param['skipFolders'] == 1) {
      $query.= " AND c.isfolder=0 ";
    }

    if ($this->param['includeTv'] != '') {
      $query.= " AND tvv.tmplvarid IN (".$this->param['includeTv'].") ";
    }

    if ($this->param['excludeTv'] != '') {
      $query.= " AND tvv.tmplvarid NOT IN (".$this->param['excludeTv'].") ";
    }

    if ($this->param['where'] != '') {
      $this->param['where'] = $modx->db->escape($this->param['where']); //?
      $query.= " AND ". $this->param['where']. " ";
    }

    $query.= "ORDER BY tvv.contentid ";
    $res = $modx->db->query($query);
    while ($row = $modx->db->getRow($res)) {
      $valAr = explode('||', $row['value']);

      foreach ($valAr as $rVal) {
        $rVal = trim($rVal);
        //массив с товарами
        $retAr['items'][$row['did']][$row['tmplvarid']] = $rVal;

        //массив с картой опций
        if (!empty($row['tmplvarid'])) {
          $retAr['map'][$row['tmplvarid']][$rVal][] = $row['contentid'];
        }

      }
      //массив с опциями
      if (!isset($retAr['options'][$row['tmplvarid']])) {
        $retAr['options'][$row['tmplvarid']]['caption'] = $row['caption'];
        $retAr['options'][$row['tmplvarid']]['elements'] = $row['elements'];
        $retAr['options'][$row['tmplvarid']]['type'] = $row['type'];
        $retAr['options'][$row['tmplvarid']]['name'] = $row['name'];
      }

      //для стандартных полей товара
      $retAr['items'][$row['did']]['pagetitle'] = $row['pagetitle'];
      $retAr['items'][$row['did']]['longtitle'] = $row['longtitle'];
      //$retAr['items'][$row['did']]['template'] = $row['template'];
      $retAr['items'][$row['did']]['ptitle'] = $row['ptitle'];
      $retAr['items'][$row['did']]['parent'] = $row['parent'];
      $retAr['items'][$row['did']]['pid'] = $row['pid'];

      $retAr['options']['parent']['caption'] = 'Категория';
      $retAr['options']['parent']['name'] = 'parent';


      $retAr['options']['pid']['caption'] = 'PID';
      $retAr['options']['pid']['name'] = 'parent ID';
      $retAr['map']['parent'][$row['parent']][] = $row['did'];

      $retAr['options']['template']['caption'] = 'Template';
      $retAr['options']['template']['name'] = 'item tpl id';
      $retAr['map']['template'][$row['template']][] = $row['did'];

      //если есть TV с мультипарентами, добавляем в карту информацию о родителях из этого TV
      if($this->param['parentTv'] && $row['tmplvarid'] == $this->param['parentTv']) {
        $splitter = $this->param['parentTvWrapper'].$this->param['parentTvSplitter'].$this->param['parentTvWrapper'];
        $addParentStr = $row['value'];
        $addParentStr = trim($addParentStr, $this->param['parentTvSplitter']); //обрезаем символ-обертку в начале и в конце
        $addParentStr = trim($addParentStr, $this->param['parentTvWrapper']); //обрезаем символ-обертку в начале и в конце

        $addParentAr = explode($splitter, $addParentStr);
        //$this->param['debug']['ar'][$row['did']] = $addParentAr;
        foreach ($addParentAr as $addParentId) {
          $addParentId = intval(trim($addParentId, $this->param['parentTvWrapper']));
          $addParentId = intval(trim($addParentId, $this->param['parentTvSplitter']));
          //$retAr['items'][$row['did']]['parent'] = $addParentId; //напоминалка
          $retAr['map']['parent'][$addParentId][] = $row['did'];
          //$this->param['debug'][] = $addParentId.':'.$row['did'];
        }
      }
    }
    return $retAr;
    /*
    //обработка конфигурационного файла пока не будет делаться
    //надо продумать структуру файла
    */
  }

  //получаем карту данных для опций и товаров среди заданных товаров
  public function createMapOld($ids) {
    global $modx;

    $retAr = array();
    //готовим выборку
    $query = "SELECT c.id did, c.pagetitle, c.longtitle, c.parent, c.template, ";
    $query.= " tvv.tmplvarid, tvv.contentid, tvv.value, ";
    $query.= " tv.type, tv.name, tv.caption, tv.description, tv.category,tv.elements,tv.default_text ";
    $query.= " FROM ".$modx->getFullTableName('site_content')." c ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvar_contentvalues')." tvv ";
    $query.= " ON c.id=tvv.contentid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvars')." tv ";
    $query.= " ON tv.id=tvv.tmplvarid ";
    $query.= " WHERE c.id IN (".$ids.") ";

    if ($this->param['skipFolders'] == 1) {
      $query.= " AND c.isfolder=0 ";
    }

    if ($this->param['includeTv'] != '') {
      $query.= " AND tvv.tmplvarid IN (".$this->param['includeTv'].") ";
    }

    if ($this->param['excludeTv'] != '') {
      $query.= " AND tvv.tmplvarid NOT IN (".$this->param['excludeTv'].") ";
    }

    if ($this->param['where'] != '') {
      $this->param['where'] = $modx->db->escape($this->param['where']); //?
      $query.= " AND ". $this->param['where']. " ";
    }

    $query.= "ORDER BY tvv.contentid ";
    $res = $modx->db->query($query);
    while ($row = $modx->db->getRow($res)) {
      $valAr = explode('||', $row['value']);

      foreach ($valAr as $rVal) {
        $rVal = trim($rVal);
        //массив с товарами
        $retAr['items'][$row['did']][$row['tmplvarid']] = $rVal;

        //массив с картой опций
        if (!empty($row['tmplvarid'])) {
          $retAr['map'][$row['tmplvarid']][$rVal][] = $row['contentid'];
        }

      }
      //массив с опциями
      if (!isset($retAr['options'][$row['tmplvarid']])) {
        $retAr['options'][$row['tmplvarid']]['caption'] = $row['caption'];
        $retAr['options'][$row['tmplvarid']]['elements'] = $row['elements'];
        $retAr['options'][$row['tmplvarid']]['type'] = $row['type'];
        $retAr['options'][$row['tmplvarid']]['name'] = $row['name'];
      }

      //для стандартных полей товара
      $retAr['items'][$row['did']]['pagetitle'] = $row['pagetitle'];
      $retAr['items'][$row['did']]['longtitle'] = $row['longtitle'];
      $retAr['items'][$row['did']]['template'] = $row['template'];
      $retAr['items'][$row['did']]['ptitle'] = $row['ptitle'];
      $retAr['items'][$row['did']]['parent'] = $row['parent'];

      $retAr['options']['parent']['caption'] = 'Категория';
      $retAr['options']['parent']['name'] = 'parent';

      $retAr['map']['parent'][$row['parent']][] = $row['did'];
      $retAr['map']['template'][$row['template']][] = $row['did'];
    }

    return $retAr;
    /*
    //обработка конфигурационного файла пока не будет делаться
    //надо продумать структуру файла
    */
  }

  public function getAllItems() {
    return array_keys($this->items);
  }

}

//*********************************
//3. Класс для работы с товарами shopkeeper в качестве элементов каталога и TV в качестве опций
//*********************************
class OptionsMapShk extends OptionsMap {

  //получаем карту данных для опций и товаров среди заданных товаров
  public function createMap($ids) {
    global $modx;

    $retAr = array();
    //готовим выборку
    $query = "SELECT c.id did, c.pagetitle, c.parent, c.template, p.pagetitle ptitle, ";
    $query.= " tvv.tmplvarid, tvv.contentid, tvv.value, pp.pagetitle region, pp.id regionid, ";
    $query.= " tv.type, tv.name, tv.caption, tv.description, tv.category,tv.elements,tv.default_text ";
    $query.= " FROM ".$modx->getFullTableName('catalog')." c ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_content')." p ";
    $query.= " ON p.id=c.parent ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_content')." pp ";
    $query.= " ON pp.id=p.parent ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('catalog_tmplvar_contentvalues')." tvv ";
    $query.= " ON c.id=tvv.contentid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvars')." tv ";
    $query.= " ON tv.id=tvv.tmplvarid ";
    $query.= " WHERE c.id IN (".$ids.") ";

    if ($this->param['skipFolders'] == 1) {
      $query.= " AND c.isfolder=0 ";
    }

    if ($this->param['includeTv'] != '') {
      $query.= " AND tvv.tmplvarid IN (".$this->param['includeTv'].") ";
    }

    if ($this->param['excludeTv'] != '') {
      $query.= " AND tvv.tmplvarid NOT IN (".$this->param['excludeTv'].") ";
    }

    if ($this->param['where'] != '') {
      $this->param['where'] = $modx->db->escape($this->param['where']); //?
      $query.= " AND ". $this->param['where']. " ";
    }

    $query.= "ORDER BY tvv.contentid ";
    $res = $modx->db->query($query);
    while ($row = $modx->db->getRow($res)) {
      $valAr = explode('||', $row['value']);

      foreach ($valAr as $rVal) {
        $rVal = trim($rVal);
        //массив с товарами
        $retAr['items'][$row['did']][$row['tmplvarid']] = $rVal;

        //массив с картой опций
        if (!empty($row['tmplvarid'])) {
          $retAr['map'][$row['tmplvarid']][$rVal][] = $row['contentid'];
        }

      }
      //массив с опциями
      if (!isset($retAr['options'][$row['tmplvarid']])) {
        $retAr['options'][$row['tmplvarid']]['caption'] = $row['caption'];
        $retAr['options'][$row['tmplvarid']]['elements'] = $row['elements'];
        $retAr['options'][$row['tmplvarid']]['type'] = $row['type'];
        $retAr['options'][$row['tmplvarid']]['name'] = $row['name'];
      }

      //для стандартных полей товара
      $retAr['items'][$row['did']]['pagetitle'] = $row['pagetitle'];
      //$retAr['items'][$row['did']]['longtitle'] = $row['longtitle'];
      //$retAr['items'][$row['did']]['template'] = $row['template'];
      $retAr['items'][$row['did']]['ptitle'] = $row['ptitle'];
      $retAr['items'][$row['did']]['parent'] = $row['parent'];
      $retAr['items'][$row['did']]['region'] = $row['region'];
      $retAr['items'][$row['did']]['regionid'] = $row['regionid'];

      $retAr['options']['parent']['caption'] = 'Категория';
      $retAr['options']['parent']['name'] = 'parent';
      $retAr['options']['region']['name'] = 'region';
      $retAr['options']['region']['caption'] = 'Регион';

      if ((is_array($retAr['map']['parent'][$row['parent']]) && !in_array($row['did'], $retAr['map']['parent'][$row['parent']])) || !isset($retAr['map']['parent'][$row['parent']])) {
        $retAr['map']['parent'][$row['parent']][] = $row['did'];
      }
      if ($row['region'] && (is_array($retAr['map']['region'][$row['region']]) && !in_array($row['did'], $retAr['map']['region'][$row['region']])) || !isset($retAr['map']['region'][$row['region']])) {
        $retAr['map']['region'][$row['region']][] = $row['did'];
      }
      if ($row['regionid'] && (is_array($retAr['map']['regionid'][$row['regionid']]) && !in_array($row['did'], $retAr['map']['regionid'][$row['regionid']])) || !isset($retAr['map']['regionid'][$row['regionid']])) {
        $retAr['map']['regionid'][$row['regionid']][] = $row['did'];
      }
      //не для этого проекта как минимум
      //$retAr['map']['template'][$row['template']][] = $row['did'];

      //если есть TV с мультипарентами, добавляем в карту информацию о родителях из этого TV
      if($this->param['parentTv'] && $row['tmplvarid'] == $this->param['parentTv']) {
        $splitter = $this->param['parentTvWrapper'].$this->param['parentTvSplitter'].$this->param['parentTvWrapper'];
        $addParentStr = $row['value'];
        $addParentStr = trim($addParentStr, $this->param['parentTvSplitter']); //обрезаем символ-обертку в начале и в конце
        $addParentStr = trim($addParentStr, $this->param['parentTvWrapper']); //обрезаем символ-обертку в начале и в конце

        $addParentAr = explode($splitter, $addParentStr);
        //$this->param['debug']['ar'][$row['did']] = $addParentAr;
        foreach ($addParentAr as $addParentId) {
          $addParentId = intval(trim($addParentId, $this->param['parentTvWrapper']));
          $addParentId = intval(trim($addParentId, $this->param['parentTvSplitter']));
          //$retAr['items'][$row['did']]['parent'] = $addParentId; //напоминалка
          $retAr['map']['parent'][$addParentId][] = $row['did'];
          //$this->param['debug'][] = $addParentId.':'.$row['did'];
        }
      }
    }
    return $retAr;
    /*
    //обработка конфигурационного файла пока не будет делаться
    //надо продумать структуру файла
    */
  }

  public function getAllItems() {
    return array_keys($this->items);
  }
}

//*********************************
//4. Класс для работы с документами modx в качестве элементов каталога и TV + птв в качестве опций
//*********************************
class OptionsMapEp extends OptionsMap {

  //получаем карту данных для опций и товаров среди заданных товаров
  public function createMap($ids) {
    global $modx;

    $retAr = array();
    //готовим выборку
    $query = "SELECT c.id did, c.pagetitle, c.longtitle, c.parent, c.template, p.pagetitle AS ptitle, ";
    $query.= " tvv.tmplvarid, tvv.contentid, tvv.value, ";
    $query.= " tv.type, tv.name, tv.caption, tv.description, tv.category,tv.elements,tv.default_text ";
    $query.= " FROM ".$modx->getFullTableName('site_content')." c ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_content')." p ";
    $query.= " ON p.id=c.parent ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvar_contentvalues')." tvv ";
    $query.= " ON c.id=tvv.contentid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_tmplvars')." tv ";
    $query.= " ON tv.id=tvv.tmplvarid ";
    $query.= " WHERE c.id IN (".$ids.") ";

    if ($this->param['skipFolders'] == 1) {
      $query.= " AND c.isfolder=0 ";
    }

    if ($this->param['includeTv'] != '') {
      $query.= " AND tvv.tmplvarid IN (".$this->param['includeTv'].") ";
    }

    if ($this->param['excludeTv'] != '') {
      $query.= " AND tvv.tmplvarid NOT IN (".$this->param['excludeTv'].") ";
    }

    if ($this->param['where'] != '') {
      $this->param['where'] = $modx->db->escape($this->param['where']); //?
      $query.= " AND ". $this->param['where']. " ";
    }

    $query.= "ORDER BY tvv.contentid ";
    $res = $modx->db->query($query);
    while ($row = $modx->db->getRow($res)) {
      $valAr = explode('||', $row['value']);

      //массив с товарами
      $retAr['items'][$row['did']][$row['tmplvarid']] = $row['value'];

      foreach ($valAr as $rVal) {
        $rVal = trim($rVal);

        //массив с картой опций
        if (!empty($row['tmplvarid'])) {
          $retAr['map'][$row['tmplvarid']][$rVal][] = $row['contentid'];
        }

      }
      //массив с опциями
      if (!isset($retAr['options'][$row['tmplvarid']])) {
        $retAr['options'][$row['tmplvarid']]['caption'] = $row['caption'];
        $retAr['options'][$row['tmplvarid']]['elements'] = $row['elements'];
        $retAr['options'][$row['tmplvarid']]['type'] = $row['type'];
        $retAr['options'][$row['tmplvarid']]['name'] = $row['name'];
      }

      //для стандартных полей товара
      $retAr['items'][$row['did']]['pagetitle'] = $row['pagetitle'];
      $retAr['items'][$row['did']]['longtitle'] = $row['longtitle'];
      $retAr['items'][$row['did']]['template'] = $row['template'];
      $retAr['items'][$row['did']]['ptitle'] = $row['ptitle'];
      $retAr['items'][$row['did']]['parent'] = $row['parent'];

      $retAr['options']['parent']['caption'] = 'Категория';
      $retAr['options']['parent']['name'] = 'parent';

      $retAr['map']['parent'][$row['parent']][] = $row['did'];
      $retAr['map']['template'][$row['template']][] = $row['did'];
    }

    //данные по расширенным параметрам
    $query = "SELECT c.id did, c.pagetitle, c.longtitle, c.parent, c.template, p.pagetitle AS ptitle, ";
    $query.= " ptvv.epid, ptvv.itemid, ptvv.value, ";
    $query.= " ptv.frontend_type, ptv.name, ptv.catid, pte.elements ";
    $query.= " FROM ".$modx->getFullTableName('site_content')." c ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('site_content')." p ";
    $query.= " ON p.id=c.parent ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('ep_params_contentvalues')." ptvv ";
    $query.= " ON c.id=ptvv.itemid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('ep_params')." ptv ";
    $query.= " ON ptv.id=ptvv.epid ";
    $query.= " LEFT JOIN ".$modx->getFullTableName('ep_params_elements')." pte ";
    $query.= " ON pte.epid=ptv.id ";
    $query.= " WHERE c.id IN (".$ids.") ";

    if ($this->param['skipFolders'] == 1) {
      $query.= " AND c.isfolder=0 ";
    }

    $query.= "ORDER BY ptv.rank ";
    $res = $modx->db->query($query);

    while ($row = $modx->db->getRow($res)) {
      $valAr = explode('|', $row['value']);

      //массив с товарами
      $retAr['items'][$row['did']]['ep'.$row['epid']] = $row['value'];

      foreach ($valAr as $rVal) {
        $rVal = trim($rVal);

        //массив с картой опций
        if (!empty($row['epid']) && trim($rVal) != '') {
          $retAr['map']['ep'.$row['epid']][$rVal][] = $row['itemid'];
        }

      }
      //массив с опциями
      if (!isset($retAr['options']['ep'.$row['epid']])) {
        $retAr['options']['ep'.$row['epid']]['frontend_type'] = $row['type'];
        $retAr['options']['ep'.$row['epid']]['name'] = $row['name'];
        $retAr['options']['ep'.$row['epid']]['elements'] = $row['elements'];
      }

    }

    return $retAr;

  }

  public function getAllItems() {
    return array_keys($this->items);
  }


  /**
  ** Возвращает массив с id товаров после фильтрации
  ** @param: $filterKey - ключ с именем фильтра
  **/
  function callFilterFunction($filterVal, $optId, $filterKey) {
    $retIdsAr = array('no filter function');
    if (function_exists($filterKey.'_filter')) {
      $funcName = $filterKey.'_filter';
      $retIdsAr = $funcName($this, $optId, $filterVal);
    } else {
      $retIdsAr = array('No filter function');
    }
    return $retIdsAr;
  }

  /**
  ** Возвращает шаблон с заменой [+optNN_ep+] на список [+optepNN_TYPE+]
  ** @param: $tpl - шаблон формы
  **/
  function preParse($tpl) {
    if (function_exists('fGetCatEps')) {
      //echo '10';

      $retCode = '';

      //берем родителя первого из товаров в качестве категории
      //$itemIds = $this->param['ids'];
      //$catAr = explode(',', $itemIds);
      $catAr = $this->getAllItems();
      //print_r($catAr);

      $cat = isset($this->items[$catAr[0]]['parent']) ? $this->items[$catAr[0]]['parent'] : '';
      if (trim($cat) == '') {
        $catEps = '';
      } else {
        //echo "\$catEps = fGetCatEps($cat);";
        $catEps = fGetCatEps($cat);
      }
      //print_r($catEps);
      //die();



      if (is_array($catEps) && count($catEps) > 0) {
        $epTpl = $this->parser->strToTpl($this->param['epItemTpl']);

        foreach ($catEps as $epAr) {
          //print_r($epAr);
          //die();
          $phe['epvalues'] = '[+'.$this->param['id'].'.optep'.$epAr['id'].'_'.$epAr['frontend_type'].'+]';
          $phe['epid'] = $epAr['id'];
          $phe['epname'] = $epAr['name'];
          $phe['epftype'] = $epAr['frontend_type'];
          $phe['id'] = $this->param['id'];

          /*$retCode.= '<div id="ep'.$epAr['id'].'">
            <span class="epname">'.$epAr['name'].': </span>
            [+'.$this->param['id'].'.optep'.$epAr['id'].'_'.$epAr['frontend_type'].'+]
          </div><br />[+id+].optep[+epid+]_[+epftype+]
              ';*/
          $retCode.= $this->parser->quickParseTpl($epTpl, $phe);
        }
      }
      /*echo $retCode;
      die();*/

      foreach ($this->options as $optId => $optAr) {
        /*echo $optId." \n";
        print_r($optAr);*/
        if (isset($optAr['type']) && $optAr['type'] == 'custom_tv' && $optAr['name'] == 'ptv') {
          $tpl = str_replace('[+'.$this->param['id'].'.opt'.$optId.'_ep+]', $retCode, $tpl);
        }
      }
      //die();

    } else {
      //echo '13';

      foreach ($this->options as $optId => $optAr) {
        $tpl = str_replace('[+'.$this->param['id'].'.opt'.$optId.'_ep+]', 'no func', $tpl);
      }
    }
    //$this->param['formTpl'] = $tpl;
    return $tpl;
  }

  /**
  ** Выбираем товары, согласно полученным значениям фильтров
  **/
  public function getFilteredIds($filterAr) {
    $retAr = array();

    /*echo '<pre>';
    print_r($filterAr);
    echo '</pre>';*/

    //если есть last_filters в конфиге, сохраняем их в массиве
    if (isset($this->param['last_filters'])) {

    }

    foreach ($this->map as $optionId => $valuesAr) {
      if (isset($this->param['fields']['opt'.$optionId])) {
        $filterRequestName = $this->param['fields']['opt'.$optionId];
      } else {
        $filterRequestName = 'opt'.$optionId;
      }
      foreach ($this->filters as $filterName) {
        if (isset($filterAr[$filterRequestName.'_'.$filterName])
              &&
              (is_array($filterAr[$filterRequestName.'_'.$filterName])
                ||
              trim($filterAr[$filterRequestName.'_'.$filterName]) != ''
              )
          ) {
          $tmpAr = $this->callFilterFunction($filterAr[$filterRequestName.'_'.$filterName], $optionId, $filterName);

          if (isset($retAr[$optionId.'_'.$filterName])) {
            $retAr[$optionId.'_'.$filterName] = array_merge($retAr[$optionId.'_'.$filterName], $tmpAr);
          } else {
            $retAr[$optionId.'_'.$filterName] = $tmpAr;
          }
        }
      }
      if (is_array($retAr[$optionId.'_'.$filterName])) {
        $retAr[$optionId.'_'.$filterName] = array_unique($retAr[$optionId.'_'.$filterName]);
      }
    }

    //в массиве $retAr теперь хранится такая структура:
    //array ( optId1_key => array (itemK, itemL...), optId2_key => array(itemN, itemM...) ...)
    //находим товары, которые есть в каждом из вложенных массивов
    $resultAr = $this->getAllItems(); //все товары
    foreach ($retAr as $optId => $itemsAr) {
      $itemsAr = array_unique($itemsAr);
      $resultAr = array_intersect($resultAr, $itemsAr);
    }

    return array_unique($resultAr);
  }

}
?>