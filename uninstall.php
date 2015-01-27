<?php

if (basename(dirname(__FILE__)) != dirname(WP_UNINSTALL_PLUGIN))
{
    return;
}

require 'src/EnzymesPlugin.php';
EnzymesPlugin::on_uninstall();
