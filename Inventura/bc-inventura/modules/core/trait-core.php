<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_Core {

  public static function init() {
    add_action('init', [__CLASS__, 'register_cpt']);
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    // Admin viewer for audit log
    add_action('admin_menu', [__CLASS__, 'register_audit_menu'], 30);
    // Phase 1: Chat access requests admin menu
    add_action('admin_menu', [__CLASS__, 'register_chat_menu'], 31);
    add_action('rest_api_init', [__CLASS__, 'register_rest']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
    // DB install / upgrade (safe, idempotent)
    add_action('admin_init', [__CLASS__, 'maybe_upgrade_db']);
    // WordPress REST cookie-auth normally requires an X-WP-Nonce even for GET.
    // That creates a "chicken & egg" for our nonce bootstrap endpoint.
    // We selectively bypass the cookie-nonce check for /bc-inventura/v1/nonce.
    add_filter('rest_authentication_errors', [__CLASS__, 'bypass_wp_rest_cookie_nonce_for_nonce_endpoint'], 99);

    // AJAX endpoints (robust when auth cookies are scoped to /wp-admin/).
    add_action('wp_ajax_bc_inventura_nonce', [__CLASS__, 'ajax_get_nonce']);
    add_action('wp_ajax_bc_inventura_post_sheet', [__CLASS__, 'ajax_post_sheet']);
    add_action('wp_ajax_bc_inventura_latest', [__CLASS__, 'ajax_get_latest']);

    // Etapa 3.0: Dodací list (foto) – upload do Media Library + meta.
    add_action('wp_ajax_bc_inventura_upload_dodak', [__CLASS__, 'ajax_upload_dodak']);

    // Etapa 1: bulk upratovanie + ochrana mazania.
    add_filter('bulk_actions-edit-' . self::CPT, [__CLASS__, 'bulk_actions']);
    add_filter('handle_bulk_actions-edit-' . self::CPT, [__CLASS__, 'handle_bulk_actions'], 10, 3);
    add_action('admin_notices', [__CLASS__, 'admin_notices']);
    add_filter('map_meta_cap', [__CLASS__, 'map_meta_cap'], 10, 4);
  }

  // ===== Settings (Etapa A1) =====
  // Stored in wp_options as individual keys for simplicity.
  public static function get_setting_expiry_grace_hours(): int {
    $v = get_option('bc_inv_expiry_grace_hours', 2);
    $v = is_numeric($v) ? (int)$v : 2;
    if ($v < 0) $v = 0;
    if ($v > 72) $v = 72;
    return $v;
  }

  public static function get_setting_min_pickup_minutes(): int {
    $v = get_option('bc_inv_min_pickup_minutes', 30);
    $v = is_numeric($v) ? (int)$v : 30;
    if ($v < 0) $v = 0;
    if ($v > 1440) $v = 1440; // max 24h
    return $v;
  }

  public static function get_setting_capacity_max_per_interval(): int {
    // 0 = disabled
    $v = get_option('bc_inv_capacity_max_per_interval', 0);
    $v = is_numeric($v) ? (int)$v : 0;
    if ($v < 0) $v = 0;
    if ($v > 500) $v = 500;
    return $v;
  }

  public static function get_setting_capacity_interval_minutes(): int {
    // keep simple for now; default 15 minutes
    $v = get_option('bc_inv_capacity_interval_minutes', 15);
    $v = is_numeric($v) ? (int)$v : 15;
    if ($v < 5) $v = 5;
    if ($v > 240) $v = 240;
    return $v;
  }



  public static function get_setting_notify_submitted_roles(): array {
    $raw = (string)get_option('bc_inv_notify_submitted_roles', 'manager');
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    $allowed = ['manager','seller','auditor'];
    $out = [];
    foreach ($parts as $p) {
      if (in_array($p, $allowed, true)) $out[] = $p;
    }
    if (empty($out)) $out = ['manager'];
    return array_values(array_unique($out));
  }

  
  public static function get_setting_reservations_default_limit(): int {
    $v = get_option('bc_inv_reservations_default_limit', 100);
    $v = is_numeric($v) ? (int)$v : 100;
    if ($v < 10) $v = 10;
    if ($v > 500) $v = 500;
    return $v;
  }

public static function is_notify_enabled_for_status(string $status): bool {
    $status = sanitize_text_field($status);
    $key = 'bc_inv_notify_status_' . $status;
    $default_on = in_array($status, ['submitted','confirmed','ready','cancelled','expired'], true) ? '1' : '0';
    $v = get_option($key, $default_on);
    return (string)$v === '1';
  }
  private static function expiry_class_from_any($exp_text) {
    $today = date('Y-m-d');
    $earliest = '';
    if (is_string($exp_text) && $exp_text !== '') {
      if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $exp_text, $m)) {
        $dates = $m[1];
        sort($dates, SORT_STRING);
        $earliest = $dates[0] ?? '';
      }
    }
    if ($earliest === '') return 'exp-ok';

    // Compute day-diff safely (no DST issues in PHP for Y-m-d)
    $dExp = DateTime::createFromFormat('Y-m-d', $earliest);
    $dToday = DateTime::createFromFormat('Y-m-d', $today);
    if (!$dExp || !$dToday) return 'exp-ok';
    $diff = (int) $dToday->diff($dExp)->format('%r%a');

    if ($diff <= -2) return 'exp-minus-2';
    if ($diff === -1) return 'exp-minus-1';
    if ($diff === 0) return 'exp-today';
    if ($diff === 1) return 'exp-1';
    if ($diff === 2) return 'exp-2';
    if ($diff === 3) return 'exp-3';
    return 'exp-ok';
  }

  private static function current_user_can_manage() {
    // Predavač môže byť Autor/Editor, admin má samozrejme tiež.
    return current_user_can('edit_posts') || current_user_can('manage_options');
  }
  private static function is_woocommerce_active() {
    return class_exists('WooCommerce') && function_exists('wc_create_order');
  }

  private static function normalize_date($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    // YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    // DD.MM.YYYY alebo D.M.YYYY
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
      $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
      $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
      return $m[3] . '-' . $mo . '-' . $d;
    }
    return null;
  }

  private static function normalize_store($s) {
    $s = trim((string)$s);
    // collapse whitespace to a single space
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  }

}