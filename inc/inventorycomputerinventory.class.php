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
   @author    David Durieux
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

class PluginFusioninventoryInventoryComputerInventory {
   private $arrayinventory = array();
   private $device_id = '';

   /**
   * Import data
   *
   * @param $p_DEVICEID XML code to import
   * @param $p_CONTENT XML code of the Computer
   * @param $p_CONTENT XML code of all agent have sent
   *
   * @return nothing (import ok) / error string (import ko)
   **/
   function import($p_DEVICEID, $a_CONTENT, $arrayinventory) {
      global $DB, $CFG_GLPI;

      $pfConfig = new PluginFusioninventoryConfig();

      $errors = '';
      $_SESSION["plugin_fusioninventory_entity"] = -1;

      // Prevent 2 computers with same name (Case of have the computer inventory 2 times in same time
      // and so we don't want it create 2 computers instead one)
      $name = '';
      if (isset($arrayinventory['CONTENT']['HARDWARE']['NAME'])) {
         $name = strtolower($arrayinventory['CONTENT']['HARDWARE']['NAME']);
      }

      // Clean all DB LOCK if exist more than 10 minutes
      $time = 600;
      $query = "DELETE FROM `glpi_plugin_fusioninventory_dblockinventorynames` "
              . " WHERE `date` <  CURRENT_TIMESTAMP() - ".$time;
      $query = "DELETE FROM `glpi_plugin_fusioninventory_dblockinventories` "
              . " WHERE `date` <  CURRENT_TIMESTAMP() - ".$time;
      $query = "DELETE FROM `glpi_plugin_fusioninventory_dblocksoftwares` "
              . " WHERE `date` <  CURRENT_TIMESTAMP() - ".$time;
      $query = "DELETE FROM `glpi_plugin_fusioninventory_dblocksoftwareversions` "
              . " WHERE `date` <  CURRENT_TIMESTAMP() - ".$time;

      // DB LOCK
      $query = "INSERT INTO `glpi_plugin_fusioninventory_dblockinventorynames`
            SET `value`='".$name."'";
      $CFG_GLPI["use_log_in_files"] = FALSE;
      $start_time = date('U');
      while(!$DB->query($query)) {
         usleep(100000);
         if ((date('U') - $start_time) > 5) {
            $communication = new PluginFusioninventoryCommunication();
            $communication->setMessage("<?xml version='1.0' encoding='UTF-8'?>
         <REPLY>
         <ERROR>ERROR: Timeout for DB lock based on name</ERROR>
         </REPLY>");
            $communication->sendMessage($_SESSION['plugin_fusioninventory_compressmode']);
            exit;
         }
      }
      $CFG_GLPI["use_log_in_files"] = TRUE;
      $this->sendCriteria($p_DEVICEID, $arrayinventory);
      $query = "DELETE FROM `glpi_plugin_fusioninventory_dblockinventorynames`
            WHERE `value`='".$name."'";
      $DB->query($query);

      return $errors;
   }



   /**
   * Send Computer to inventoryruleimport
   *
   * @param $p_DEVICEID XML code to import
   * @param $p_CONTENT XML code of the Computer
   * @param $p_CONTENT XML code of all agent have sent
   *
   * @return nothing
   *
   **/
   function sendCriteria($p_DEVICEID, $arrayinventory) {

      if (isset($_SESSION['plugin_fusioninventory_entityrestrict'])) {
         unset($_SESSION['plugin_fusioninventory_entityrestrict']);
      }

      $this->device_id = $p_DEVICEID;
      // * Hacks

         // Hack to put OS in software
         if (isset($arrayinventory['CONTENT']['HARDWARE']['OSNAME'])) {
            $inputos = array();
            if (isset($arrayinventory['CONTENT']['HARDWARE']['OSCOMMENTS'])) {
               $inputos['COMMENTS'] = $arrayinventory['CONTENT']['HARDWARE']['OSCOMMENTS'];
            }
            $inputos['NAME']     = $arrayinventory['CONTENT']['HARDWARE']['OSNAME'];
            if (isset($arrayinventory['CONTENT']['HARDWARE']['OSVERSION'])) {
               $inputos['VERSION']  = $arrayinventory['CONTENT']['HARDWARE']['OSVERSION'];
            }
            if (isset($arrayinventory['CONTENT']['SOFTWARES']['VERSION'])) {
               $temparray = $arrayinventory['CONTENT']['SOFTWARES'];
               $arrayinventory['CONTENT']['SOFTWARES'] = array();
               $arrayinventory['CONTENT']['SOFTWARES'][] = $temparray;
            }
            $arrayinventory['CONTENT']['SOFTWARES'][] = $inputos;
         }

         // Hack for USB Printer serial
         if (isset($arrayinventory['CONTENT']['PRINTERS'])) {
            foreach($arrayinventory['CONTENT']['PRINTERS'] as $key=>$printer) {
               if ((isset($printer['SERIAL']))
                       AND (preg_match('/\/$/', $printer['SERIAL']))) {
                  $arrayinventory['CONTENT']['PRINTERS'][$key]['SERIAL'] =
                        preg_replace('/\/$/', '', $printer['SERIAL']);
               }
            }
         }

         // Hack to remove Memories with Flash types see ticket
         // http://forge.fusioninventory.org/issues/1337
         if (isset($arrayinventory['CONTENT']['MEMORIES'])) {
            foreach($arrayinventory['CONTENT']['MEMORIES'] as $key=>$memory) {
               if ((isset($memory['TYPE']))
                       AND (preg_match('/Flash/', $memory['TYPE']))) {

                  unset($arrayinventory['CONTENT']['MEMORIES'][$key]);
               }
            }
         }
      // End hack
      $a_computerinventory = PluginFusioninventoryFormatconvert::computerInventoryTransformation(
                                             $arrayinventory['CONTENT']);

      // Get tag is defined and put it in fusioninventory_agent table
         $tagAgent = "";
         if (isset($a_computerinventory['ACCOUNTINFO'])) {
            if (isset($a_computerinventory['ACCOUNTINFO']['KEYNAME'])
                    && $a_computerinventory['ACCOUNTINFO']['KEYNAME'] == 'TAG') {
               if (isset($a_computerinventory['ACCOUNTINFO']['KEYVALUE'])
                       && $a_computerinventory['ACCOUNTINFO']['KEYVALUE'] != '') {
                  $tagAgent = $a_computerinventory['ACCOUNTINFO']['KEYVALUE'];
               }
            }
         }
         $pfAgent = new PluginFusioninventoryAgent();
         $input = array();
         $input['id'] = $_SESSION['plugin_fusioninventory_agents_id'];
         $input['tag'] = $tagAgent;
         $pfAgent->update($input);

      $pfBlacklist = new PluginFusioninventoryInventoryComputerBlacklist();
      $a_computerinventory = $pfBlacklist->cleanBlacklist($a_computerinventory);

      if (isset($a_computerinventory['monitor'])) {
         foreach ($a_computerinventory['monitor'] as $num=>$a_monit) {
            $a_computerinventory['monitor'][$num] = $pfBlacklist->cleanBlacklist($a_monit);
         }
      }
      $this->arrayinventory = $a_computerinventory;

      $input = array();

      // Global criterias

         if ((isset($a_computerinventory['Computer']['serial']))
                 AND (!empty($a_computerinventory['Computer']['serial']))) {
            $input['serial'] = $a_computerinventory['Computer']['serial'];
         }
         if ((isset($a_computerinventory['Computer']['uuid']))
                 AND (!empty($a_computerinventory['Computer']['uuid']))) {
            $input['uuid'] = $a_computerinventory['Computer']['uuid'];
         }
         foreach($a_computerinventory['networkport'] as $network) {
            if (((isset($network['virtualdev']))
                    && ($network['virtualdev'] != 1))
                    OR (!isset($network['virtualdev']))){
               if ((isset($network['mac'])) AND (!empty($network['mac']))) {
                  $input['mac'][] = $network['mac'];
               }
               foreach ($network['ipaddress'] as $ip) {
                  if ($ip != '127.0.0.1' && $ip != '::1') {
                     $input['ip'][] = $ip;
                  }
               }
               if ((isset($network['subnet'])) AND (!empty($network['subnet']))) {
                  $input['subnet'][] = $network['subnet'];
               }
            }
         }
         // Case of virtualmachines
         if (!isset($input['mac'])
                 && !isset($input['ip'])) {
            foreach($a_computerinventory['networkport'] as $network) {
               if ((isset($network['mac'])) AND (!empty($network['mac']))) {
                  $input['mac'][] = $network['mac'];
               }
               foreach ($network['ipaddress'] as $ip) {
                  if ($ip != '127.0.0.1' && $ip != '::1') {
                     $input['ip'][] = $ip;
                  }
               }
               if ((isset($network['subnet'])) AND (!empty($network['subnet']))) {
                  $input['subnet'][] = $network['subnet'];
               }
            }
         }

         if ((isset($a_computerinventory['Computer']['os_license_number']))
               AND (!empty($a_computerinventory['Computer']['os_license_number']))) {
            $input['mskey'] = $a_computerinventory['Computer']['os_license_number'];
         }
         if ((isset($a_computerinventory['Computer']['operatingsystems_id']))
               AND (!empty($a_computerinventory['Computer']['operatingsystems_id']))) {
            $input['osname'] = $a_computerinventory['Computer']['operatingsystems_id'];
         }
         if ((isset($a_inventory['fusioninventorycomputer']['oscomment']))
               AND (!empty($a_inventory['fusioninventorycomputer']['oscomment']))) {
            $input['oscomment'] = $a_inventory['fusioninventorycomputer']['oscomment'];
         }
         if ((isset($a_computerinventory['Computer']['computermodels_id']))
                 AND (!empty($a_computerinventory['Computer']['computermodels_id']))) {
            $input['model'] = $a_computerinventory['Computer']['computermodels_id'];
         }
         if ((isset($a_computerinventory['Computer']['domains_id']))
                 AND (!empty($a_computerinventory['Computer']['domains_id']))) {
            $input['domains_id'] = $a_computerinventory['Computer']['domains_id'];
         }

         // TODO
//         if (isset($arrayinventory['CONTENT']['STORAGES'])) {
//            foreach($arrayinventory['CONTENT']['STORAGES'] as $storage) {
//               if ((isset($storage['SERIALNUMBER'])) AND (!empty($storage['SERIALNUMBER']))) {
//                  $input['partitionserial'][] = $storage['SERIALNUMBER'];
//               }
//            }
//         }
//         if (isset($arrayinventory['CONTENT']['computerdisk'])) {
//            foreach($arrayinventory['CONTENT']['DRIVES'] as $drive) {
//               if ((isset($drive['SERIAL'])) AND (!empty($drive['SERIAL']))) {
//                  $input['hdserial'][] = $drive['SERIAL'];
//               }
//            }
//         }
         $input['tag'] = $tagAgent;

         if ((isset($a_computerinventory['Computer']['name']))
                 AND ($a_computerinventory['Computer']['name'] != '')) {
            $input['name'] = $a_computerinventory['Computer']['name'];
         } else {
            $input['name'] = '';
         }
         $input['itemtype'] = "Computer";

         // If transfer is disable, get entity and search only on this entity
         // (see http://forge.fusioninventory.org/issues/1503)
         $pfConfig = new PluginFusioninventoryConfig();
         $pfEntity = new PluginFusioninventoryEntity();

         // * entity rules
            $inputent = $input;
            if ((isset($a_computerinventory['Computer']['domains_id']))
                    AND (!empty($a_computerinventory['Computer']['domains_id']))) {
               $inputent['domain'] = $a_computerinventory['Computer']['domains_id'];
            }
            if (isset($inputent['serial'])) {
               $inputent['serialnumber'] = $inputent['serial'];
            }
            $ruleEntity = new PluginFusioninventoryInventoryRuleEntityCollection();

            // * Reload rules (required for unit tests)
            $ruleEntity->getCollectionPart();

            $dataEntity = $ruleEntity->processAllRules($inputent, array());
            if (isset($dataEntity['_ignore_import'])) {
               return;
            }

            if (isset($dataEntity['entities_id'])
                    && $dataEntity['entities_id'] >= 0) {
               $_SESSION["plugin_fusioninventory_entity"] = $dataEntity['entities_id'];
               $input['entities_id'] = $dataEntity['entities_id'];

            } else if (isset($dataEntity['entities_id'])
                    && $dataEntity['entities_id'] == -1) {
               $input['entities_id'] = 0;
               $_SESSION["plugin_fusioninventory_entity"] = -1;
            } else {
               $input['entities_id'] = 0;
               $_SESSION["plugin_fusioninventory_entity"] = 0;
            }

            if (isset($dataEntity['locations_id'])) {
               $_SESSION['plugin_fusioninventory_locations_id'] = $dataEntity['locations_id'];
            }
         // End entity rules
      $_SESSION['plugin_fusioninventory_classrulepassed'] =
                     "PluginFusioninventoryInventoryComputerInventory";

      $ruleLocation = new PluginFusioninventoryInventoryRuleLocationCollection();

      // * Reload rules (required for unit tests)
      $ruleLocation->getCollectionPart();

      $dataLocation = $ruleLocation->processAllRules($input, array());
      if (isset($dataLocation['locations_id'])) {
         $_SESSION['plugin_fusioninventory_locations_id'] =
               $dataLocation['locations_id'];
      }

      $rule = new PluginFusioninventoryInventoryRuleImportCollection();

      // * Reload rules (required for unit tests)
      $rule->getCollectionPart();

      $data = $rule->processAllRules($input, array(), array('class'=>$this));
      PluginFusioninventoryToolbox::logIfExtradebug("pluginFusioninventory-rules",
                                                   $data);

      if (isset($data['_no_rule_matches']) AND ($data['_no_rule_matches'] == '1')) {
         $this->rulepassed(0, "Computer");
      } else if (!isset($data['found_equipment'])) {
         $pfIgnoredimportdevice = new PluginFusioninventoryIgnoredimportdevice();
         $inputdb = array();
         $inputdb['name'] = $input['name'];
         $inputdb['date'] = date("Y-m-d H:i:s");
         $inputdb['itemtype'] = "Computer";

         if ((isset($a_computerinventory['Computer']['domains_id']))
                    AND (!empty($a_computerinventory['Computer']['domains_id']))) {
               $inputdb['domain'] = $a_computerinventory['Computer']['domains_id'];
            }
         if (isset($a_computerinventory['Computer']['serial'])) {
            $inputdb['serial'] = $a_computerinventory['Computer']['serial'];
         }
         if (isset($a_computerinventory['Computer']['uuid'])) {
            $inputdb['uuid'] = $a_computerinventory['Computer']['uuid'];
         }
         if (isset($input['ip'])) {
            $inputdb['ip'] = $input['ip'];
         }
         if (isset($input['mac'])) {
            $inputdb['mac'] = $input['mac'];
         }

         $inputdb['entities_id'] = $input['entities_id'];

         if (isset($input['ip'])) {
            $inputdb['ip'] = exportArrayToDB($input['ip']);
         }
         if (isset($input['mac'])) {
            $inputdb['mac'] = exportArrayToDB($input['mac']);
         }
         $inputdb['rules_id'] = $data['_ruleid'];
         $inputdb['method'] = 'inventory';
         $pfIgnoredimportdevice->add($inputdb);
      }
   }



   /**
   * If rule have found computer or rule give to create computer
   *
   * @param $items_id integer id of the computer found (or 0 if must be created)
   * @param $itemtype value Computer type here
   *
   * @return nothing
   *
   **/
   function rulepassed($items_id, $itemtype) {
      global $DB, $PLUGIN_FUSIONINVENTORY_XML, $PF_ESXINVENTORY, $CFG_GLPI;

      PluginFusioninventoryToolbox::logIfExtradebug(
         "pluginFusioninventory-rules",
         "Rule passed : ".$items_id.", ".$itemtype."\n"
      );
      $pfFormatconvert = new PluginFusioninventoryFormatconvert();

      $a_computerinventory = $pfFormatconvert->replaceids($this->arrayinventory);
      $entities_id = $_SESSION["plugin_fusioninventory_entity"];

      if ($itemtype == 'Computer') {
         $pfInventoryComputerLib = new PluginFusioninventoryInventoryComputerLib();
         $pfAgent                = new PluginFusioninventoryAgent();

         $computer   = new Computer();
         if ($items_id == '0') {
            if ($entities_id == -1) {
               $entities_id = 0;
               $_SESSION["plugin_fusioninventory_entity"] = 0;
            }
            $_SESSION['glpiactiveentities']        = array($entities_id);
            $_SESSION['glpiactiveentities_string'] = $entities_id;
            $_SESSION['glpiactive_entity']         = $entities_id;
         } else {
            $computer->getFromDB($items_id);
            $a_computerinventory['Computer']['states_id'] = $computer->fields['states_id'];
            $input = array();
            PluginFusioninventoryInventoryComputerInventory::addDefaultStateIfNeeded($input);
            if (isset($input['states_id'])) {
                $a_computerinventory['Computer']['states_id'] = $input['states_id'];
            }

            if ($entities_id == -1) {
               $entities_id = $computer->fields['entities_id'];
               $_SESSION["plugin_fusioninventory_entity"] = $computer->fields['entities_id'];
            }

            $_SESSION['glpiactiveentities']        = array($entities_id);
            $_SESSION['glpiactiveentities_string'] = $entities_id;
            $_SESSION['glpiactive_entity']         = $entities_id;

            if ($computer->fields['entities_id'] != $entities_id) {
               $pfEntity = new PluginFusioninventoryEntity();
               $pfInventoryComputerComputer = new PluginFusioninventoryInventoryComputerComputer();
               $moveentity = FALSE;
               if ($pfEntity->getValue('transfers_id_auto', $computer->fields['entities_id']) > 0) {
                  if (!$pfInventoryComputerComputer->getLock($items_id)) {
                     $moveentity = TRUE;
                  }
               }
               if ($moveentity) {
                  $pfEntity = new PluginFusioninventoryEntity();
                  $transfer = new Transfer();
                  $transfer->getFromDB($pfEntity->getValue('transfers_id_auto', $entities_id));
                  $item_to_transfer = array("Computer" => array($items_id=>$items_id));
                  $transfer->moveItems($item_to_transfer, $entities_id, $transfer->fields);
               } else {
                  $_SESSION["plugin_fusioninventory_entity"] = $computer->fields['entities_id'];
                  $_SESSION['glpiactiveentities']        = array($computer->fields['entities_id']);
                  $_SESSION['glpiactiveentities_string'] = $computer->fields['entities_id'];
                  $_SESSION['glpiactive_entity']         = $computer->fields['entities_id'];
                  $entities_id = $computer->fields['entities_id'];
               }
            }
         }
         if ($items_id > 0) {
            $a_computerinventory = $pfFormatconvert->extraCollectInfo(
                                                   $a_computerinventory,
                                                   $items_id);
         }
         $a_computerinventory = $pfFormatconvert->computerSoftwareTransformation(
                                                $a_computerinventory,
                                                $entities_id);

         $no_history = FALSE;
         // * New
         $setdynamic = 1;
         if ($items_id == '0') {
            $input = array();
            $input['entities_id'] = $entities_id;
            PluginFusioninventoryInventoryComputerInventory::addDefaultStateIfNeeded($input);
            if (isset($input['states_id'])) {
                $a_computerinventory['Computer']['states_id'] = $input['states_id'];
            } else {
                $a_computerinventory['Computer']['states_id'] = 0;
            }
            $items_id = $computer->add($input);
            $no_history = TRUE;
            $setdynamic = 0;
         }
         if (isset($_SESSION['plugin_fusioninventory_locations_id'])) {
               $a_computerinventory['Computer']['locations_id'] =
                                 $_SESSION['plugin_fusioninventory_locations_id'];
               unset($_SESSION['plugin_fusioninventory_locations_id']);
            }

         $serialized = gzcompress(serialize($a_computerinventory));
         $a_computerinventory['fusioninventorycomputer']['serialized_inventory'] =
                  Toolbox::addslashes_deep($serialized);

         if (!$PF_ESXINVENTORY) {
            $pfAgent->setAgentWithComputerid($items_id, $this->device_id, $entities_id);
         }

         $pfConfig = new PluginFusioninventoryConfig();

         $query = "INSERT INTO `glpi_plugin_fusioninventory_dblockinventories`
            SET `value`='".$items_id."'";
         $CFG_GLPI["use_log_in_files"] = FALSE;
         if (!$DB->query($query)) {
            $communication = new PluginFusioninventoryCommunication();
            $communication->setMessage("<?xml version='1.0' encoding='UTF-8'?>
         <REPLY>
         <ERROR>ERROR: SAME COMPUTER IS CURRENTLY UPDATED</ERROR>
         </REPLY>");
            $communication->sendMessage($_SESSION['plugin_fusioninventory_compressmode']);
            exit;
         }
         $CFG_GLPI["use_log_in_files"] = TRUE;

         // * For benchs
         //$start = microtime(TRUE);

         PluginFusioninventoryInventoryComputerStat::increment();

         $pfInventoryComputerLib->updateComputer(
                 $a_computerinventory,
                 $items_id,
                 $no_history,
                 $setdynamic);

         $query = "DELETE FROM `glpi_plugin_fusioninventory_dblockinventories`
               WHERE `value`='".$items_id."'";
         $DB->query($query);

         $plugin = new Plugin();
         if ($plugin->isActivated('monitoring')) {
            Plugin::doOneHook("monitoring", "ReplayRulesForItem", array('Computer', $items_id));
         }
         // * For benchs
         //Toolbox::logInFile("exetime", (microtime(TRUE) - $start)." (".$items_id.")\n".
         //  memory_get_usage()."\n".
         //  memory_get_usage(TRUE)."\n".
         //  memory_get_peak_usage()."\n".
         //  memory_get_peak_usage()."\n");

         if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
            $pfRulematchedlog = new PluginFusioninventoryRulematchedlog();
            $inputrulelog = array();
            $inputrulelog['date'] = date('Y-m-d H:i:s');
            $inputrulelog['rules_id'] = $_SESSION['plugin_fusioninventory_rules_id'];
            if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
               $inputrulelog['plugin_fusioninventory_agents_id'] =
                              $_SESSION['plugin_fusioninventory_agents_id'];
            }
            $inputrulelog['items_id'] = $items_id;
            $inputrulelog['itemtype'] = $itemtype;
            $inputrulelog['method'] = 'inventory';
            $pfRulematchedlog->add($inputrulelog, array(), FALSE);
            $pfRulematchedlog->cleanOlddata($items_id, $itemtype);
            unset($_SESSION['plugin_fusioninventory_rules_id']);
         }
         // Write XML file
         if (!empty($PLUGIN_FUSIONINVENTORY_XML)) {
            PluginFusioninventoryToolbox::writeXML(
                    $items_id,
                    $PLUGIN_FUSIONINVENTORY_XML->asXML(),
                    'computer');
         }
      } else if ($itemtype == 'PluginFusioninventoryUnmanaged') {

         $a_computerinventory = $pfFormatconvert->computerSoftwareTransformation(
                                                $a_computerinventory,
                                                $entities_id);

         $class = new $itemtype();
         if ($items_id == "0") {
            if ($entities_id == -1) {
               $entities_id = 0;
               $_SESSION["plugin_fusioninventory_entity"] = 0;
            }
            $input = array();
            $input['date_mod'] = date("Y-m-d H:i:s");
            $items_id = $class->add($input);
            if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
               $pfRulematchedlog = new PluginFusioninventoryRulematchedlog();
               $inputrulelog = array();
               $inputrulelog['date'] = date('Y-m-d H:i:s');
               $inputrulelog['rules_id'] = $_SESSION['plugin_fusioninventory_rules_id'];
               if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
                  $inputrulelog['plugin_fusioninventory_agents_id'] =
                                 $_SESSION['plugin_fusioninventory_agents_id'];
               }
               $inputrulelog['items_id'] = $items_id;
               $inputrulelog['itemtype'] = $itemtype;
               $inputrulelog['method'] = 'inventory';
               $pfRulematchedlog->add($inputrulelog);
               $pfRulematchedlog->cleanOlddata($items_id, $itemtype);
               unset($_SESSION['plugin_fusioninventory_rules_id']);
            }
         }
         $class->getFromDB($items_id);
         $_SESSION["plugin_fusioninventory_entity"] = $class->fields['entities_id'];
         $input = array();
         $input['id'] = $class->fields['id'];

