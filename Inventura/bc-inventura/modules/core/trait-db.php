<?php
if (!defined('ABSPATH')) exit;

/**
 * Core / DB: install + migrations (dbDelta), versioning.
 * Keep this trait free of Admin UI / REST / AJAX concerns.
 */
trait BC_Inv_Trait_DB {

  /**
   * Return full table name by logical key.
   *
   * Usage: self::table('reservations')
   */
  protected static function table($key) {
    global $wpdb;

    $map = [
      'stores'             => 'bc_inv_stores',
      'store_users'        => 'bc_inv_store_users',
      'audit'              => 'bc_inv_audit',
      'customers'          => 'bc_inv_customers',
      'reservations'       => 'bc_inv_reservations',
      'reservation_items'  => 'bc_inv_reservation_items',
      'email_log'          => 'bc_inv_email_log',
    ];

    if (!isset($map[$key])) {
      throw new InvalidArgumentException('Unknown Inventura table key: ' . $key);
    }

    return $wpdb->prefix . $map[$key];
  }

  public static function activate() {
    // Some hosts keep PHP OPcache aggressively; clear it on activation to ensure
    // new code is actually loaded (prevents "phantom" fatals after updates).
    if (function_exists('opcache_reset')) {
      @opcache_reset();
    }
    // Ensure DB tables exist right after activation.
    self::maybe_upgrade_db(true);
  }

  public static function maybe_upgrade_db($force = false) {
    if (!is_admin() && !$force) return;

    $installed = get_option('bc_inv_db_version', '0.0.0');
    if (!$force && version_compare($installed, self::DB_VERSION, '>=')) {
      return;
    }

    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $tStores = self::table('stores');
    $tStoreUsers = self::table('store_users');
    $tAudit = self::table('audit');
    $tCustomers = self::table('customers');
    $tRes = self::table('reservations');
    $tResItems = self::table('reservation_items');
    $tEmailLog = self::table('email_log');

    // Stores
    dbDelta("CREATE TABLE $tStores (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(190) NOT NULL,
      location VARCHAR(190) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      note TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY is_active (is_active),
      KEY name (name)
    ) $charset;");

    // Store ↔ users mapping
    dbDelta("CREATE TABLE $tStoreUsers (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      store_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      role_in_store VARCHAR(40) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY store_user (store_id, user_id),
      KEY user_id (user_id),
      KEY store_id (store_id)
    ) $charset;");

    // Audit log (generic)
    dbDelta("CREATE TABLE $tAudit (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      store_id BIGINT UNSIGNED NULL,
      sheet_post_id BIGINT UNSIGNED NULL,
      actor_user_id BIGINT UNSIGNED NULL,
      action VARCHAR(40) NOT NULL,
      payload LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY store_id (store_id),
      KEY sheet_post_id (sheet_post_id),
      KEY actor_user_id (actor_user_id),
      KEY action (action),
      KEY created_at (created_at)
    ) $charset;");

    // Customers (optional mapping to WP user)
    dbDelta("CREATE TABLE $tCustomers (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      wp_user_id BIGINT UNSIGNED NULL,
      name VARCHAR(190) NULL,
      phone VARCHAR(50) NULL,
      email VARCHAR(190) NULL,
      note TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY wp_user_id (wp_user_id),
      KEY email (email),
      KEY phone (phone)
    ) $charset;");

    // Reservations
    dbDelta("CREATE TABLE $tRes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      store_id BIGINT UNSIGNED NOT NULL,
      customer_id BIGINT UNSIGNED NULL,
      customer_wp_user_id BIGINT UNSIGNED NULL,
      created_by_user_id BIGINT UNSIGNED NULL,
      staff_user_id BIGINT UNSIGNED NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'submitted',
      source VARCHAR(20) NOT NULL DEFAULT 'pwa',
      pickup_at DATETIME NULL,
      confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
      system_flags LONGTEXT NULL,
      suggested_at DATETIME NULL,
      total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      currency CHAR(3) NOT NULL DEFAULT 'EUR',
      customer_note TEXT NULL,
      internal_note TEXT NULL,
      confirmed_at DATETIME NULL,
      ready_at DATETIME NULL,
      picked_up_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY store_id (store_id),
      KEY status (status),
      KEY pickup_at (pickup_at),
      KEY confidence (confidence),
      KEY customer_id (customer_id),
      KEY customer_wp_user_id (customer_wp_user_id),
      KEY created_by_user_id (created_by_user_id),
      KEY staff_user_id (staff_user_id)
    ) $charset;");

    // Reservation items
    dbDelta("CREATE TABLE $tResItems (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      reservation_id BIGINT UNSIGNED NOT NULL,
      wc_product_id BIGINT UNSIGNED NULL,
      plu VARCHAR(40) NULL,
      name VARCHAR(190) NOT NULL,
      qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
      unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      note VARCHAR(190) NULL,
      PRIMARY KEY (id),
      KEY reservation_id (reservation_id),
      KEY wc_product_id (wc_product_id),
      KEY plu (plu)
    ) $charset;");

    // Email log (for troubleshooting delivery / SMTP)
    dbDelta("CREATE TABLE $tEmailLog (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      store_id BIGINT UNSIGNED NULL,
      reservation_id BIGINT UNSIGNED NULL,
      mail_to VARCHAR(190) NOT NULL,
      subject VARCHAR(255) NOT NULL,
      mail_type VARCHAR(30) NOT NULL,
      ok TINYINT(1) NOT NULL DEFAULT 0,
      error_text TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY store_id (store_id),
      KEY reservation_id (reservation_id),
      KEY mail_type (mail_type),
      KEY ok (ok),
      KEY created_at (created_at)
    ) $charset;");

    update_option('bc_inv_db_version', self::DB_VERSION, false);
  }
}
