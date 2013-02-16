<?php
/**
** formFunctions.inc.php
** встроенные функции для обработки шаблонов в форме фильтрации
**/

/**
** вспомогательные функции
**/
//////приведение значения фильтра к массиву (для унификации)
if (!function_exists('fToArray')) {
  function fToArray($filterVal) {
    if (!is_array($filterVal)) {
      $filterVal = array($filterVal);
    }
    return $filterVal;
  }
}

///////получение массива опций из строки возможных значений
//для товаров = документам MODX
if (!function_exists('fGetOpt')) {
  function fGetOpt($optVal, $type='') {
    //echo $optVal;
    //для расширенных параметров просто разбиваем значения по \n
    if ($type == 'ep') {
      $retAr = array();
      $optAr = explode("\n", $optVal);
      foreach ($optAr as $option) {
        $option = trim($option);
        $retAr[$option] = $option;
      }
      return $retAr;
    } else {
      if (!strpos($optVal, '||')) {
        return $optVal;
      } else {
        $retAr = array();
        $optAr = explode('||', $optVal);
        foreach ($optAr as $option) {
          if (!strpos($optVal, '==')) {
            $retAr[$option] = $option;
          } else {
            list($name, $val) = explode('==', $option);
            $retAr[trim($val)] = trim($name);
          }
        }
        return $retAr;
      }
    }
  }
}

/**
** фильтр isset ("да-нет")
**/
//////////////////isset
if (!function_exists('isset_request')) {
  function isset_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_isset']) && trim($_REQUEST[$key.'_isset']) != '') {
      $filterAr[$key.'_isset'] = $_REQUEST[$key.'_isset'];
    }
    return $filterAr;
  }
}

if (!function_exists('isset_parser')) {
  function isset_parser(&$opt, $code, $optId, $filterVal) {
    if (isset($filterVal) && trim($filterVal) != '') {
      $filterVal = 'checked';
    }
    $retCode = '';
    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }
    $ph['name'] = $optName;
    $ph['checked'] = $filterVal;
    $retCode.= $opt->parser->parseTpl($code, array_keys($ph), array_values($ph));

    return $retCode;
  }
}

if (!function_exists('isset_filter')) {
  function isset_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    //если в значении массив, берем первый элемент
    $filterVal = fToArray($filterVal);
    $filterVal = $filterVal[0];

    //выбираем все значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        if ($optVal != '') {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
      }
    }
    $retIdsAr = array_unique($retIdsAr);
    return $retIdsAr;
  }
}


/**
** фильтры "точное соответствие"
**/
//////////////////select
if (!function_exists('select_request')) {
  function select_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_select'])) {
      $filterAr[$key.'_select'] = $_REQUEST[$key.'_select'];
    }
    return $filterAr;
  }
}

if (!function_exists('select_parser')) {
  function select_parser(&$opt, $code, $optId, $filterVal) {

    //если в значении массив, берем первый элемент
    $filterVal = fToArray($filterVal);
    $filterVal = $filterVal[0];

    $chAr = preg_split('~(\[\+loop\+\]|\[\+end_loop\+\])~s', $code);
    $pre = $chAr[0];
    $sfx = $chAr[2];
    $body = $chAr[1];
    $retCode = '';

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    if (!is_array($opt->map[$optId])) return $pre.$sfx;

    $tmpAr = array_keys($opt->map[$optId]);
    natsort($tmpAr);

    foreach ($tmpAr as $optVal) {
      $ph['value'] = $optVal;
      if ($optVal == $filterVal) {
        $ph['selected'] = 'selected';
      } else {
        $ph['selected'] = '';
      }
      $retCode.= $opt->parser->parseTpl($body, array_keys($ph), array_values($ph));
    }

    $retCode = $pre.$retCode.$sfx;
    $ph['name'] = $optName;
    $retCode = $opt->parser->parseTpl($retCode, array_keys($ph), array_values($ph));
    return $retCode;
  }
}

if (!function_exists('select_filter')) {
  function select_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();

    //если в значении массив, берем первый элемент
    $filterVal = fToArray($filterVal);
    $filterVal = $filterVal[0];

    //выбираем значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      //echo '<pre>';
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        //echo "$optVal => docsAr<br>\n";
        if ($optVal == $filterVal) {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
          break;
        }
        //print_r($retIdsAr);
      }
      //echo '</pre>';
    }
    return $retIdsAr;
  }
}

