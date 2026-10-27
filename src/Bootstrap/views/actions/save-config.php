<?php

  use Magrathea\Config;
  use Magrathea\ConfigFile;
  use Magrathea\MagratheaPHP;

  $data = $_POST;

  $configPath = realpath(MagratheaPHP::Instance()->magRoot."/configs");
	$configFile = new ConfigFile();
  $configFile->SetPath($configPath)->SetFile("magrathea.conf");

  $env = Config::Instance()->GetEnvironment();
  $config = $configFile->GetConfig();

  foreach($data as $key => $value) {
    echo "Saving ".$key."... <br/>";
    $config[$env][$key] = $value;
  }

  $configFile->SetConfig($config);
  $configFile->Save();
  echo "<br/><br/>";
  echo "<span class='success'>DONE</div>";
//    print_r($data);

?>

