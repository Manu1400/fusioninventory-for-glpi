<?php
include ("../../../inc/includes.php");

Session::checkLoginUser();

//Plugin::load('fusioninventory', true);

if (isset ($_REQUEST["add"])) {
	$link = new PluginFusioninventoryDeploymirrorIprange();
   $newID = $link->add($_REQUEST, 1);

   Html::back();
}