//////////////////snames
if (!function_exists('snames_request')) {
  function snames_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_snames'])) {
      $filterAr[$key.'_snames'] = $_REQUEST[$key.'_snames'];
    }
    return $filterAr;
  }
}

if (!function_exists('snames_parser')) {
  function snames_parser(&$opt, $code, $optId, $filterVal) {
    global $modx;
    //если в значении массив, берем первый элемент
    $filterVal = fToArray($filterVal);
    $filterVal = $filterVal[0];

    $chAr = preg_split('~(\[\+loop\+\]|\[\+end_loop\+\])~s', $code);
    $pre = $chAr[0];
    $sfx = $chAr[2];
    $body = $chAr[1];
    $retCode = '';

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    if ($opt->noImpossible) {
      $tmpAr = array_keys($opt->tmpMap[$optId]);
    } else {
      $tmpAr = array_keys($opt->map[$optId]);
    }
    natsort($tmpAr);

    foreach ($tmpAr as $optVal) {
      $ph['value'] = $optVal;
      //$ph['valname'] = $opt->items[$optVal]['ptitle'];
      $dAr = $modx->getDocument(intval($optVal));
      $ph['valname'] = $dAr['pagetitle'];
      if ($optVal == $filterVal) {
        $ph['selected'] = 'selected';
      } else {
        $ph['selected'] = '';
      }
      $retCode.= $opt->parser->parseTpl($body, array_keys($ph), array_values($ph));
    }

    $retCode = $pre.$retCode.$sfx;
    $ph['name'] = $optName;
    $retCode = $opt->parser->parseTpl($retCode, array_keys($ph), array_values($ph));
    return $retCode;
  }
}

if (!function_exists('snames_filter')) {
  function snames_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();

    //если в значении массив, берем первый элемент
    $filterVal = fToArray($filterVal);
    $filterVal = $filterVal[0];

    //выбираем значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      //echo '<pre>';
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        //echo "$optVal => docsAr<br>\n";
        if ($optVal == $filterVal) {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
          break;
        }
        //print_r($retIdsAr);
      }
      //echo '</pre>';
    }
    return $retIdsAr;
  }
}

//////////////////radio
if (!function_exists('radio_request')) {
  function radio_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_radio'])) {
      $filterAr[$key.'_radio'] = $_REQUEST[$key.'_radio'];
    }
    return $filterAr;
  }
}

if (!function_exists('radio_parser')) {
  function radio_parser(&$opt, $code, $optId, $filterVal) {
    $chAr = preg_split('~(\[\+loop\+\]|\[\+end_loop\+\])~s', $code);
    $pre = $chAr[0];
    $sfx = $chAr[2];
    $body = $chAr[1];
    $retCode = '';

    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    $tmpAr = array_keys($opt->map[$optId]);
    natsort($tmpAr);

    foreach ($tmpAr as $optVal) {
      $ph['value'] = $optVal;
      $ph['name'] = $optName;
      if (in_array($optVal, $filterVal)) {
        $ph['checked'] = 'checked';
      } else {
        $ph['checked'] = '';
      }
      $retCode.= $opt->parser->parseTpl($body, array_keys($ph), array_values($ph));
    }
    $retCode = $pre.$retCode.$sfx;
    return $retCode;
  }
}

if (!function_exists('radio_filter')) {
  function radio_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    //выбираем значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      //echo '<pre>';
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        //echo "$optVal => docsAr<br>\n";
        if (in_array($optVal, $filterVal)) {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
        //print_r($retIdsAr);
      }
      //echo '</pre>';
    }
    return $retIdsAr;
  }
}


//////////////////checkboxes
if (!function_exists('checkboxes_request')) {
  function checkboxes_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_checkboxes'])) {
      $filterAr[$key.'_checkboxes'] = $_REQUEST[$key.'_checkboxes'];
    }
    return $filterAr;
  }
}

