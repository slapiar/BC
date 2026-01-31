<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_Access {
  private static function get_active_stores_map() {
    global $wpdb;
    $t = self::table('stores');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $got = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
    if ($got !== $t) return [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results("SELECT id, name FROM $t WHERE is_active=1 ORDER BY name ASC", ARRAY_A);
    $out = [];
    if (is_array($rows)) {
      foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $name = (string)($r['name'] ?? '');
        if ($id > 0 && $name !== '') $out[$id] = $name;
      }
    }
    return $out;
  }
  public static function get_active_stores() {
    return self::get_active_stores_map();
  }
  private static function user_has_store_access($user_id, $store_id) {
    $user_id = (int)$user_id;
    $store_id = (int)$store_id;
    if ($user_id <= 0 || $store_id <= 0) return false;
    if (user_can($user_id, 'manage_options')) return true;

    global $wpdb;
    $t = self::table('store_users');
    // If mapping table is empty, allow (bootstrap phase).
    $cnt = (int) $wpdb->get_var("SELECT COUNT(1) FROM $t");
    if ($cnt === 0) return true;

    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $t WHERE store_id=%d AND user_id=%d AND is_active=1 LIMIT 1", $store_id, $user_id));
    return !empty($row);
  }

  private static function current_user_has_store_access($store_id) {
    $uid = get_current_user_id();
    return self::user_has_store_access($uid, (int)$store_id);
  }
  private static function get_accessible_stores_map_for_user($user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];
    // Admins see all active stores.
    if (user_can($user_id, 'manage_options')) {
      return self::get_active_stores_map();
    }

    global $wpdb;
    $tMap = self::table('store_users');
    // If mapping table is empty, allow all (bootstrap phase).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $cnt = (int) $wpdb->get_var("SELECT COUNT(1) FROM $tMap");
    if ($cnt === 0) return self::get_active_stores_map();

    $tStores = self::table('stores');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT s.id, s.name
           FROM $tStores s
           INNER JOIN $tMap m ON m.store_id = s.id
          WHERE s.is_active=1 AND m.user_id=%d AND m.is_active=1
          ORDER BY s.name ASC",
        $user_id
      ),
      ARRAY_A
    );
    $out = [];
    if (is_array($rows)) {
      foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $name = (string)($r['name'] ?? '');
        if ($id > 0 && $name !== '') $out[$id] = $name;
      }
    }
    return $out;
  }
  private static function get_store_name($store_id) {
    $store_id = (int)$store_id;
    if ($store_id <= 0) return '';
    global $wpdb;
    $t = self::table('stores');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $t WHERE id=%d LIMIT 1", $store_id));
    return is_string($name) ? $name : '';
  }
  private static function get_store_user_emails($store_id, $roles = ['manager']) {
    $store_id = (int)$store_id;
    $roles = is_array($roles) ? $roles : ['manager'];
    $roles = array_values(array_filter(array_map('strval', $roles)));
    if ($store_id <= 0 || empty($roles)) return [];

    global $wpdb;
    $tMap = self::table('store_users');
    $users = $wpdb->users;

    $placeholders = implode(',', array_fill(0, count($roles), '%s'));
    $sql = "SELECT DISTINCT u.user_email
              FROM $tMap m
              INNER JOIN $users u ON u.ID = m.user_id
             WHERE m.store_id=%d AND m.is_active=1 AND m.role_in_store IN ($placeholders)
               AND u.user_email <> ''";

    $params = array_merge([$store_id], $roles);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
    $out = [];
    if (is_array($rows)) {
      foreach ($rows as $em) {
        $em = sanitize_email((string)$em);
        if ($em) $out[] = $em;
      }
    }
    return array_values(array_unique($out));
  }

  private static function store_exists_and_active($store_id) {
    global $wpdb;
    $t = self::table('stores');
    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $t WHERE id=%d AND is_active=1 LIMIT 1", (int)$store_id));
    return !empty($row);
  }

}
