<?php

class TemplateParser {
  var $output;
  var $error;
  var $messages;
  var $debug;
  
  function __construct() {
    $this->setOutput('');
    $this->setError('');
    $this->messages = '';
    $this->debug = '';
  }
  
  function setOutput($str) {
    $this->output = $str;
  }
  
  function setError($str) {
    $this->error.= $str;
  }
  
  function setPh($phs, $values, $pre = '[+', $suf = '+]') {
    if (!is_array($phs) && !is_array($values)) {
      $this->output = str_replace($pre.$phs.$suf, $values, $this->output);
      return;
    }
    if (is_array($phs) && is_array($values)) {
      foreach ($phs as $ind=>$ph) {
        $this->output = str_replace($pre.$ph.$suf, $values[$ind], $this->output);
      }
      return;
    }
    $this->setError('Невозможно установить значения плейсхолдеров');
  }

  function parseTpl($tpl, $phs, $values, $pre = '[+', $suf = '+]') {
   if (!is_array($phs) && !is_array($values)) {
      $tpl = str_replace($pre.$phs.$suf, $values, $tpl);
      return $tpl;
    }
    if (is_array($phs) && is_array($values)) {
      foreach ($phs as $ind=>$ph) {
        $tpl = str_replace($pre.$ph.$suf, $values[$ind], $tpl);
      }
      return $tpl;
    }
    $this->setError('Невозможно обработать шаблон');
    return ('Невозможно обработать шаблон');
  }

  function printOutput() {
    //echo $this->output;
    echo $this->stripTags($this->output);
  }
  
  function stripTags($html) {
    $t= preg_replace('~\[\*(.*?)\*\]~', "", $html); //tv
    $t= preg_replace('~\[\[(.*?)\]\]~', "", $t); //snippet
    $t= preg_replace('~\[\!(.*?)\!\]~', "", $t); //snippet
    $t= preg_replace('~\[\((.*?)\)\]~', "", $t); //settings
    $t= preg_replace('~\[\+(.*?)\+\]~', "", $t); //placeholders
    $t= preg_replace('~{{(.*?)}}~', "", $t); //chunks
    return $t;
  }
  
  function strToTpl($str) {
    global $modx;
    
    $pre = substr($str, 0, 5);
    
    switch ($pre) {
      case '@CODE':
        $str = str_replace(array('@eq', '@amp'), array('=', '&'), trim(substr($str, 6)));
      break;
      
      case '@FILE':
        $fName = trim(substr($str, 6));
        if (file_exists($fName)) {
          $str = file_get_contents($fName);
        } else {
          $str = 'File '.$fName.' not found';
        }
      break;
      
      default:
        $str = $modx->getChunk($str);
      break;
    }
    
    return $str;
  }
}
?>