if (!function_exists('checkboxes_parser')) {
  function checkboxes_parser(&$opt, $code, $optId, $filterVal) {
    $chAr = preg_split('~(\[\+loop\+\]|\[\+end_loop\+\])~s', $code);
    $pre = $chAr[0];
    $sfx = $chAr[2];
    $body = $chAr[1];
    $retCode = '';

    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    //получаем значения
    if (substr($optId, 0, 2) == 'ep') {
      $optValAr = fGetOpt($opt->options[$optId]['elements'], 'ep');
    } else {
      $optValAr = fGetOpt($opt->options[$optId]['elements']);
    }
    

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    if (!is_array($opt->map[$optId])) return '';
    $tmpAr = array_keys($opt->map[$optId]);
    //сортируем пункты - выстраиваем их в том порядке, который задан в перечне элементов в админке
    //natsort($tmpAr);
    $tmpAr2 = array();
    foreach ($optValAr as $key=>$val) {
      if (in_array($val, $tmpAr)) {
        $tmpAr2[] = $val;
      }
    }

    $iteration = 1;

    foreach ($tmpAr2 as $optVal) {
      if ($optVal == '---' || $optVal == '') continue;
      $ph['value'] = $optVal;
      $ph['iteration'] = $iteration++;
      $ph['valname'] = $optValAr[$optVal];
      $ph['name'] = $optName;
      if (in_array($optVal, $filterVal)) {
        $ph['checked'] = 'checked';
      } else {
        $ph['checked'] = '';
      }
      $retCode.= $opt->parser->parseTpl($body, array_keys($ph), array_values($ph));
    }
    $retCode = $pre.$retCode.$sfx;
    return $retCode;
  }
}

if (!function_exists('checkboxes_filter')) {
  function checkboxes_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    //выбираем значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      //echo '<pre>';
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        //echo "$optVal => docsAr<br>\n";
        if (in_array($optVal, $filterVal)) {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
        //print_r($retIdsAr);
      }
      //echo '</pre>';
    }
    return $retIdsAr;
  }
}

//////////////////cnames
if (!function_exists('cnames_request')) {
  function cnames_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_cnames'])) {
      $filterAr[$key.'_cnames'] = $_REQUEST[$key.'_cnames'];
    }
    return $filterAr;
  }
}

if (!function_exists('cnames_parser')) {
  function cnames_parser(&$opt, $code, $optId, $filterVal) {
    global $modx;
    $chAr = preg_split('~(\[\+loop\+\]|\[\+end_loop\+\])~s', $code);
    $pre = $chAr[0];
    $sfx = $chAr[2];
    $body = $chAr[1];
    $retCode = '';

    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    $tmpAr = array_keys($opt->map[$optId]);
    natsort($tmpAr);

    foreach ($tmpAr as $optVal) {
      $ph['value'] = $optVal;
      $dAr = $modx->getDocument(intval($optVal));
      $ph['valname'] = $dAr['pagetitle'];
      $ph['name'] = $optName;
      if (in_array($optVal, $filterVal)) {
        $ph['checked'] = 'checked';
      } else {
        $ph['checked'] = '';
      }
      $retCode.= $opt->parser->parseTpl($body, array_keys($ph), array_values($ph));
    }
    $retCode = $pre.$retCode.$sfx;
    return $retCode;
  }
}

if (!function_exists('cnames_filter')) {
  function cnames_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    //выбираем значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      //echo '<pre>';
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        //echo "$optVal => docsAr<br>\n";
        if (in_array($optVal, $filterVal)) {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
        //print_r($retIdsAr);
      }
      //echo '</pre>';
    }
    return $retIdsAr;
  }
}


/**
** фильтр minmax
**/
//////////////////minmax
if (!function_exists('minmax_request')) {
  function minmax_request(&$opt, $key) {
    $filterAr = array();
    if (isset($_REQUEST[$key.'_minmax'])) {
      $filterAr[$key.'_minmax'] = $_REQUEST[$key.'_minmax'];
    }
    return $filterAr;
  }
}

if (!function_exists('minmax_parser')) {
  function minmax_parser(&$opt, $code, $optId, $filterVal, $otherIds='') {
    $retCode = '';

    //если в значении не массив, преобразуем
    $filterVal = fToArray($filterVal);

    if (isset($opt->param['fields']['opt'.$optId])) {
      $optName = $opt->param['fields']['opt'.$optId];
    } else {
      $optName = 'opt'.$optId;
    }

    $min = $opt->getMinVal($optId);
    $max = $opt->getMaxVal($optId);

    $ph['name'] = $optName;
    $ph['umin'] = isset($filterVal[0]) ? $filterVal[0] : $min;
    $ph['umax'] = isset($filterVal[1]) ? $filterVal[1] : $max;
    $ph['min'] = $min;
    $ph['max'] = $max;
    $retCode.= $opt->parser->parseTpl($code, array_keys($ph), array_values($ph));

    return $retCode;
  }
}

