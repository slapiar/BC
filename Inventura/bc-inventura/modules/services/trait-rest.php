<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_REST {

  // === PWA auth helpers (Phase 1) ===
  protected static function auth_now(): string {
    return current_time('mysql');
  }

  protected static function auth_is_valid_device_id(string $device_id): bool {
    if ($device_id === '') return false;
    return (bool) preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $device_id);
  }

  protected static function auth_generate_approval_code(): string {
    return (string) random_int(100000, 999999);
  }

  protected static function auth_code_expires_at(): string {
    return date('Y-m-d H:i:s', time() + 15 * 60);
  }

  protected static function auth_is_expired(?string $expires_at, ?string $now = null): bool {
    if (!$expires_at) return false;
    $now = $now ?: self::auth_now();
    return strtotime($expires_at) < strtotime($now);
  }

  protected static function auth_get_device_row(string $device_id): ?array {
    global $wpdb;
    $t = self::table('auth_devices');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE device_id=%s LIMIT 1", $device_id), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  protected static function auth_audit(string $action, array $payload = []): void {
    global $wpdb;
    try {
      $t = self::table('audit');
      $wpdb->insert($t, [
        'store_id' => null,
        'sheet_post_id' => null,
        'actor_user_id' => is_user_logged_in() ? (int) get_current_user_id() : null,
        'action' => $action,
        'payload' => wp_json_encode($payload),
        'created_at' => self::auth_now(),
      ]);
    } catch (Throwable $e) {
      // best effort only
    }
  }

  protected static function auth_create_user_from_nickname(string $nickname, string $device_id): int {
    if (!function_exists('wp_create_user')) return 0;

    $base = sanitize_user('pwa_' . $nickname, true);
    if ($base === '') {
      $base = 'pwa_device_' . substr(md5($device_id), 0, 6);
    }

    $login = $base;
    $i = 1;
    while (username_exists($login)) {
      $login = $base . '_' . $i;
      $i++;
    }

    $email = $login . '@example.invalid';
    $password = wp_generate_password(20, true, true);
    $user_id = wp_create_user($login, $password, $email);
    if (is_wp_error($user_id)) return 0;

    wp_update_user([
      'ID' => $user_id,
      'display_name' => $nickname !== '' ? $nickname : $login,
      'nickname' => $nickname !== '' ? $nickname : $login,
    ]);
    if ($nickname !== '') {
      update_user_meta($user_id, 'bc_inv_chat_nick', $nickname);
    }

    return (int) $user_id;
  }

  public static function bypass_wp_rest_cookie_nonce_for_nonce_endpoint($result) {
    // Only apply to our nonce endpoint.
    $rest_route = isset($_REQUEST['rest_route']) ? (string) $_REQUEST['rest_route'] : '';

    // When pretty permalinks are used, rest_route may be empty. Fallback to REQUEST_URI.
    if ($rest_route === '' && isset($_SERVER['REQUEST_URI'])) {
      $uri = (string) $_SERVER['REQUEST_URI'];
      if (strpos($uri, '/wp-json/' . self::REST_NS . '/nonce') !== false) {
        $rest_route = '/' . self::REST_NS . '/nonce';
      }
    }

    if ($rest_route !== '/' . self::REST_NS . '/nonce') return $result;

    // If WP blocked cookie-auth because nonce is missing/invalid, allow the request through.
    // The endpoint itself still requires is_user_logged_in() + capability.
    if (is_wp_error($result)) {
      return null;
    }
    return $result;
  }

  public static function register_rest() {
    // GET nonce (for same-origin PWA using WP cookie auth)
    register_rest_route(self::REST_NS, '/nonce', [
      'methods' => 'GET',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => function() {
        return new WP_REST_Response(['ok' => true, 'nonce' => wp_create_nonce('wp_rest')], 200);
      },
    ]);

    // --- PWA auth (Phase 1) ---

    register_rest_route(self::REST_NS, '/auth/request-access', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'rest_auth_request_access'],
    ]);

    register_rest_route(self::REST_NS, '/auth/refresh', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'rest_auth_refresh'],
    ]);

    register_rest_route(self::REST_NS, '/me', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'rest_auth_me'],
    ]);

    register_rest_route(self::REST_NS, '/admin/access-requests', [
      'methods' => 'GET',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_admin_access_requests'],
      'args' => [
        'status' => ['required' => false],
      ],
    ]);

    register_rest_route(self::REST_NS, '/admin/access-requests/(?P<id>\d+)/approve', [
      'methods' => 'POST',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_admin_access_request_approve'],
    ]);

    register_rest_route(self::REST_NS, '/admin/access-requests/(?P<id>\d+)/deny', [
      'methods' => 'POST',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_admin_access_request_deny'],
    ]);

    register_rest_route(self::REST_NS, '/admin/access-requests/(?P<id>\d+)/wait', [
      'methods' => 'POST',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_admin_access_request_wait'],
    ]);

    register_rest_route(self::REST_NS, '/admin/users/(?P<id>\d+)/disconnect', [
      'methods' => 'POST',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_admin_user_disconnect'],
    ]);

    // POST sheet (from PWA)
    register_rest_route(self::REST_NS, '/sheet', [
      'methods' => 'POST',
      'permission_callback' => function() {
        // Same-origin PWA uses WP cookie auth + X-WP-Nonce.
        if (!is_user_logged_in() || !self::current_user_can_manage()) return false;
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field($_SERVER['HTTP_X_WP_NONCE']) : '';
        if (!$nonce) return false;
        return (bool) wp_verify_nonce($nonce, 'wp_rest');
      },
      'callback' => [__CLASS__, 'rest_post_sheet'],
    ]);

    // GET last sheet for a prevadzka
    register_rest_route(self::REST_NS, '/sheet/latest', [
      'methods' => 'GET',
      'permission_callback' => function() {
        return is_user_logged_in() && self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_get_latest'],
      'args' => [
        'prevadzka' => ['required' => false],
      ],
    ]);

    // --- Rezervácie (0.2.0) ---

    register_rest_route(self::REST_NS, '/reservations', [
      'methods' => 'POST',
      'permission_callback' => function() {
        return is_user_logged_in() && self::verify_rest_nonce();
      },
      'callback' => [__CLASS__, 'rest_create_reservation'],
    ]);

    register_rest_route(self::REST_NS, '/reservations/my', [
      'methods' => 'GET',
      'permission_callback' => function() {
        return is_user_logged_in() && self::verify_rest_nonce();
      },
      'callback' => [__CLASS__, 'rest_get_my_reservations'],
    ]);

    register_rest_route(self::REST_NS, '/reservations/store', [
      'methods' => 'GET',
      'permission_callback' => function($req) {
        if (!is_user_logged_in() || !self::verify_rest_nonce()) return false;
        $store_id = (int) $req->get_param('store_id');
        if ($store_id <= 0) return false;
        return self::current_user_has_store_access($store_id);
      },
      'callback' => [__CLASS__, 'rest_get_store_reservations'],
      'args' => [
        'store_id' => ['required' => true],
        'status' => ['required' => false],
        'date_from' => ['required' => false],
        'date_to' => ['required' => false],
      ],
    ]);

    register_rest_route(self::REST_NS, '/reservations/(?P<id>\\d+)/status', [
      'methods' => 'POST',
      'permission_callback' => function($req) {
        if (!is_user_logged_in() || !self::verify_rest_nonce()) return false;
        $id = (int) $req->get_param('id');
        if ($id <= 0) return false;
        $row = self::db_get_reservation($id);
        if (!$row) return false;
        return self::current_user_has_store_access((int)$row['store_id']);
      },
      'callback' => [__CLASS__, 'rest_set_reservation_status'],
    ]);

    register_rest_route(self::REST_NS, '/reservations/(?P<id>\\d+)/to-order', [
      'methods' => 'POST',
      'permission_callback' => function($req) {
        if (!is_user_logged_in() || !self::verify_rest_nonce()) return false;
        if (!self::is_woocommerce_active()) return false;
        $id = (int) $req->get_param('id');
        if ($id <= 0) return false;
        $row = self::db_get_reservation($id);
        if (!$row) return false;
        return self::current_user_has_store_access((int)$row['store_id']);
      },
      'callback' => [__CLASS__, 'rest_convert_reservation_to_order'],
    ]);
    // Audit viewer
    register_rest_route(self::REST_NS, '/audit', [
      'methods' => 'GET',
      'permission_callback' => function($req) {
        if (!is_user_logged_in() || !self::verify_rest_nonce()) return false;
        // If store_id provided, require store access
        $store_id = (int) $req->get_param('store_id');
        if ($store_id > 0) return self::current_user_has_store_access($store_id);
        return self::current_user_can_manage();
      },
      'callback' => [__CLASS__, 'rest_get_audit'],
      'args' => [
        'store_id' => ['required' => false],
        'action' => ['required' => false],
        'limit' => ['required' => false],
        'offset' => ['required' => false],
        'date_from' => ['required' => false],
        'date_to' => ['required' => false],
      ],
    ]);

    // Phase 1: Chat access request (public endpoint, no auth required)
    register_rest_route(self::REST_NS, '/auth/request-access', [
      'methods' => 'POST',
      'permission_callback' => '__return_true', // Public endpoint
      'callback' => [__CLASS__, 'rest_request_access'],
    ]);

    // Phase 1: Device status check (public endpoint, no auth required)
    register_rest_route(self::REST_NS, '/auth/device-status', [
      'methods' => 'GET',
      'permission_callback' => '__return_true', // Public endpoint
      'callback' => [__CLASS__, 'rest_device_status'],
    ]);
  }

  // --- PWA auth endpoints (Phase 1) ---

  public static function rest_auth_request_access(WP_REST_Request $req) {
    $device_id = trim((string) $req->get_header('X-Device-Id'));
    if (!self::auth_is_valid_device_id($device_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid device_id'], 400);
    }

    $body = $req->get_json_params();
    $nickname = is_array($body) && isset($body['nickname']) ? sanitize_text_field((string) $body['nickname']) : '';
    $nickname = trim($nickname);
    if ($nickname === '' || strlen($nickname) < 2 || strlen($nickname) > 32) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid nickname'], 400);
    }
    if (!preg_match('/^[\p{L}0-9 _.-]+$/u', $nickname)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid nickname'], 400);
    }

    global $wpdb;
    $t = self::table('auth_devices');
    $now = self::auth_now();
    $expires_at = self::auth_code_expires_at();

    $row = self::auth_get_device_row($device_id);
    if (!$row) {
      $code = self::auth_generate_approval_code();
      $wpdb->insert($t, [
        'device_id' => $device_id,
        'nickname' => $nickname,
        'status' => 'pending',
        'approval_code' => $code,
        'code_expires_at' => $expires_at,
        'created_at' => $now,
        'updated_at' => $now,
      ]);
      self::auth_audit('auth_request_access', ['device_id' => $device_id, 'status' => 'pending']);
      return new WP_REST_Response([
        'ok' => true,
        'status' => 'pending',
        'approval_code' => $code,
        'expires_at' => $expires_at,
      ], 200);
    }

    $status = (string) ($row['status'] ?? 'pending');
    if ($status === 'active') {
      return new WP_REST_Response(['ok' => true, 'status' => 'active'], 200);
    }

    $code = (string) ($row['approval_code'] ?? '');
    $expires_at = (string) ($row['code_expires_at'] ?? '');
    $expired = $expires_at !== '' && strtotime($expires_at) < strtotime($now);

    if ($status === 'pending' || $status === 'waiting') {
      if ($expired || $code === '') {
        $code = self::auth_generate_approval_code();
        $expires_at = self::auth_code_expires_at();
        $wpdb->update($t, [
          'approval_code' => $code,
          'code_expires_at' => $expires_at,
          'nickname' => $nickname,
          'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
      }
      return new WP_REST_Response([
        'ok' => true,
        'status' => 'pending',
        'approval_code' => $code,
        'expires_at' => $expires_at,
      ], 200);
    }

    $code = self::auth_generate_approval_code();
    $expires_at = self::auth_code_expires_at();
    $wpdb->update($t, [
      'status' => 'pending',
      'approval_code' => $code,
      'code_expires_at' => $expires_at,
      'nickname' => $nickname,
      'updated_at' => $now,
    ], ['id' => (int) $row['id']]);
    self::auth_audit('auth_request_access', ['device_id' => $device_id, 'status' => 'pending']);
    return new WP_REST_Response([
      'ok' => true,
      'status' => 'pending',
      'approval_code' => $code,
      'expires_at' => $expires_at,
    ], 200);
  }

  public static function rest_auth_refresh(WP_REST_Request $req) {
    $device_id = trim((string) $req->get_header('X-Device-Id'));
    if (!self::auth_is_valid_device_id($device_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid device_id'], 400);
    }

    $row = self::auth_get_device_row($device_id);
    if (!$row) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Unknown device'], 401);
    }

    global $wpdb;
    $t = self::table('auth_devices');
    $status = (string) ($row['status'] ?? 'pending');
    $now = self::auth_now();
    $expires_at = (string) ($row['code_expires_at'] ?? '');

    if (in_array($status, ['pending', 'waiting'], true) && self::auth_is_expired($expires_at, $now)) {
      $status = 'expired';
      $wpdb->update($t, ['status' => 'expired', 'updated_at' => $now], ['id' => (int) $row['id']]);
    }

    if ($status !== 'active') {
      return new WP_REST_Response([
        'ok' => true,
        'status' => $status,
        'expires_at' => $expires_at,
      ], 200);
    }

    $wpdb->update($t, ['last_seen_at' => $now], ['id' => (int) $row['id']]);

    return new WP_REST_Response([
      'ok' => true,
      'status' => 'active',
      'user_id' => (int) ($row['user_id'] ?? 0),
    ], 200);
  }

  public static function rest_auth_me(WP_REST_Request $req) {
    $device_id = trim((string) $req->get_header('X-Device-Id'));
    if (!self::auth_is_valid_device_id($device_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid device_id'], 400);
    }

    $row = self::auth_get_device_row($device_id);
    if (!$row) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Unknown device'], 401);
    }

    global $wpdb;
    $t = self::table('auth_devices');
    $status = (string) ($row['status'] ?? 'pending');
    $now = self::auth_now();
    $expires_at = (string) ($row['code_expires_at'] ?? '');

    if (in_array($status, ['pending', 'waiting'], true) && self::auth_is_expired($expires_at, $now)) {
      $status = 'expired';
      $wpdb->update($t, ['status' => 'expired', 'updated_at' => $now], ['id' => (int) $row['id']]);
    }

    if ($status !== 'active') {
      return new WP_REST_Response([
        'ok' => true,
        'status' => $status,
        'expires_at' => $expires_at,
        'nickname' => (string) ($row['nickname'] ?? ''),
      ], 200);
    }

    $user_id = (int) ($row['user_id'] ?? 0);
    $user = $user_id > 0 ? get_userdata($user_id) : null;

    return new WP_REST_Response([
      'ok' => true,
      'status' => 'active',
      'user' => $user ? [
        'id' => (int) $user->ID,
        'display_name' => (string) $user->display_name,
      ] : null,
      'nickname' => (string) ($row['nickname'] ?? ''),
    ], 200);
  }

  public static function rest_admin_access_requests(WP_REST_Request $req) {
    global $wpdb;
    $t = self::table('auth_devices');
    $status = sanitize_text_field((string) $req->get_param('status'));
    $now = self::auth_now();

    $where = [];
    $args = [];
    if ($status !== '') {
      $where[] = 'status = %s';
      $args[] = $status;
    }

    $sql = "SELECT * FROM $t";
    if ($where) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';
    if ($args) {
      $sql = $wpdb->prepare($sql, $args);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $out = [];
    foreach ($rows as $row) {
      $device_id = (string) ($row['device_id'] ?? '');
      $row_status = (string) ($row['status'] ?? '');
      $expires_at = (string) ($row['code_expires_at'] ?? '');
      if (in_array($row_status, ['pending', 'waiting'], true) && self::auth_is_expired($expires_at, $now)) {
        $row_status = 'expired';
        $wpdb->update($t, ['status' => 'expired', 'updated_at' => $now], ['id' => (int) $row['id']]);
      }
      $out[] = [
        'id' => (int) $row['id'],
        'device_id' => $device_id,
        'device_id_short' => $device_id !== '' ? substr($device_id, 0, 8) : '',
        'nickname' => (string) ($row['nickname'] ?? ''),
        'status' => $row_status,
        'approval_code' => (string) ($row['approval_code'] ?? ''),
        'expires_at' => $expires_at,
        'created_at' => (string) ($row['created_at'] ?? ''),
      ];
    }

    return new WP_REST_Response(['ok' => true, 'requests' => $out], 200);
  }

  public static function rest_admin_access_request_approve(WP_REST_Request $req) {
    global $wpdb;
    $t = self::table('auth_devices');
    $id = (int) $req->get_param('id');
    if ($id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'Invalid id'], 400);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    if (!$row) return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);

    $status = (string) ($row['status'] ?? 'pending');
    if ($status === 'active') {
      return new WP_REST_Response(['ok' => true, 'status' => 'active', 'user_id' => (int) $row['user_id']], 200);
    }

    $nickname = (string) ($row['nickname'] ?? '');
    $user_id = (int) ($row['user_id'] ?? 0);
    if ($user_id <= 0) {
      $user_id = self::auth_create_user_from_nickname($nickname, (string) $row['device_id']);
      if ($user_id <= 0) {
        return new WP_REST_Response(['ok' => false, 'error' => 'User create failed'], 500);
      }
    }

    $wpdb->update($t, [
      'status' => 'active',
      'user_id' => $user_id,
      'approved_by' => (int) get_current_user_id(),
      'approved_at' => self::auth_now(),
      'updated_at' => self::auth_now(),
    ], ['id' => $id]);

    self::auth_audit('auth_request_approved', [
      'device_id' => (string) ($row['device_id'] ?? ''),
      'user_id' => $user_id,
    ]);

    return new WP_REST_Response(['ok' => true, 'status' => 'active', 'user_id' => $user_id], 200);
  }

  public static function rest_admin_access_request_deny(WP_REST_Request $req) {
    global $wpdb;
    $t = self::table('auth_devices');
    $id = (int) $req->get_param('id');
    if ($id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'Invalid id'], 400);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    if (!$row) return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);

    $wpdb->update($t, ['status' => 'denied', 'updated_at' => self::auth_now()], ['id' => $id]);
    self::auth_audit('auth_request_denied', ['device_id' => (string) ($row['device_id'] ?? '')]);
    return new WP_REST_Response(['ok' => true, 'status' => 'denied'], 200);
  }

  public static function rest_admin_access_request_wait(WP_REST_Request $req) {
    global $wpdb;
    $t = self::table('auth_devices');
    $id = (int) $req->get_param('id');
    if ($id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'Invalid id'], 400);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    if (!$row) return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);

    $wpdb->update($t, ['status' => 'waiting', 'updated_at' => self::auth_now()], ['id' => $id]);
    self::auth_audit('auth_request_wait', ['device_id' => (string) ($row['device_id'] ?? '')]);
    return new WP_REST_Response(['ok' => true, 'status' => 'waiting'], 200);
  }

  public static function rest_admin_user_disconnect(WP_REST_Request $req) {
    global $wpdb;
    $t = self::table('auth_devices');
    $user_id = (int) $req->get_param('id');
    if ($user_id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'Invalid user_id'], 400);

    $updated = $wpdb->update($t, ['status' => 'revoked'], ['user_id' => $user_id]);
    self::auth_audit('auth_user_disconnect', ['user_id' => $user_id, 'count' => (int) $updated]);
    return new WP_REST_Response(['ok' => true, 'updated' => (int) $updated], 200);
  }

  public static function rest_post_sheet(WP_REST_Request $req) {
    $payload = $req->get_json_params();
    $sheet = self::sanitize_sheet_payload($payload);
    if (!$sheet) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $post_id = self::create_sheet_post($sheet);
    if (is_wp_error($post_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => $post_id->get_error_message()], 500);
    }
    return new WP_REST_Response(['ok' => true, 'id' => intval($post_id)], 200);
  }

  public static function rest_get_latest(WP_REST_Request $req) {
    $prevadzka = sanitize_text_field($req->get_param('prevadzka'));

    $args = [
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'orderby' => 'date',
      'order' => 'DESC',
    ];

    // If prevadzka is provided, filter by meta.
    if ($prevadzka !== '') {
      $args['meta_query'] = [[
        'key' => '_bc_inventura_header',
        'value' => '"prevadzka";s:' . strlen($prevadzka) . ':"' . $prevadzka . '"',
        'compare' => 'LIKE',
      ]];
    }

    $data = self::get_latest_sheet_data($prevadzka);
    return new WP_REST_Response(['ok' => true, 'sheet' => $data], 200);
  }

  public static function rest_get_audit(WP_REST_Request $req) {
    global $wpdb;

    $tAudit = self::table('audit');

    $store_id = (int) $req->get_param('store_id');
    $action = sanitize_text_field((string) $req->get_param('action'));
    $limit = (int) $req->get_param('limit'); if ($limit <= 0) $limit = 100; if ($limit > 500) $limit = 500;
    $offset = (int) $req->get_param('offset'); if ($offset < 0) $offset = 0;
    $date_from = sanitize_text_field((string) $req->get_param('date_from'));
    $date_to = sanitize_text_field((string) $req->get_param('date_to'));

    $where = [];
    $params = [];

    if ($store_id > 0) { $where[] = 'store_id=%d'; $params[] = $store_id; }
    if ($action !== '') { $where[] = 'action=%s'; $params[] = $action; }
    if ($date_from !== '') { $ts = strtotime($date_from); if ($ts) { $where[] = 'created_at >= %s'; $params[] = date('Y-m-d H:i:s', $ts); } }
    if ($date_to !== '') { $ts = strtotime($date_to); if ($ts) { $where[] = 'created_at <= %s'; $params[] = date('Y-m-d H:i:s', $ts); } }

    $sql = "SELECT * FROM $tAudit";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY created_at DESC';
    $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);

    if ($params) { $sql = $wpdb->prepare($sql, $params); }

    $rows = $wpdb->get_results($sql, ARRAY_A);
    // Decode payload JSON where possible
    foreach ($rows as &$r) {
      $r['payload_decoded'] = null;
      if (!empty($r['payload'])) {
        $d = json_decode($r['payload'], true);
        if (json_last_error() === JSON_ERROR_NONE) $r['payload_decoded'] = $d;
      }
    }

    return new WP_REST_Response(['ok' => true, 'rows' => $rows, 'count' => count($rows)], 200);
  }

  public static function rest_create_reservation(WP_REST_Request $req) {
    $payload = $req->get_json_params();
    $san = self::sanitize_reservation_payload($payload);
    if (!$san) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid reservation payload'], 400);
    }

    $store_id = (int)$san['store_id'];
    if (!self::store_exists_and_active($store_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Unknown store'], 404);
    }

    $uid = get_current_user_id();
    $user = get_userdata($uid);
    $email = $user ? $user->user_email : '';
    $name = $user ? $user->display_name : '';

    $customer_id = self::upsert_customer_for_wp_user($uid, $name, $email);

    $eval = self::evaluate_reservation([
      'pickup_at' => $san['pickup_at'],
      'customer_wp_user_id' => $uid,
      'customer_phone' => '',
      'customer_email' => $email,
    ], $san['items']);

    $res = [
      'store_id' => $store_id,
      'customer_id' => $customer_id ?: null,
      'customer_wp_user_id' => $uid,
      'created_by_user_id' => $uid,
      'staff_user_id' => null,
      'status' => 'submitted',
      'source' => 'pwa',
      'pickup_at' => $san['pickup_at'],
      'confidence' => (int)$eval['confidence'],
      'system_flags' => wp_json_encode($eval['flags'] ?? []),
      'suggested_at' => current_time('mysql'),
      'total_amount' => $san['total_amount'],
      'currency' => 'EUR',
      'customer_note' => $san['customer_note'],
      'internal_note' => null,
      'confirmed_at' => null,
      'ready_at' => null,
      'picked_up_at' => null,
      'cancelled_at' => null,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ];

    $id = self::db_insert_reservation($res, $san['items']);
    if ($id <= 0) {
      return new WP_REST_Response(['ok' => false, 'error' => 'DB insert failed'], 500);
    }

    // Notify store staff about new reservation (submitted).
    $store_name = self::get_store_name($store_id);
    $items_txt = self::reservation_items_to_text($san['items']);
    $sum_txt = number_format((float)$san['total_amount'], 2, ',', ' ') . ' EUR';
    $subj = 'Nová rezervácia #' . $id . ' (' . $store_name . ')';
    $body = "Prišla nová rezervácia.\n\nPrevádzka: $store_name\nVyzdvihnutie: " . $san['pickup_at'] . "\nZákazník: $name <$email>\n\nPoložky:\n$items_txt\n\nSuma: $sum_txt";
    if (self::is_notify_enabled_for_status('submitted')) {
      $roles = self::get_setting_notify_submitted_roles();
      self::notify_store_staff($store_id, $subj, $body, $roles, ['reservation_id' => $id, 'mail_type' => 'submitted']);
    }

    return new WP_REST_Response(['ok' => true, 'id' => $id], 200);
  }

  public static function rest_get_my_reservations(WP_REST_Request $req) {
    global $wpdb;
    $uid = get_current_user_id();
    $tRes = self::table('reservations');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tRes WHERE customer_wp_user_id=%d ORDER BY created_at DESC LIMIT 50", $uid), ARRAY_A);
    foreach ($rows as &$r) {
      $r['items'] = self::db_get_reservation_items((int)$r['id']);
    }
    return new WP_REST_Response(['ok' => true, 'reservations' => $rows], 200);
  }

  public static function rest_get_store_reservations(WP_REST_Request $req) {
    global $wpdb;
    $store_id = (int)$req->get_param('store_id');
    $status = sanitize_text_field((string)$req->get_param('status'));
    $date_from = sanitize_text_field((string)$req->get_param('date_from'));
    $date_to = sanitize_text_field((string)$req->get_param('date_to'));

    $tRes = self::table('reservations');
    $where = "store_id=%d";
    $params = [$store_id];

    if ($status !== '') { $where .= " AND status=%s"; $params[] = $status; }
    if ($date_from !== '') {
      $ts = strtotime($date_from);
      if ($ts) { $where .= " AND pickup_at >= %s"; $params[] = date('Y-m-d 00:00:00', $ts); }
    }
    if ($date_to !== '') {
      $ts = strtotime($date_to);
      if ($ts) { $where .= " AND pickup_at <= %s"; $params[] = date('Y-m-d 23:59:59', $ts); }
    }

    $sql = $wpdb->prepare("SELECT * FROM $tRes WHERE $where ORDER BY pickup_at ASC, created_at ASC LIMIT 200", $params);
    $rows = $wpdb->get_results($sql, ARRAY_A);
    foreach ($rows as &$r) {
      $r['items'] = self::db_get_reservation_items((int)$r['id']);
    }
    return new WP_REST_Response(['ok' => true, 'reservations' => $rows], 200);
  }

  public static function rest_set_reservation_status(WP_REST_Request $req) {
    $id = (int)$req->get_param('id');
    $payload = $req->get_json_params();
    $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : '';
    $allowed = ['submitted','confirmed','ready','picked_up','cancelled','expired'];
    if (!in_array($status, $allowed, true)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Invalid status'], 400);
    }

    $fields = ['status' => $status, 'staff_user_id' => get_current_user_id(), 'updated_at' => current_time('mysql')];
    if ($status === 'confirmed') $fields['confirmed_at'] = current_time('mysql');
    if ($status === 'ready') $fields['ready_at'] = current_time('mysql');
    if ($status === 'picked_up') $fields['picked_up_at'] = current_time('mysql');
    if ($status === 'cancelled') $fields['cancelled_at'] = current_time('mysql');

        $ok = self::set_reservation_status_internal($id, $status, get_current_user_id());
    if (!$ok) return new WP_REST_Response(['ok' => false, 'error' => 'Update failed'], 500);
    return new WP_REST_Response(['ok' => true], 200);
  }

  public static function rest_convert_reservation_to_order(WP_REST_Request $req) {
    $id = (int)$req->get_param('id');
    $order_id = self::convert_reservation_to_order_internal($id);
    if (is_wp_error($order_id)) {
      return new WP_REST_Response(['ok' => false, 'error' => $order_id->get_error_message()], 400);
    }
    return new WP_REST_Response(['ok' => true, 'order_id' => (int)$order_id], 200);
  }

}
