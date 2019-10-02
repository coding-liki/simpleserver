<?php

$cfg_path = __DIR__."/../../../../configs/simple_server.php";

$cfg_example_path = __DIR__."/../config_example/simple_server.php";


copy($cfg_example_path, $cfg_path);