if (!function_exists('minmax_filter')) {
  function minmax_filter(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    $filterVal = fToArray($filterVal);

    //выбираем все значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        if ($optVal < $filterVal[0] || $optVal > $filterVal[1]) {
          //skip
        } else {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
      }
    }
    $retIdsAr = array_unique($retIdsAr);
    return $retIdsAr;
  }
}

/**
** фильтр extend params (ep)
**/

//optepNN - имя опции
//optepNN_select - имя REQUEST переменной

/////////!!!!!!!!!!!!подставить в шаблон список плейсхолдеров [+optepNN_TYPE+]
if (!function_exists('fGetCatEps')) {
  function fGetCatEps($cat) {
    global $modx;
    $cat = intval($cat);
    if ($cat < 1) return array(); //die('err');

    $ret = array();
    $res = $modx->db->select('*', $modx->getFullTableName('ep_params'), 'catid='.$cat.' AND in_filters=1','rank');
    while($row = $modx->db->getRow($res)) {
      switch ($row['frontend_type']) {
        case '1': $row['frontend_type'] = 'select'; break;
        case '2': $row['frontend_type'] = 'checkboxes'; break;
        case '3': $row['frontend_type'] = 'radio'; break;
        case '4': $row['frontend_type'] = 'minmax'; break;
        default: $row['frontend_type'] = 'checkboxes'; break;
      }
      $ret[] = $row;
    }
    return $ret;
  }
}

//////////////////функции не используются, но пока пусть будут
if (!function_exists('ep_request1')) {
  function ep_request1(&$opt, $key) {
    global $modx;
    $filterAr = array();

    //берем родителя первого из товаров в качестве категории
    $itemIds = $opt->param['ids'];
    $catAr = explode(',', $itemIds);
    $catEps = fGetCatEps($opt->items[$catAr[0]]['parent']);

    if (is_array($catEps) && count($catEps) > 1) {
      foreach ($catEps as $epAr) {
        if (isset($_REQUEST[$key.'_'.$epAr['frontend_type']])) {
          $filterAr[$key.'_'.$epAr['frontend_type']] = $_REQUEST[$key.'_'.$epAr['frontend_type']];
        }
      }
    }
    return $filterAr;
  }
}

if (!function_exists('ep_parser1')) {
  function ep_parse1(&$opt, $code, $optId, $filterVal) {
    $retCode = '';

    //берем родителя первого из товаров в качестве категории
    $itemIds = $opt->param['ids'];
    $catAr = explode(',', $itemIds);
    $catEps = fGetCatEps($opt->items[$catAr[0]]['parent']);

    if (is_array($catEps) && count($catEps) > 1) {
      foreach ($catEps as $epAr) {
        //если в значении не массив, преобразуем
        $filterVal = fToArray($filterVal);
        $tpl = $opt->templates[$epAr['frontend_type']];
        if (function_exists($epAr['frontend_type'].'_parser')) {
          $funcName = $epAr['frontend_type'].'_parser';
          $retTmp = $funcName($opt, $tpl, $optId, $filterVal);

          if (is_array($retTmp)) {
            foreach($retTmp as $key=>$code) {
              $retCode.= '
              <div id="ep'.$epAr['id'].'_'.$key.'">'.$code.'</div>
              ';
            }
          } else {
            $retCode.= '
              <div id="ep'.$epAr['id'].'">'.$retTmp.'</div>
              ';
          }
        } else {
           $retCode.= 'no function found for ep'.$epAr['id'];
        }
      }
    }
    return $retCode;
  }
}

if (!function_exists('ep_filter1')) {
  function ep_filter1(&$opt, $optId, $filterVal) {
    $retIdsAr = array();
    $filterVal = fToArray($filterVal);

    //выбираем все значения опции и сопоставленные документы
    if (is_array($opt->map[$optId])) {
      foreach ($opt->map[$optId] as $optVal => $docsAr) {
        if ($optVal < $filterVal[0] || $optVal > $filterVal[1]) {
          //skip
        } else {
          $retIdsAr = array_merge($retIdsAr, $docsAr);
        }
      }
    }
    $retIdsAr = array_unique($retIdsAr);
    return $retIdsAr;
  }
}

?>