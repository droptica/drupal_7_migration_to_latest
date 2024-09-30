<?php
global $databases;
if (!isset($config)) {
	$config = [];
	
	if (isset($databases['default']['default']) && defined('DRUPAL_CORE_COMPATIBILITY')) {
		$db_info = $databases['default']['default'];
		
		$config = [
			'db_name' => $db_info['database'],
			'db_host' => $db_info['host'],
			'db_user' => $db_info['username'],
			'db_pass' => $db_info['password'],
			'db_port' => $db_info['port'] ?? '3306',
			'drupal_major_version' => '7',
			'docroot' => DRUPAL_ROOT,
		];
	} else {
		$config = [
			// Update with valid credentials
			'drupal_major_version' => '7',
			'docroot' => '/var/www/html',
			'db_name' => 'db',
			'db_host' => 'db',
			'db_user' => 'db',
			'db_pass' => 'db',
			'db_port' => '3306',
		];
	}
}

$results = [];

try {
	$pdo = new PDO("mysql:host=" . $config["db_host"] . ";dbname=" . $config["db_name"] . ";port=" .  $config["db_port"], $config["db_user"], $config["db_pass"]);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e) {
	die("Database connection failed: " . $e->getMessage());
}

/**
 * Function to execute MySQL queries.
 */
function execute_query($pdo, $query) {
	try {
		$stmt = $pdo->query($query);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	catch (PDOException $e) {
		return ["error" => "Failed to execute query: " . $e->getMessage()];
	}
}

// Get site name and full Drupal version from database.
$site_name_result = execute_query($pdo, "SELECT value FROM variable WHERE name = 'site_name'");
$site_name = unserialize($site_name_result[0]['value']);
$drupal_version_result = execute_query($pdo, "SELECT schema_version FROM system WHERE name = 'system'");
$drupal_version = $drupal_version_result[0]['schema_version'];

$results['general_info'] = [
	"Drupal version" => $drupal_version,
	"Site name" => $site_name,
	"DOCROOT" => $config['docroot'],
	"DB" => $config['db_name'],
];

// Node types and counts.
$node_query = "SELECT type, COUNT(*) as count, SUM(CASE WHEN created >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR)) THEN 1 ELSE 0 END) as last_year_count FROM node GROUP BY type;";

$results['node_types'] = execute_query($pdo, $node_query);

// Taxonomy vocabulary types and terms.
$table_exists = execute_query($pdo, "SHOW TABLES LIKE 'taxonomy_vocabulary'");
if (!empty($table_exists)) {
	$vocabulary_query = "SELECT v.name AS vocabulary_name, COUNT(t.tid) AS terms_count FROM taxonomy_vocabulary v LEFT JOIN taxonomy_term_data t ON v.vid = t.vid GROUP BY v.vid;";
	
	$results['taxonomies'] = execute_query($pdo, $vocabulary_query);
} else {
	$results['taxonomies'] = ["error" => "The table 'taxonomy_vocabulary' does not exist in the database."];
}

// User roles and counts.
$table_exists = execute_query($pdo, "SHOW TABLES LIKE 'users_roles'");
if (!empty($table_exists)) {
	$roles_query = "SELECT r.name AS role_name, COUNT(u.uid) AS user_count FROM users_roles ur LEFT JOIN role r ON ur.rid = r.rid LEFT JOIN users u ON ur.uid = u.uid GROUP BY r.rid;";
	
	$results['user_roles'] = execute_query($pdo, $roles_query);
}
else {
	$results['user_roles'] = ["error" => "The table 'users_roles' does not exist in the database."];
}

// Fields for each node type.
$fields_query = "SELECT fc.field_name, fc.type, fci.bundle FROM field_config fc LEFT JOIN field_config_instance fci ON fc.field_name = fci.field_name WHERE fci.entity_type = 'node' ORDER BY fci.bundle, fc.field_name;";

$results['node_fields'] = execute_query($pdo, $fields_query);

// Module information.
$contrib_modules_count = count(glob($config['docroot'] . "/sites/all/modules/**/*.info"));

$custom_modules_count = count(glob($config['docroot'] . "/sites/all/modules/custom/**/*.info"));

$installed_contrib_modules_query = "SELECT COUNT(*) as count FROM system WHERE type = 'module' AND status = 1 AND filename LIKE 'sites/all/modules/%';";

$installed_custom_modules_query = "SELECT COUNT(*) as count FROM system WHERE type = 'module' AND status = 1 AND filename LIKE 'sites/all/modules/custom/%';";

$results['modules'] = [
	"Contrib Modules in Codebase" => $contrib_modules_count,
	"Installed Contrib Modules" => execute_query($pdo, $installed_contrib_modules_query)[0]['count'],
	"Custom Modules in Codebase" => $custom_modules_count,
	"Installed Custom Modules" => execute_query($pdo, $installed_custom_modules_query)[0]['count'],
];

// File information grouped by MIME type.
$file_mime_query = "SELECT filemime, COUNT(*) as file_count FROM file_managed GROUP BY filemime ORDER BY file_count DESC;";
$results['file_types'] = execute_query($pdo, $file_mime_query);

// Close the database connection.
$pdo = NULL;

/**
 * Function to format output.
 */
function format_output($results) {
	$output = "";
	foreach ($results as $section => $data) {
		$output .= "\n" . strtoupper(str_replace('_', ' ', $section)) . ":\n" . str_repeat('-', 40) . "\n";
		
		if (isset($data['error'])) {
			$output .= $data['error'] . "\n";
			continue;
		}
		
		if ($section === 'general_info' || $section === 'modules') {
			foreach ($data as $key => $value) {
				$output .= sprintf("%-30s: %s\n", $key, $value);
			}
		}
		else {
			if (!empty($data)) {
				$headers = array_keys($data[0]);
				$column_widths = array_map(function ($header) use ($data) {
					return max(
						strlen($header),
						max(array_map(function ($row) use ($header) {
							return strlen($row[$header] ?? '');
						}, $data))
					);
				}, $headers);
				
				// Print headers.
				foreach ($headers as $index => $header) {
					$output .= sprintf("%-{$column_widths[$index]}s ", $header);
				}
				$output .= "\n" . str_repeat('-', array_sum($column_widths) + count($column_widths)) . "\n";
				
				// Print rows.
				foreach ($data as $row) {
					foreach ($headers as $index => $header) {
						$output .= sprintf("%-{$column_widths[$index]}s ", $row[$header] ?? '');
					}
					$output .= "\n";
				}
			}
		}
	}
	return $output;
}

// Generate output.
$result = format_output($results);

if (php_sapi_name() === 'cli') {
	echo $result;
}
else {
	return $result;
}


