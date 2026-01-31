<?php
// Integration-like test for Leads soft-delete/restore/hard-delete

// Minimal WP stubs
$options = [];
function get_option($k, $d = null) { global $options; return $options[$k] ?? $d; }
function update_option($k, $v, $autoload = true) { global $options; $options[$k] = $v; return true; }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/wp_stub/');
@mkdir(ABSPATH . 'wp-admin/includes/', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/upgrade.php', "<?php // stub upgrade file ?>");

// WordPress helper stubs used by trait
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function sanitize_key($s) { return (string) $s; }
function sanitize_text_field($s) { return (string) $s; }
function sanitize_email($s) { return (string) $s; }
function wp_kses_post($s) { return (string) $s; }
function current_time($type = 'mysql') { return gmdate('Y-m-d H:i:s'); }

// Fake $wpdb with simple in-memory storage
class FakeWPDB {
  public $prefix = 'wp_';
  public $tables = [];
  public $insert_id = 0;
  public function get_charset_collate() { return 'DEFAULT'; }
  public function insert($table, $data) {
    $this->insert_id++;
    $id = $this->insert_id;
    $row = $data;
    $row['id'] = $id;
    if (empty($row['created_at'])) $row['created_at'] = gmdate('Y-m-d H:i:s');
    if (empty($row['updated_at'])) $row['updated_at'] = $row['created_at'];
    if (!isset($row['status'])) $row['status'] = 'new';
    $this->tables[$table][$id] = $row;
    $this->insert_id = $id;
    return 1;
  }
  public function update($table, $data, $where) {
    if (isset($where['id'])) {
      $id = $where['id'];
      if (!isset($this->tables[$table][$id])) return false;
      foreach ($data as $k => $v) { $this->tables[$table][$id][$k] = $v; }
      $this->tables[$table][$id]['updated_at'] = gmdate('Y-m-d H:i:s');
      return 1;
    }
    return false;
  }
  public function delete($table, $where) {
    if (isset($where['id'])) {
      $id = $where['id'];
      if (isset($this->tables[$table][$id])) { unset($this->tables[$table][$id]); return 1; }
    }
    return false;
  }
  public function get_row($query, $output = ARRAY_A) {
    if (preg_match('/FROM\s+([a-zA-Z0-9_]+).*WHERE\s+id\s*=\s*(\d+)/i', $query, $m)) {
      $table = $m[1]; $id = (int)$m[2];
      return $this->tables[$table][$id] ?? null;
    }
    return null;
  }
  public function prepare($query /*, ...$args */) {
    $args = array_slice(func_get_args(), 1);
    // Support passing a single array of args: $wpdb->prepare($sql, $argsArray)
    if (count($args) === 1 && is_array($args[0])) {
      $args = $args[0];
    }
    if (empty($args)) return $query;
    $result = $query;
    foreach ($args as $val) {
      $val_escaped = is_int($val) ? $val : "'" . str_replace("'", "\\'", $val) . "'";
      $result = preg_replace('/%[ds]/', $val_escaped, $result, 1);
    }
    return $result;
  }
  public function get_results($sql, $output = ARRAY_A) {
    if (!preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $sql, $m)) return [];
    $table = $m[1];
    $rows = $this->tables[$table] ?? [];
    $where_sql = '';
    if (preg_match('/WHERE\s+(.*?)(ORDER BY|LIMIT|$)/is', $sql, $wm)) $where_sql = trim($wm[1]);
    $filtered = [];
    foreach ($rows as $row) {
      $ok = true;
      if ($where_sql !== '') {
        if (preg_match("/status\s*=\s*'([^']+)'/i", $where_sql, $sm)) { if ($row['status'] !== $sm[1]) $ok = false; }
        if (preg_match("/status\s*<>\s*'([^']+)'/i", $where_sql, $sm)) { if ($row['status'] === $sm[1]) $ok = false; }
        if (preg_match('/store_id\s*=\s*(\d+)/i', $where_sql, $sm)) { if ((int)$row['store_id'] !== (int)$sm[1]) $ok = false; }
        if (preg_match_all("/(name|email|phone|interest)\\s+LIKE\\s+'([^']+)'/i", $where_sql, $matches, PREG_SET_ORDER)) {
          $match_found = false;
          foreach ($matches as $m2) {
            $pat = trim($m2[2], "%");
            if ($pat !== '' && stripos($row[$m2[1]] ?? '', $pat) !== false) { $match_found = true; break; }
          }
          if (!$match_found) $ok = false;
        }
      }
      if ($ok) $filtered[] = $row;
    }
    usort($filtered, function($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $lm)) {
      $limit = (int)$lm[1]; $offset = (int)$lm[2];
      $filtered = array_slice($filtered, $offset, $limit);
    }
    return array_values($filtered);
  }
  public function esc_like($s) { return $s; }
}

global $wpdb;
$wpdb = new FakeWPDB();

// Include traits
require_once __DIR__ . '/../../Inventura/bc-inventura/modules/core/trait-db.php';
require_once __DIR__ . '/../../Inventura/bc-inventura/modules/services/trait-leads.php';

class TestLeads { use BC_Inv_Trait_DB, BC_Inv_Trait_Leads; const DB_VERSION = '0.2.8'; }

// Test flow
$ok = true;
// 1) create
$id = TestLeads::create_lead(['store_id' => 1, 'name' => 'Alice', 'email' => 'a@example.com']);
if (!is_int($id) || $id <= 0) { echo "CREATE_FAIL\n"; exit(2); }
// 2) list (exclude deleted)
$all = TestLeads::list_leads(['store_id' => 1]); if (count($all) !== 1) { echo "LIST_EXCLUDE_FAIL\n"; exit(3); }
// 3) soft delete
$d = TestLeads::delete_lead($id); if (!$d) { echo "DELETE_FAIL\n"; exit(4); }
// After delete, default list excludes deleted
$after = TestLeads::list_leads(['store_id' => 1]); if (count($after) !== 0) { echo "LIST_AFTER_DELETE_FAIL\n"; exit(5); }
// include mode should return it
$inc = TestLeads::list_leads(['store_id' => 1, 'deleted' => 'include']); if (count($inc) !== 1) { echo "LIST_INCLUDE_FAIL\n"; exit(6); }
// only mode should return it
$only = TestLeads::list_leads(['store_id' => 1, 'deleted' => 'only']); if (count($only) !== 1) { echo "LIST_ONLY_FAIL\n"; exit(7); }
// get_lead should show status deleted and deleted_at set
$lead = TestLeads::get_lead($id); if (empty($lead) || $lead['status'] !== 'deleted' || empty($lead['deleted_at'])) { echo "GET_AFTER_DELETE_FAIL\n"; exit(8); }
// restore
$r = TestLeads::restore_lead($id); if (!$r) { echo "RESTORE_FAIL\n"; exit(9); }
$restored = TestLeads::get_lead($id); if (empty($restored) || $restored['status'] === 'deleted' || !is_null($restored['deleted_at'])) { echo "GET_AFTER_RESTORE_FAIL\n"; exit(10); }
// hard delete
$h = TestLeads::hard_delete_lead($id); if (!$h) { echo "HARD_DELETE_FAIL\n"; exit(11); }
$gone = TestLeads::get_lead($id); if ($gone !== null) { echo "GONE_FAIL\n"; exit(12); }

echo "LEADS_SOFT_DELETE_OK\n"; exit(0);
