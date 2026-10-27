<?php

	use Magrathea\Config;
	use Magrathea\DB\Database;
	use Magrathea\MagratheaPHP;

	$config = Config::Instance();
	MagratheaPHP::Instance()->Connect();
	$magDb = Database::Instance();

	$success = false;
	try {
		$success = $magDb->OpenConnectionPlease();
		$magDb->CloseConnectionThanks();
	} catch(Exception $ex){
		$success = $ex->getMessage();
	}

	if($success) {
		echo '<span class="success">Connection successful!</span>';
	}
