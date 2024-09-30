<?php

global $databases;

if (!isset($config)) {
	$config = [];
	
	if (isset($databases['default']['default']) && defined('DRUPAL_CORE_COMPATIBILITY')) {
		$db_info = $databases['default']['default'];
		
		$config = [
			'drupal_major_version' => '7',
			'docroot' => DRUPAL_ROOT,
			'db_name' => $db_info['database'],
			'db_host' => $db_info['host'],
			'db_user' => $db_info['username'],
			'db_pass' => $db_info['password'],
			'db_port' => $db_info['port'] ?? '3306',
		];
	} else {
		// Default configuration if Drupal environment is not detected
		$config = [
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
	$pdo = new PDO("mysql:host=" . $config["db_host"] . ";dbname=" . $config["db_name"] . ";port=" . $config["db_port"], $config["db_user"], $config["db_pass"]);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
	die("Database connection failed: " . $e->getMessage());
}

/**
 * Execute a MySQL query and return the results.
 *
 * @param PDO $pdo The PDO connection object.
 * @param string $query The SQL query to execute.
 * @return array The query results or an error message.
 */
function execute_query($pdo, $query) {
	try {
		$stmt = $pdo->query($query);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
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
} else {
	$results['user_roles'] = ["error" => "The table 'users_roles' does not exist in the database."];
}

// Fields for each node type.
$fields_query = "SELECT fc.field_name, fc.type, fci.bundle FROM field_config fc LEFT JOIN field_config_instance fci ON fc.field_name = fci.field_name WHERE fci.entity_type = 'node' ORDER BY fci.bundle, fc.field_name;";
$results['node_fields'] = execute_query($pdo, $fields_query);

// Module information.
$contrib_modules_count = count(glob($config['docroot'] . "/sites/all/modules/**/*.info"));
$custom_modules = find_files_by_type($config['docroot'] . "/sites/all/modules/custom/", "info");
$custom_modules_count = count($custom_modules);

$installed_contrib_modules_query = "SELECT COUNT(*) as count FROM system WHERE type = 'module' AND status = 1 AND filename LIKE 'sites/all/modules/%';";
$installed_custom_modules_query = "SELECT COUNT(*) as count FROM system WHERE type = 'module' AND status = 1 AND filename LIKE 'sites/all/modules/custom/%';";

$results['modules'] = [
	"Contrib Modules in Codebase" => $contrib_modules_count,
	"Installed Contrib Modules" => execute_query($pdo, $installed_contrib_modules_query)[0]['count'],
	"Custom Modules in Codebase" => $custom_modules_count,
	"Installed Custom Modules" => execute_query($pdo, $installed_custom_modules_query)[0]['count'],
];

// After the existing module information code
$contrib_modules_info = get_contrib_modules_info($pdo);
$results['modules']['contrib_modules'] = $contrib_modules_info;

foreach ($custom_modules as $module) {
	$module_path = dirname($module);
	$module_name = pathinfo($module, PATHINFO_FILENAME);
	$module_module_path = $module_path . "/" . $module_name . ".module";
	$info_file_path = $module;
	$results['modules']['custom'][$module_name] = [];
	
	$php_files = array_merge(
		find_files_by_type($module_path, "php", $module_path . "/modules"),
		find_files_by_type($module_path, "inc", $module_path . "/modules")
	);
	$php_line_count = count_lines($php_files);
	
	$file_extensions = get_file_extensions($module_path, $module_path . "/modules");
	
	$results['modules']['custom'][$module_name] = [
		"Number of PHP, inc files" => count($php_files),
		"Total PHP lines" => $php_line_count,
		"File extensions" => implode(", ", $file_extensions),
	];
	
	$custom_entity_info = check_entity_info($module_module_path);
	if ($custom_entity_info['hook_found']) {
		$results['modules']['custom'][$module_name]["Number of custom Entities"] = $custom_entity_info['entity_count'];
	}
	
	foreach ($php_files as $php_file) {
		$db_query_info = find_db_query($php_file);
		if ($db_query_info['query_count'] > 0) {
			$results['modules']['custom'][$module_name]["DB Queries"] = $db_query_info['query_count'];
			$results['modules']['custom'][$module_name]["Total Lines with DB Queries"] = $db_query_info['total_lines'];
		}
	}
	
	$functions = get_function_names_and_size($module_module_path);
	if (!empty($functions)) {
		$results['modules']['custom'][$module_name]["functions"] = $functions;
	}
}

// File information grouped by MIME type.
$file_mime_query = "SELECT filemime, COUNT(*) as file_count FROM file_managed GROUP BY filemime ORDER BY file_count DESC;";
$results['file_types'] = execute_query($pdo, $file_mime_query);

// Theme information
$themes_custom = find_files_by_type($config['docroot'] . "/sites/all/themes/", "info");
$themes_core = find_files_by_type($config['docroot'] . "/themes/", "info");

$results['themes'] = [
	"Themes in Codebase" => count($themes_custom),
	"Contrib themes in Codebase" => count($themes_core),
];

foreach ($themes_custom as $theme) {
	$theme_path = dirname($theme);
	$theme_name = pathinfo($theme, PATHINFO_FILENAME);
	$info_file_path = $theme;
	$results['themes'][$theme_name] = [];
	
	// Read base theme from .info file
	$base_theme = 'None';
	if (file_exists($info_file_path)) {
		$info_content = file_get_contents($info_file_path);
		if (preg_match('/base theme\s*=\s*(.+)/', $info_content, $matches)) {
			$base_theme = trim($matches[1]);
		}
	}
	
	$template_js = find_files_by_type($theme_path, "js");
	$template_css = find_files_by_type($theme_path, "css");
	
	$templates_dir = $theme_path . "/templates";
	$templates_files = file_exists($templates_dir) ? find_files_by_type($templates_dir, "php") : [];
	
	$template_count = count($templates_files);
	
	$js_line_count = count_lines($template_js);
	$css_line_count = count_lines($template_css);
	
	$results['themes'][$theme_name] = [
		"Base Theme" => $base_theme,
		"Number of JS files" => count($template_js),
		"Total JS lines" => $js_line_count,
		"Number of CSS files" => count($template_css),
		"Total CSS lines" => $css_line_count,
		"Number of Templates" => $template_count
	];
	
	if ($template_count) {
		$total_line_count = count_lines($templates_files);
		$php_line_count = 0;
		
		foreach ($templates_files as $template_file) {
			$file_contents = file_get_contents($template_file);
			$php_line_count += preg_match_all('/<\?php.*?\?>/s', $file_contents, $matches);
		}
		
		$php_percentage = ($total_line_count > 0) ? round(($php_line_count / $total_line_count) * 100, 2) : 0;
		$results['themes'][$theme_name]["Total TPL lines"] = $total_line_count;
		$results['themes'][$theme_name]["PHP Percentage in Templates"] = $php_percentage . "%";
	}
}

if (empty($results['themes'])) {
	$results['themes']["Error"] = "No themes found";
}

// Close the database connection.
$pdo = null;

/**
 * Format the output results into a readable string.
 *
 * @param array $results The results to format.
 * @return string The formatted output.
 */
function format_output($results) {
	$output = "";
	foreach ($results as $section => $data) {
		$output .= "\n" . strtoupper(str_replace('_', ' ', $section)) . ":\n" . str_repeat('-', 40) . "\n";
		
		if (isset($data['error'])) {
			$output .= $data['error'] . "\n";
			continue;
		}
		
		if ($section === 'general_info') {
			foreach ($data as $key => $value) {
				$output .= sprintf("%-30s: %s\n", $key, $value);
			}
		} elseif ($section === 'modules') {
			$output .= format_modules_output($data);
		} elseif ($section === 'themes') {
			$output .= format_themes_output($data);
		} else {
			$output .= format_table_output($data);
		}
	}
	return $output;
}

/**
 * Format the modules output.
 *
 * @param array $data The modules data to format.
 * @return string The formatted modules output.
 */
function format_modules_output($data) {
	$output = "";
	$overall_counts = [
		"Contrib Modules in Codebase",
		"Installed Contrib Modules",
		"Custom Modules in Codebase",
		"Installed Custom Modules"
	];
	
	foreach ($overall_counts as $count_key) {
		if (isset($data[$count_key])) {
			$output .= sprintf("%-30s: %s\n", $count_key, $data[$count_key]);
		}
	}
	
	$output .= format_contrib_modules_summary($data['contrib_modules']);
	$output .= format_custom_modules_summary($data['custom']);
	$output .= format_custom_modules_details($data['custom'], $overall_counts);
	
	return $output;
}

/**
 * Format the contrib modules summary.
 *
 * @param array $contrib_modules The contrib modules data.
 * @return string The formatted contrib modules summary.
 */
function format_contrib_modules_summary($contrib_modules) {
	$output = "\nInstalled Contrib Modules Summary:\n" . str_repeat('-', 40) . "\n";
	$output .= sprintf("%-30s %-15s %s\n", "Module Name", "Version", "Project");
	$output .= str_repeat('-', 80) . "\n";
	foreach ($contrib_modules as $module) {
		$output .= sprintf("%-30s %-15s %s\n", $module['name'], $module['version'], $module['project']);
	}
	$output .= "\n";
	return $output;
}

/**
 * Format the custom modules summary.
 *
 * @param array $data The modules data.
 * @return string The formatted custom modules summary.
 */
function format_custom_modules_summary($data) {
	$total_php_inc_files = 0;
	$total_php_inc_lines = 0;
	$total_db_queries = 0;
	$total_db_query_lines = 0;
	$total_entities = 0;
	
	foreach ($data as $module_name => $module_data) {
		if (!in_array($module_name, ["Contrib Modules in Codebase", "Installed Contrib Modules", "Custom Modules in Codebase", "Installed Custom Modules"])) {
			$total_php_inc_files += $module_data['Number of PHP, inc files'] ?? 0;
			$total_php_inc_lines += $module_data['Total PHP lines'] ?? 0;
			$total_db_queries += $module_data['DB Queries'] ?? 0;
			$total_db_query_lines += $module_data['Total Lines with DB Queries'] ?? 0;
			$total_entities += $module_data['Number of custom Entities'] ?? 0;
		}
	}
	
	$stripped_functions = count_stripped_functions($data);
	arsort($stripped_functions); // Sort by count, descending
	
	$output = "\nCustom Modules Summary:\n" . str_repeat('-', 40) . "\n";
	$output .= sprintf("%-30s: %d\n", "Total PHP, inc files", $total_php_inc_files);
	$output .= sprintf("%-30s: %d\n", "Total PHP, inc lines", $total_php_inc_lines);
	$output .= sprintf("%-30s: %d\n", "Total DB queries", $total_db_queries);
	$output .= sprintf("%-30s: %d\n", "Total lines with DB queries", $total_db_query_lines);
	$output .= sprintf("%-30s: %d\n", "Total custom Entities", $total_entities);
	
	$output .= "\nMost Used Functions in .module (across all custom modules):\n" . str_repeat('-', 40) . "\n";
	$output .= sprintf("%-30s %s\n", "Function Name", "Count");
	$output .= str_repeat('-', 40) . "\n";
	foreach ($stripped_functions as $func_name => $count) {
		$output .= sprintf("%-30s %d\n", $func_name, $count);
	}
	
	return $output;
}

/**
 * Format the custom modules details.
 *
 * @param array $data The modules data.
 * @param array $overall_counts The overall counts to exclude.
 * @return string The formatted custom modules details.
 */
function format_custom_modules_details($data, $overall_counts) {
	$output = "\nCustom Module Details:\n" . str_repeat('-', 40) . "\n";
	foreach ($data as $module_name => $module_data) {
		if (!in_array($module_name, $overall_counts)) {
			$output .= "Module: $module_name\n";
			foreach ($module_data as $key => $value) {
				if ($key === 'functions') {
					$output .= format_functions_table($value);
				} else {
					$output .= sprintf("  %-28s: %s\n", $key, $value);
				}
			}
			$output .= "\n";
		}
	}
	return $output;
}

/**
 * Format the functions table.
 *
 * @param array $functions The functions data.
 * @return string The formatted functions table.
 */
function format_functions_table($functions) {
	$output = "  Hook/ Functions in .module:\n";
	$output .= "  " . str_repeat('-', 38) . "\n";
	$output .= sprintf("  %-30s %s\n", "Function Name", "Line Count");
	$output .= "  " . str_repeat('-', 38) . "\n";
	foreach ($functions as $func_name => $line_count) {
		$output .= sprintf("  %-30s %d\n", $func_name, $line_count);
	}
	$output .= "  " . str_repeat('-', 38) . "\n";
	return $output;
}

/**
 * Format the themes output.
 *
 * @param array $data The themes data to format.
 * @return string The formatted themes output.
 */
function format_themes_output($data) {
	$output = sprintf("%-30s: %s\n", "Themes in Codebase", $data["Themes in Codebase"]);
	$output .= sprintf("%-30s: %s\n", "Contrib themes in Codebase", $data["Contrib themes in Codebase"]);
	$output .= "\nIndividual Theme Details:\n" . str_repeat('-', 40) . "\n";
	
	foreach ($data as $theme_name => $theme_data) {
		if ($theme_name !== "Themes in Codebase" && $theme_name !== "Contrib themes in Codebase") {
			$output .= "Theme: $theme_name\n";
			foreach ($theme_data as $key => $value) {
				$output .= sprintf("  %-28s: %s\n", $key, $value);
			}
			$output .= "\n";
		}
	}
	
	return $output;
}

/**
 * Format data as a table.
 *
 * @param array $data The data to format as a table.
 * @return string The formatted table output.
 */
function format_table_output($data) {
	$output = "";
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
		
		// Print headers
		foreach ($headers as $index => $header) {
			$output .= sprintf("%-{$column_widths[$index]}s ", $header);
		}
		$output .= "\n" . str_repeat('-', array_sum($column_widths) + count($column_widths)) . "\n";
		
		// Print rows
		foreach ($data as $row) {
			foreach ($headers as $index => $header) {
				$output .= sprintf("%-{$column_widths[$index]}s ", $row[$header] ?? '');
			}
			$output .= "\n";
		}
	}
	return $output;
}

/**
 * Find files of a specific type in a directory.
 *
 * @param string $directory The directory to search in.
 * @param string $type The file extension to search for.
 * @param string|bool $exclude A path to exclude from the search.
 * @return array An array of file paths.
 */
function find_files_by_type($directory, $type, $exclude = false) {
	$files = [];
	
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory)
	);
	
	foreach ($iterator as $file) {
		if ($exclude && strpos($file->getPathname(), $exclude) !== false) {
			continue;
		}
		if ($file->isFile() && pathinfo($file, PATHINFO_EXTENSION) === $type) {
			$files[] = $file->getRealPath();
		}
	}
	
	return $files;
}

