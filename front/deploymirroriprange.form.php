<?php
include ("../../../inc/includes.php");

Session::checkLoginUser();

if (isset ($_REQUEST["add"])) {
	if (isset($_REQUEST['plugin_fusioninventory_ipranges_id']) && !empty($_REQUEST["plugin_fusioninventory_ipranges_id"])) {
		$link = new PluginFusioninventoryDeploymirrorIprange();
   	$newID = $link->add($_REQUEST, 1);
	}

   Html::back();
}