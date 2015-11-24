<?php

/*
   ------------------------------------------------------------------------
   FusionInventory
   Copyright (C) 2010-2015 by the FusionInventory Development Team.

   http://www.fusioninventory.org/   http://forge.fusioninventory.org/
   ------------------------------------------------------------------------

   LICENSE

   This file is part of FusionInventory project.

   FusionInventory is free software: you can redistribute it and/or modify
   it under the terms of the GNU Affero General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   FusionInventory is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   GNU Affero General Public License for more details.

   You should have received a copy of the GNU Affero General Public License
   along with FusionInventory. If not, see <http://www.gnu.org/licenses/>.

   ------------------------------------------------------------------------

   @package   FusionInventory
   @author    Walid Nouh
   @co-author
   @copyright Copyright (c) 2010-2015 FusionInventory team
   @license   AGPL License 3.0 or (at your option) any later version
              http://www.gnu.org/licenses/agpl-3.0-standalone.html
   @link      http://www.fusioninventory.org/
   @link      http://forge.fusioninventory.org/projects/fusioninventory-for-glpi/
   @since     2010

   ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginFusioninventoryDeployMirror extends CommonDBTM {

   public $dohistory = TRUE;

   static $rightname = 'plugin_fusioninventory_deploymirror';

   static function getTypeName($nb=0) {
      return __('Mirror servers', 'fusioninventory');
   }



   function defineTabs($options=array()) {

      $ong=array();
      $ong[1]=__('Main');


      if ($this->fields['id'] > 0) {
         $ong[12]=__('Historical');

      }
      $ong['no_all_tab'] = TRUE;

      return $ong;
   }

   static function getIPAdressesOf($computer_id) {
      global $DB;

      $ip_adresses = array();

      foreach ($DB->request('glpi_networkports',
                           array('itemtype' => 'Computer',
                                 'items_id' => $computer_id,
                                 'is_deleted' => '0')) as $np_data) {
         foreach ($DB->request('glpi_networknames',
                               array('itemtype' => 'NetworkPort',
                                      'items_id' => $np_data['id'])) as $dataname) {
            foreach ($DB->request('glpi_ipaddresses',
                                  array('itemtype' => 'NetworkName',
                                        'items_id' => $dataname['id'])) as $data) {
               $ip_adresses[] = $data['name'];
            }
         }
      }

      return $ip_adresses;
   }


   /*
    * Get and filter mirrors list by computer agent and location and entities (and tag)
    * Location is retrieved from the computer data.
    */

   static function getList($agents_id = NULL) {
      global $PF_CONFIG;

      if (is_null($agents_id)) {
         return array();
      }

      $pfAgent = new PluginFusioninventoryAgent();
      $pfAgent->getFromDB($agents_id);
      $agent = $pfAgent->fields;

      if (!isset($agent) || !isset($agent['computers_id'])) {
         return array();
      }

      $results = getAllDatasFromTable('glpi_plugin_fusioninventory_deploymirrors');

      $computer = new Computer();
      $computer->getFromDB($agent['computers_id']);

      $mirrors = array();

      $computer_locations  = getSonsOf('glpi_locations', $computer->fields['locations_id']);
      $computer_entities   = getSonsOf('glpi_entities', $computer->fields['entities_id']);

      $where = empty($computer_locations) ? "" : "locations_id IN (".implode(',', $computer_locations).") ";
      if (! empty($computer_entities) ) {
         if (! empty($where)) {
            $where .= "AND ";
         }
         $where .= "entities_id IN (" . implode(',', $computer_entities) . ")";
      }

      $d_mirror = new self();
      foreach ($d_mirror->find($where) as $result) {

         $ip_adresses = self::getIPAdressesOf($computer->getID());

         $pfIPRange = new PluginFusioninventoryIPRange();

         foreach ($ip_adresses as $ip_adress) {
            $pfIPRange->getFromDB($result['plugin_fusioninventory_ipranges_id']);

            // Range IP
            $s = $pfIPRange->getIp2long($pfIPRange->fields['ip_start']);
            $e = $pfIPRange->getIp2long($pfIPRange->fields['ip_end']);

            $i = $pfIPRange->getIp2long($ip_adress);

            // If not in range IP
            if (! ($s <= $i  && $i <= $e)) {
               continue;
            }

         }

         // Hook to implement to restrict mirror (used by Plugin Tag)
         if (! Plugin::doHookFunction("fusionventory_mirror_restrict", array($computer, $result, $agent))) {
            continue;
         }

         $mirrors[] = $result['url'];
      }

      //add default mirror (this server) if enabled in config
      $entities_id = 0;
      if (isset($agent['entities_id'])) {
         $entities_id = $agent['entities_id'];
      }
      if ( isset($PF_CONFIG['server_as_mirror'])
              && (bool)$PF_CONFIG['server_as_mirror'] == TRUE) {
         $mirrors[] = PluginFusioninventoryAgentmodule::getUrlForModule('DEPLOY', $entities_id)
            ."?action=getFilePart&file=";
      }

      return $mirrors;
   }


   function showForm($id, $options=array()) {
      global $CFG_GLPI;

      if ($id!='') {
         $this->getFromDB($id);
      } else {
         $this->getEmpty();
      }
      $this->showTabs($options);
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Name')."&nbsp;:</td>";
      echo "<td align='center'>";
      Html::autocompletionTextField($this,'name', array('size' => 40));
      echo "</td>";

      echo "<td rowspan='2' class='middle right'>".__('Comments')."&nbsp;: </td>";
      echo "<td class='center middle' rowspan='2'><textarea cols='45'
      rows='4' name='comment' >".$this->fields["comment"]."</textarea></td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Mirror server address', 'fusioninventory')."&nbsp;:</td>";
      echo "<td align='center'>";
      Html::autocompletionTextField($this,'url', array('size' => 40));
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Mirror location', 'fusioninventory')."&nbsp;:</td>";
      echo "<td align='center'>";

      echo "<script type='text/javascript'>\n";
      echo "document.getElementsByName('is_recursive')[0].id = 'is_recursive';\n";
      echo "</script>";

      $params = array('is_recursive' => '__VALUE__',
                      'id'           => $id);
      Ajax::updateItemOnEvent('is_recursive', "displaydropdownlocation",
              $CFG_GLPI["root_doc"]."/plugins/fusioninventory/ajax/dropdownlocation.php", $params);

      echo "<div id='displaydropdownlocation'>";
      // Location option
      Location::dropdown(
         array(
            'value'  => $this->fields["locations_id"],
            'entity' => $this->fields["entities_id"],
            'entity_sons' => $this->isRecursive(),
         )
      );
      echo "</div>";
      echo "</td>";
      echo "<td><label>" . PluginFusioninventoryIPRange::getTypeName(). "</label> :</td>";
      echo "<td>";
      PluginFusioninventoryIPRange::dropdown(array(
         'value' => $this->fields['plugin_fusioninventory_ipranges_id'])
      );
      echo "</td></tr>";

      $this->showFormButtons($options);

      echo "<div id='tabcontent'></div>";

      echo "<script type='text/javascript'>loadDefaultTab();
      </script>";

      return TRUE;
   }



   function getSearchOptions() {

      $tab = array();
      $tab[1]['table']         = $this->getTable();
      $tab[1]['field']         = 'name';
      $tab[1]['linkfield']     = 'name';
      $tab[1]['name']          = __('Name');
      $tab[1]['datatype']      = 'itemlink';
      $tab[1]['itemlink_type'] = $this->getType();

      $tab[19]['table']     = $this->getTable();
      $tab[19]['field']     = 'date_mod';
      $tab[19]['linkfield'] = '';
      $tab[19]['name']      = __('Last update');
      $tab[19]['datatype']  = 'datetime';

      $tab[2]['table']     = $this->getTable();
      $tab[2]['field']     = 'url';
      $tab[2]['linkfield'] = 'url';
      $tab[2]['name']      = __('Mirror server address', 'fusioninventory');
      $tab[2]['datatype']  = 'string';

      $tab[16]['table']     = $this->getTable();
      $tab[16]['field']     = 'comment';
      $tab[16]['linkfield'] = 'comment';
      $tab[16]['name']      = __('Comments');
      $tab[16]['datatype']  = 'text';

      $tab[80]['table']     = 'glpi_entities';
      $tab[80]['field']     = 'completename';
      $tab[80]['name']      = __('Entity');


      $tab[81]['table']     = getTableNameForForeignKeyField('locations_id');
      $tab[81]['field']     = 'completename';
      $tab[81]['linkfield'] = 'locations_id';
      $tab[81]['name']      = Location::getTypeName(); //__('Mirror location', 'fusioninventory');
      $tab[81]['datatype']  = 'itemlink';

      $tab[86]['table']     = $this->getTable();
      $tab[86]['field']     = 'is_recursive';
      $tab[86]['linkfield'] = 'is_recursive';
      $tab[86]['name']      = __('Child entities');
      $tab[86]['datatype']  = 'bool';

      /*
      $tab[87]['table']     = 'glpi_locations';
      $tab[87]['field']     = 'name';
      $tab[87]['linkfield'] = 'locations_id';
      $tab[87]['name']      = __('Mirror location', 'fusioninventory');
      $tab[87]['datatype']  = 'dropdown';
      */

      $tab[88]['table']     = getTableForItemType('PluginFusioninventoryIPRange');
      $tab[88]['field']     = 'name';
      $tab[88]['linkfield'] = 'plugin_fusioninventory_ipranges_id';
      $tab[88]['name']      = PluginFusioninventoryIPRange::getTypeName();
      $tab[88]['datatype']  = 'dropdown';

      return $tab;
   }


   /**
    * Massive action ()
    */
   function getSpecificMassiveActions($checkitem=NULL) {

      $actions = array();
      $actions[__CLASS__.MassiveAction::CLASS_ACTION_SEPARATOR.'transfert'] = __('Transfer');

      return $actions;
   }


   /**
    * @since version 0.85
    *
    * @see CommonDBTM::showMassiveActionsSubForm()
   **/
   static function showMassiveActionsSubForm(MassiveAction $ma) {
      switch ($ma->getAction()) {
         case "transfert": 
            Dropdown::show('Entity');
            echo Html::submit(_x('button','Post'), array('name' => 'massiveaction'));
            return true;
            break;
      }
   }

   /**
    * @since version 0.85
    *
    * @see CommonDBTM::processMassiveActionsForOneItemtype()
   **/
   static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item,
                                                       array $ids) {

      $pfDeployMirror = new self();

      switch ($ma->getAction()) {
         case "transfert" :
            
            foreach($ids as $key) {
               if ($pfDeployMirror->getFromDB($key)) {
                  $input = array();
                  $input['id'] = $key;
                  $input['entities_id'] = $_POST['entities_id'];
                  if ($pfDeployMirror->update($input)) {
                     //set action massive ok for this item
                     $ma->itemDone($item->getType(), $key, MassiveAction::ACTION_OK);
                  } else {
                     // KO
                     $ma->itemDone($item->getType(), $key, MassiveAction::ACTION_KO);
                  }
               }
            }

            break;
      } 
   }
}

?>
