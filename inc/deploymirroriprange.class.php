<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginFusioninventoryDeploymirrorIprange extends CommonDBRelation {

	// From CommonDBRelation
   static public $itemtype_1    = 'PluginFusioninventoryDeployMirror';
   static public $items_id_1    = 'plugin_fusioninventory_deploymirrors_id';
   static public $take_entity_1 = true;
   
   static public $itemtype_2    = 'PluginFusioninventoryIPRange';
   static public $items_id_2    = 'plugin_fusioninventory_ipranges_id';
   static public $take_entity_2 = false;
   
   function getForbiddenStandardMassiveAction() {
      return array('MassiveAction:update');
   }

	function showForDisplaymirror(PluginFusioninventoryDeployMirror $item) {
   
      $instID = $item->fields['id'];
      if (!$item->can($instID, READ)) {
         return false;
      }
      
      $rand = mt_rand();

      $dp_iprange = new self();

      $datas = array();
      foreach ($dp_iprange->find("plugin_fusioninventory_deploymirrors_id = ".$item->getID()) as $data) {
      	$datas[] = $data;
      }
      $number = count($datas);

      $canedit = $item->can($instID, UPDATE);
   
      if ($canedit) {
         $target = Toolbox::getItemTypeFormURL(__CLASS__);
         echo "<form name='deploymirroriprange_form$rand' id='deploymirroriprange_form$rand' method='post' action='".$target."'>";
         
         echo "<table class='tab_cadre_fixe'>";
         echo "<tr class='tab_bg_2'><th colspan='2'>".__('Add an item')."</th></tr>";
         
         echo "<tr class='tab_bg_1'>";
         echo "<td class='right'>";
         $used = array();
	      foreach ($datas as $data) {
	      	$used[] = $data["plugin_fusioninventory_ipranges_id"];
	      }
         PluginFusioninventoryIPRange::dropdown(array(
         	'used' 	=> $used,
         	'entity' => $item->fields["entities_id"],
            'entity_sons' => true,
         	));
         echo "</td>";
         echo "<td class='center'>";
         echo "<input type='hidden' name='plugin_fusioninventory_deploymirrors_id' value='$instID'>";
         echo "<input type='submit' name='add' value=\""._sx('button', 'Add')."\" class='submit'>";
         echo "</td></tr>";
         echo "</table>";
         Html::closeForm();
      }
   
      echo "<div class='spaced'>";
      if ($canedit && $number) {
         Html::openMassiveActionsForm('mass'.__CLASS__.$rand);
         Html::showMassiveActions();
      }
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr>";

      if ($canedit && $number) {
         echo "<th width='10'>" . Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand) . "</th>";
      }
   
      echo "<th>" . PluginFusioninventoryIPRange::getTypeName() . "</th>";
      echo "<th>" . __("Start of IP range", 'fusioninventory') . "</th>";
      echo "<th>" . __("End of IP range", 'fusioninventory') . "</th>";
      echo "<th>" . __("Entity") . "</th>";
      echo "</tr>";

      $IPRange = new PluginFusioninventoryIPRange();

      foreach ($datas as $data) {
      	echo "<tr class='tab_bg_1 center'>";

         if ($canedit) {
            echo "<td width='10'>";
            if ($item->canUpdate()) {
               Html::showMassiveActionCheckBox(__CLASS__, $data["id"]);
            }
            echo "</td>";
         }

         $IPRange->getFromDB($data['plugin_fusioninventory_ipranges_id']);

			echo "<td>" . $IPRange->getLink() . "</td>";
      	echo "<td>" . $IPRange->fields["ip_start"] . "</td>";
      	echo "<td>" . $IPRange->fields["ip_end"] . "</td>";
      	echo "<td>" . Dropdown::getDropdownName("glpi_entities", $IPRange->fields["entities_id"]) . "</td>";
			echo "</tr>";

      }
      echo "</table>";
      if ($canedit && $number) {
         $massiveactionparams['ontop'] = false;
         Html::showMassiveActions($massiveactionparams);
         Html::closeForm();
      }
      echo "</div>";

	}

}