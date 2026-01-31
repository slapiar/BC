<?php
// Simple integration-like test: stub environment and capture dbDelta SQL
// to verify that maybe_upgrade_db() includes creation of bc_inv_leads table.

// Minimal stubs
$options = [];
function get_option($k, $d = null) { global $options; return $options[$k] ?? $d; }
function update_option($k, $v, $autoload = true) { global $options; $options[$k] = $v; return true; }

// Ensure ABSPATH and a stubbed upgrade.php exist so trait can require it
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/wp_stub/');
@mkdir(ABSPATH . 'wp-admin/includes/', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php // stub upgrade file ?>");

// Capture dbDelta calls
$captured_sql = [];
function dbDelta($sql) {
  global $captured_sql;
  $captured_sql[] = $sql;
}

// Minimal WP stubs
function is_admin() { return false; }

// Fake $wpdb
class FakeWPDB {
  public $prefix = 'wp_';
  public function get_charset_collate() { return 'DEFAULT'; }
}
global $wpdb;
$wpdb = new FakeWPDB();

// Include the trait directly and exercise maybe_upgrade_db
require_once __DIR__ . '/../../Inventura/bc-inventura/modules/core/trait-db.php';
class TestDB { use BC_Inv_Trait_DB; const DB_VERSION = '0.2.7'; }

// Run upgrade with force
try {
  TestDB::maybe_upgrade_db(true);
} catch (Throwable $e) {
  echo "EXCEPTION: " . $e->getMessage() . "\n";
  exit(2);
}

// Check captured SQL
$found = false;
foreach ($captured_sql as $s) {
  if (stripos($s, 'create table') !== false && stripos($s, 'bc_inv_leads') !== false) {
    $found = true; break;
  }
}

if ($found) {
  echo "LEADS_SQL_OK\n"; exit(0);
}

echo "LEADS_SQL_MISSING\n"; exit(1);