/**
 * Get all file extensions in a directory.
 *
 * @param string $directory The directory to search in.
 * @param string|bool $exclude A path to exclude from the search.
 * @return array An array of unique file extensions.
 */
function get_file_extensions($directory, $exclude = false) {
	$extensions = [];
	
	if (!is_dir($directory)) {
		return $extensions;
	}
	
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory)
	);
	
	foreach ($iterator as $file) {
		if ($file->isFile()) {
			if ($exclude && strpos($file->getPathname(), $exclude) !== false) {
				continue;
			}
			$extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
			if ($extension) {
				$extensions[] = strtolower($extension);
			}
		}
	}
	
	return array_unique($extensions);
}

/**
 * Get function names and their sizes from a file.
 *
 * @param string $file The file to analyze.
 * @return array An array of function names and their line counts.
 */
function get_function_names_and_size($file) {
	$functions = [];
	
	if (!file_exists($file)) {
		return $functions;
	}
	
	$lines = file($file);
	$function_name = null;
	$is_function = false;
	$line_count = 0;
	
	foreach ($lines as $line_number => $line) {
		if (preg_match('/function\s+(\w+)\s*\(/', $line, $matches)) {
			if ($function_name) {
				$functions[$function_name] = $line_count;
			}
			
			$function_name = $matches[1];
			$line_count = 1;
			$is_function = true;
		} elseif ($is_function) {
			$line_count++;
			if (strpos($line, '}') !== false) {
				$functions[$function_name] = $line_count;
				$function_name = null;
				$is_function = false;
				$line_count = 0;
			}
		}
	}
	
	if ($function_name) {
		$functions[$function_name] = $line_count;
	}
	
	return $functions;
}

