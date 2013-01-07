<?php
/**
** formTemplates.inc.php
**/

//Этот файл содержит шаблоны элементов формы фильтрации

$tpl['isset'] = <<<CODE
  <input type="checkbox" name="[+name+]_isset" value="checked" [+checked+] />
CODE;

$tpl['snames'] = <<<CODE
  <select name="[+name+]_snames">
    <option value="" > Любой </option>
    [+loop+]
    <option value="[+value+]" [+selected+][+hidden+]> [+valname+] </option>
    [+end_loop+]
  </select>
CODE;

$tpl['select'] = <<<CODE
  <select name="[+name+]_select">
    <option value="" > Не задано </option>
    [+loop+]
    <option value="[+value+]" [+selected+][+hidden+]> [+value+] </option>
    [+end_loop+]
  </select>
CODE;

$tpl['radio'] = <<<CODE
  [+loop+]
    <br/><label>[+value+] <input class="radio[+hidden+]" type="radio" name="[+name+]_radio" value="[+value+]" [+checked+] /> </label>
  [+end_loop+]
CODE;

$tpl['checkboxes'] = <<<CODE
  [+loop+]
    <label>[+value+] <input class="chckbox[+hidden+]" type="checkbox" name="[+name+]_checkboxes[]" value="[+value+]" [+checked+] /> </label><br />
  [+end_loop+]
CODE;

$tpl['cnames'] = <<<CODE
  [+loop+]
    <label>[+valname+] <input type="checkbox" name="[+name+]_cnames[]" value="[+value+]" [+checked+] /> </label>
  [+end_loop+]
CODE;

$tpl['minmax'] = <<<CODE
  <script>
  $(function() {
    $("#slider[+name+]").slider({
      range: true,
      min: [+min+],
      max: [+max+],
      values: [ [+umin+], [+umax+] ],
      slide: function( event, ui ) {
            $("#umin[+name+]").val(ui.values[ 0 ]);
            $("#umax[+name+]").val(ui.values[ 1 ]);
      }

    });

    $("#umin[+name+]").val($("#slider[+name+]").slider("values", 0));
    $("#umax[+name+]").val($("#slider[+name+]").slider("values", 1));

    /*$("#slider[+name+]").bind("slidechange", function(event, ui) {
      $('#afgo').click();
    });*/

  });
  </script>



<div class="sliderdiv">

<p>
Задайте значения:
  <label for="umin[+name+]">
    <input type="text" name="[+name+]_minmax[0]" id="umin[+name+]" />
  </label>
  <label for="umax[+name+]">
    <input type="text" name="[+name+]_minmax[1]" id="umax[+name+]" />
  </label>
</p>

<div id="slider[+name+]"></div>

</div>
CODE;

?>