         // Write XML file
         if (!empty($PLUGIN_FUSIONINVENTORY_XML)) {
            PluginFusioninventoryToolbox::writeXML(
                    $items_id,
                    $PLUGIN_FUSIONINVENTORY_XML->asXML(),
                    'PluginFusioninventoryUnmanaged');
         }

         if (isset($a_computerinventory['Computer']['name'])) {
            $input['name'] = $a_computerinventory['Computer']['name'];
         }
         $input['item_type'] = "Computer";
         if (isset($a_computerinventory['Computer']['domains_id'])) {
            $input['domain'] = $a_computerinventory['Computer']['domains_id'];
         }
         if (isset($a_computerinventory['Computer']['serial'])) {
            $input['serial'] = $a_computerinventory['Computer']['serial'];
         }
         $class->update($input);
      }
   }



   /**
    * Get default value for state of devices (monitor, printer...)
    *
    * @param type $input
    * @param type $check_management
    * @param type $management_value
    *
    */
   static function addDefaultStateIfNeeded(&$input, $check_management = FALSE,
                                           $management_value = 0) {
      $config = new PluginFusioninventoryConfig();
      $state = $config->getValue("states_id_default");
      if ($state) {
         if (!$check_management || ($check_management && !$management_value)) {
            $input['states_id'] = $state;
         }
      }
      return $input;
   }



   /**
    * Return method name of this class/plugin
    *
    * @return value
    */
   static function getMethod() {
      return 'inventory';
   }
}

?>