/**
 * Find database queries in a file.
 *
 * @param string $file The file to analyze.
 * @return array An array with the query count and total lines of queries.
 */
function find_db_query($file) {
	$query_count = 0;
	$total_lines = 0;
	
	if (!file_exists($file)) {
		return ['query_count' => $query_count, 'total_lines' => $total_lines];
	}
	
	$lines = file($file);
	$query_lines = [];
	$in_query_block = false;
	
	foreach ($lines as $line_number => $line) {
		if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|MERGE|DROP|CREATE|ALTER|TRUNCATE)\b/i', $line)) {
			$query_count++;
			$query_lines[] = $line_number + 1;
			$total_lines++;
			
			$in_query_block = true;
		} elseif ($in_query_block) {
			$total_lines++;
			
			if (strpos($line, ';') !== false) {
				$in_query_block = false;
			}
		}
	}
	
	return ['query_count' => $query_count, 'total_lines' => $total_lines];
}

/**
 * Check for entity_info hook and count custom entities.
 *
 * @param string $file The file to analyze.
 * @return array An array indicating if the hook was found and the entity count.
 */
function check_entity_info($file) {
	$entity_count = 0;
	$hook_found = false;
	
	if (!file_exists($file)) {
		return ['hook_found' => $hook_found, 'entity_count' => $entity_count];
	}
	
	$lines = file($file);
	$in_entity_block = false;
	
	foreach ($lines as $line) {
		if (preg_match('/function\s+(\w+_)?entity_info\s*\(/', $line)) {
			$hook_found = true;
			$in_entity_block = true;
		}
		
		if ($in_entity_block) {
			if (preg_match('/\bentity class\b/', $line)) {
				$entity_count++;
			}
			
			if (strpos($line, '}') !== false) {
				$in_entity_block = false;
			}
		}
	}
	
	return ['hook_found' => $hook_found, 'entity_count' => $entity_count];
}

