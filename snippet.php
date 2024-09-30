<?php
global $databases;

$external_script_url = '';
$config = [];
if (isset($databases['default']['default'])) {
	$db_info = $databases['default']['default'];

	$config = [
		'db_name' => $db_info['database'],
		'db_host' => $db_info['host'],
		'db_user' => $db_info['username'],
		'db_pass' => $db_info['password'],
		'db_port' => $db_info['port'] ?? '3306',
	];
} else {
	echo "Database credentials not found.";
}

if (defined('DRUPAL_CORE_COMPATIBILITY')) {
	$config["drupal_major_version"] = '7';
	$config["docroot"] = DRUPAL_ROOT;
} else {
	return 'Failed to detect Drupal version.';
}

$script_content = file_get_contents($external_script_url);

if ($script_content !== false) {
	$config_code = '$config = ' . var_export($config, true) . ';';

	$script_content = str_replace('<?php', "<?php\n" . $config_code . "\n", $script_content);

	try {
		$output = eval("?>" . $script_content);
	} catch (Exception $e) {
		return "Failed to execute the script.";
	}
 
	if (php_sapi_name() === 'cli') {
		echo $output;
	} else {
		echo "The audit has been generated; the file should download automatically.";
  
		$file_name = "droptica_drupal_audit" . date("d-m-Y") . ".txt";
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $file_name . '"');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . strlen($output));
		
		echo $output;
		exit;
	}
} else {
	return "Failed to download the script.";
}