/**
 * Get information about contrib modules.
 *
 * @param PDO $pdo The PDO connection object.
 * @return array An array of contrib modules with their names and versions.
 */
function get_contrib_modules_info($pdo) {
	$query = "SELECT name, filename, info FROM system WHERE type = 'module' AND status = 1 AND filename LIKE 'sites/all/modules/%' AND filename NOT LIKE 'sites/all/modules/custom/%'";
	$result = execute_query($pdo, $query);
	$modules = [];
	foreach ($result as $row) {
		$info = unserialize($row['info']);
		$modules[$row['name']] = [
			'name' => $info["name"],
			'version' => $info['version'] ?? 'Unknown',
			'project' => $info["project"] ?? 'Unknown'
		];
	}
	return $modules;
}

/**
 * Count the total number of lines in multiple files.
 *
 * @param array $files An array of file paths.
 * @return int The total number of lines.
 */
function count_lines($files) {
	$total_lines = 0;
	foreach ($files as $file) {
		$total_lines += count(file($file));
	}
	return $total_lines;
}

/**
 * Count occurrences of stripped function names across all modules.
 *
 * @param array $data The modules data.
 * @return array An array of stripped function names and their counts.
 */
function count_stripped_functions($data) {
	$function_counts = [];
	foreach ($data as $module_name => $module_data) {
		if (isset($module_data['functions'])) {
			foreach ($module_data['functions'] as $func_name => $line_count) {
				// Strip module name prefix
				$stripped_name = preg_replace('/^' . preg_quote($module_name, '/') . '_/', '', $func_name);
				if (!isset($function_counts[$stripped_name])) {
					$function_counts[$stripped_name] = 0;
				}
				$function_counts[$stripped_name]++;
			}
		}
	}
	// Filter to keep only functions used more than once
	return array_filter($function_counts, function($count) {
		return $count > 1;
	});
}

// Generate output.
$result = format_output($results);

if (php_sapi_name() === 'cli') {
	echo $result;
} else {
	return $result;
}
