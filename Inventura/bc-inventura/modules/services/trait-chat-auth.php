<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 1: Chat Authentication & Access Requests
 * Handles device registration, approval flow, and personal room creation.
 */
trait BC_Inv_Trait_Chat_Auth {

  /**
   * Generate a readable 6-character approval code.
   */
  private static function generate_approval_code(): string {
    // Use readable characters only (exclude ambiguous: 0, O, I, l, 1)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
      $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
  }

  /**
   * Get device by device_id.
   */
  private static function get_device_by_id($device_id) {
    global $wpdb;
    $t = self::table('devices');
    $device_id = sanitize_text_field($device_id);
    if (empty($device_id)) return null;
    
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t WHERE device_id=%s LIMIT 1",
      $device_id
    ), ARRAY_A);
  }

  /**
   * Get access request by device_id.
   */
  private static function get_access_request_by_device($device_id) {
    global $wpdb;
    $t = self::table('access_requests');
    $device_id = sanitize_text_field($device_id);
    if (empty($device_id)) return null;
    
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t WHERE device_id=%s ORDER BY created_at DESC LIMIT 1",
      $device_id
    ), ARRAY_A);
  }

  /**
   * Create or update device record.
   */
  private static function upsert_device($device_id, $data) {
    global $wpdb;
    $t = self::table('devices');
    
    $existing = self::get_device_by_id($device_id);
    
    $fields = [
      'device_id' => sanitize_text_field($device_id),
      'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'pending',
      'wp_user_id' => isset($data['wp_user_id']) ? (int)$data['wp_user_id'] : null,
      'nickname' => isset($data['nickname']) ? sanitize_text_field($data['nickname']) : null,
      'phone_hint' => isset($data['phone_hint']) ? sanitize_text_field($data['phone_hint']) : null,
      'client_info' => isset($data['client_info']) ? wp_json_encode($data['client_info']) : null,
      'updated_at' => current_time('mysql'),
    ];
    
    if ($existing) {
      $wpdb->update($t, $fields, ['device_id' => $device_id]);
      return $existing['id'];
    } else {
      $fields['created_at'] = current_time('mysql');
      $wpdb->insert($t, $fields);
      return $wpdb->insert_id;
    }
  }

  /**
   * Create access request.
   */
  private static function create_access_request($device_id, $nickname, $approval_code, $data = []) {
    global $wpdb;
    $t = self::table('access_requests');
    
    // Default expiration: 24 hours from now
    $expires_at = isset($data['expires_at']) ? $data['expires_at'] : 
      date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $fields = [
      'device_id' => sanitize_text_field($device_id),
      'nickname' => sanitize_text_field($nickname),
      'approval_code' => sanitize_text_field($approval_code),
      'phone_hint' => isset($data['phone_hint']) ? sanitize_text_field($data['phone_hint']) : null,
      'client_info' => isset($data['client_info']) ? wp_json_encode($data['client_info']) : null,
      'status' => 'pending',
      'expires_at' => $expires_at,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ];
    
    $wpdb->insert($t, $fields);
    return $wpdb->insert_id;
  }

  /**
   * Update access request status.
   */
  private static function update_access_request_status($request_id, $status, $user_id = null) {
    global $wpdb;
    $t = self::table('access_requests');
    
    $fields = [
      'status' => sanitize_text_field($status),
      'updated_at' => current_time('mysql'),
    ];
    
    if ($status === 'approved' && $user_id) {
      $fields['approved_by_user_id'] = (int)$user_id;
      $fields['approved_at'] = current_time('mysql');
    } elseif ($status === 'denied' && $user_id) {
      $fields['denied_by_user_id'] = (int)$user_id;
      $fields['denied_at'] = current_time('mysql');
    }
    
    $wpdb->update($t, $fields, ['id' => (int)$request_id]);
  }

  /**
   * Create personal room for user.
   * Returns room ID or false on failure.
   */
  private static function create_personal_room($user_id) {
    global $wpdb;
    $t = self::table('chat_rooms');
    $user_id = (int)$user_id;
    
    if ($user_id <= 0) return false;
    
    $user = get_userdata($user_id);
    if (!$user) return false;
    
    $slug = 'inbox:' . $user_id;
    
    // Check if room already exists
    $existing = $wpdb->get_row($wpdb->prepare(
      "SELECT id FROM $t WHERE slug=%s LIMIT 1",
      $slug
    ), ARRAY_A);
    
    if ($existing) {
      return (int)$existing['id'];
    }
    
    // Create new room
    $fields = [
      'slug' => $slug,
      'type' => 'personal',
      'owner_user_id' => $user_id,
      'name' => $user->display_name . ' Inbox',
      'description' => 'Personal inbox for ' . $user->display_name,
      'is_active' => 1,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ];
    
    $wpdb->insert($t, $fields);
    return $wpdb->insert_id;
  }

  /**
   * Get or create WP user for approved device.
   * Uses existing internal model.
   */
  private static function get_or_create_user_for_device($nickname, $phone_hint = null) {
    // Sanitize nickname to create username
    $username = sanitize_user($nickname, true);
    $username = strtolower(trim($username));
    
    // Ensure username is unique
    $base_username = $username;
    $counter = 1;
    while (username_exists($username)) {
      $username = $base_username . $counter;
      $counter++;
    }
    
    // Generate email (required by WP)
    $email = $username . '@chat.local';
    $counter = 1;
    while (email_exists($email)) {
      $email = $username . $counter . '@chat.local';
      $counter++;
    }
    
    // Create user
    $user_id = wp_create_user($username, wp_generate_password(24, true, true), $email);
    
    if (is_wp_error($user_id)) {
      return false;
    }
    
    // Update display name to nickname
    wp_update_user([
      'ID' => $user_id,
      'display_name' => $nickname,
      'nickname' => $nickname,
    ]);
    
    // Set basic chat member role (you can adjust this based on your needs)
    $user = get_userdata($user_id);
    $user->set_role('subscriber'); // Or create a custom 'chat_member' role
    
    // Store phone hint if provided
    if ($phone_hint) {
      update_user_meta($user_id, 'phone_hint', sanitize_text_field($phone_hint));
    }
    
    return $user_id;
  }

  /**
   * Approve access request.
   * Creates user, activates device, creates personal room, logs audit.
   */
  private static function approve_access_request($request_id, $admin_user_id) {
    global $wpdb;
    
    $tReq = self::table('access_requests');
    $request = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $tReq WHERE id=%d LIMIT 1",
      (int)$request_id
    ), ARRAY_A);
    
    if (!$request || $request['status'] !== 'pending') {
      return new WP_Error('invalid_request', 'Request not found or not pending');
    }
    
    // Get or create WP user
    $user_id = self::get_or_create_user_for_device(
      $request['nickname'],
      $request['phone_hint']
    );
    
    if (!$user_id) {
      return new WP_Error('user_creation_failed', 'Failed to create user');
    }
    
    // Update device status to active
    self::upsert_device($request['device_id'], [
      'status' => 'active',
      'wp_user_id' => $user_id,
      'nickname' => $request['nickname'],
      'phone_hint' => $request['phone_hint'],
    ]);
    
    // Update request status
    self::update_access_request_status($request_id, 'approved', $admin_user_id);
    
    // Create personal room
    $room_id = self::create_personal_room($user_id);
    
    // Audit log
    self::log_audit([
      'action' => 'chat_access_approved',
      'actor_user_id' => $admin_user_id,
      'payload' => wp_json_encode([
        'request_id' => $request_id,
        'device_id' => $request['device_id'],
        'nickname' => $request['nickname'],
        'user_id' => $user_id,
        'room_id' => $room_id,
      ]),
    ]);
    
    return [
      'user_id' => $user_id,
      'room_id' => $room_id,
    ];
  }

  /**
   * Deny access request.
   * Sets status to denied, logs audit.
   */
  private static function deny_access_request($request_id, $admin_user_id) {
    global $wpdb;
    
    $tReq = self::table('access_requests');
    $request = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $tReq WHERE id=%d LIMIT 1",
      (int)$request_id
    ), ARRAY_A);
    
    if (!$request || $request['status'] !== 'pending') {
      return new WP_Error('invalid_request', 'Request not found or not pending');
    }
    
    // Update device status to denied
    self::upsert_device($request['device_id'], [
      'status' => 'denied',
    ]);
    
    // Update request status
    self::update_access_request_status($request_id, 'denied', $admin_user_id);
    
    // Audit log
    self::log_audit([
      'action' => 'chat_access_denied',
      'actor_user_id' => $admin_user_id,
      'payload' => wp_json_encode([
        'request_id' => $request_id,
        'device_id' => $request['device_id'],
        'nickname' => $request['nickname'],
      ]),
    ]);
    
    return true;
  }

  /**
   * Set request to waiting status.
   */
  private static function wait_access_request($request_id, $admin_user_id) {
    global $wpdb;
    
    $tReq = self::table('access_requests');
    $request = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $tReq WHERE id=%d LIMIT 1",
      (int)$request_id
    ), ARRAY_A);
    
    if (!$request) {
      return new WP_Error('invalid_request', 'Request not found');
    }
    
    // Update request status
    self::update_access_request_status($request_id, 'waiting', $admin_user_id);
    
    // Audit log
    self::log_audit([
      'action' => 'chat_access_waiting',
      'actor_user_id' => $admin_user_id,
      'payload' => wp_json_encode([
        'request_id' => $request_id,
        'device_id' => $request['device_id'],
        'nickname' => $request['nickname'],
      ]),
    ]);
    
    return true;
  }

  /**
   * Expire access request immediately.
   */
  private static function expire_access_request($request_id, $admin_user_id) {
    global $wpdb;
    
    $tReq = self::table('access_requests');
    $request = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $tReq WHERE id=%d LIMIT 1",
      (int)$request_id
    ), ARRAY_A);
    
    if (!$request) {
      return new WP_Error('invalid_request', 'Request not found');
    }
    
    // Update request status
    self::update_access_request_status($request_id, 'expired', $admin_user_id);
    
    // Audit log
    self::log_audit([
      'action' => 'chat_access_expired',
      'actor_user_id' => $admin_user_id,
      'payload' => wp_json_encode([
        'request_id' => $request_id,
        'device_id' => $request['device_id'],
        'nickname' => $request['nickname'],
      ]),
    ]);
    
    return true;
  }

  /**
   * Helper: Log audit entry.
   */
  private static function log_audit($data) {
    global $wpdb;
    $t = self::table('audit');
    
    $fields = [
      'action' => sanitize_text_field($data['action']),
      'actor_user_id' => isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null,
      'store_id' => isset($data['store_id']) ? (int)$data['store_id'] : null,
      'sheet_post_id' => isset($data['sheet_post_id']) ? (int)$data['sheet_post_id'] : null,
      'payload' => isset($data['payload']) ? $data['payload'] : null,
      'created_at' => current_time('mysql'),
    ];
    
    $wpdb->insert($t, $fields);
  }

  /**
   * REST endpoint: POST /auth/request-access
   * Creates pending access request and returns approval code.
   */
  public static function rest_request_access(WP_REST_Request $req) {
    $payload = $req->get_json_params();
    
    // Get device_id from X-Device-Id header
    $device_id = $req->get_header('X-Device-Id');
    if (empty($device_id)) {
      $device_id = isset($payload['device_id']) ? $payload['device_id'] : null;
    }
    
    if (empty($device_id)) {
      return new WP_REST_Response([
        'ok' => false,
        'error' => 'device_id required (X-Device-Id header or device_id field)',
      ], 400);
    }
    
    $device_id = sanitize_text_field($device_id);
    
    // Validate nickname
    $nickname = isset($payload['nickname']) ? trim($payload['nickname']) : '';
    if (strlen($nickname) < 2 || strlen($nickname) > 32) {
      return new WP_REST_Response([
        'ok' => false,
        'error' => 'nickname must be between 2 and 32 characters',
      ], 400);
    }
    
    // Optional fields
    $phone_hint = isset($payload['phone_hint']) ? sanitize_text_field($payload['phone_hint']) : null;
    $client_info = isset($payload['client_info']) ? $payload['client_info'] : [];
    
    // Check if device already has an active status
    $device = self::get_device_by_id($device_id);
    if ($device && $device['status'] === 'active') {
      return new WP_REST_Response([
        'ok' => false,
        'error' => 'device_already_active',
        'message' => 'This device is already approved',
      ], 400);
    }
    
    // Check for existing pending request
    $existing_request = self::get_access_request_by_device($device_id);
    if ($existing_request && $existing_request['status'] === 'pending') {
      // Return existing approval code
      return new WP_REST_Response([
        'ok' => true,
        'approval_code' => $existing_request['approval_code'],
        'status' => 'pending',
        'message' => 'Request already submitted. Use this code.',
      ], 200);
    }
    
    // Generate approval code
    $approval_code = self::generate_approval_code();
    
    // Create/update device record
    self::upsert_device($device_id, [
      'status' => 'pending',
      'nickname' => $nickname,
      'phone_hint' => $phone_hint,
      'client_info' => $client_info,
    ]);
    
    // Create access request
    $request_id = self::create_access_request($device_id, $nickname, $approval_code, [
      'phone_hint' => $phone_hint,
      'client_info' => $client_info,
    ]);
    
    if (!$request_id) {
      return new WP_REST_Response([
        'ok' => false,
        'error' => 'Failed to create access request',
      ], 500);
    }
    
    // Audit log
    self::log_audit([
      'action' => 'chat_access_requested',
      'payload' => wp_json_encode([
        'request_id' => $request_id,
        'device_id' => $device_id,
        'nickname' => $nickname,
      ]),
    ]);
    
    return new WP_REST_Response([
      'ok' => true,
      'approval_code' => $approval_code,
      'status' => 'pending',
      'message' => 'Access request submitted. Please contact admin with your nickname and code.',
    ], 200);
  }

  /**
   * REST endpoint: GET /auth/device-status
   * Check device status (for PWA to poll).
   */
  public static function rest_device_status(WP_REST_Request $req) {
    $device_id = $req->get_header('X-Device-Id');
    if (empty($device_id)) {
      $device_id = $req->get_param('device_id');
    }
    
    if (empty($device_id)) {
      return new WP_REST_Response([
        'ok' => false,
        'error' => 'device_id required',
      ], 400);
    }
    
    $device = self::get_device_by_id($device_id);
    
    if (!$device) {
      return new WP_REST_Response([
        'ok' => true,
        'status' => 'not_found',
        'active' => false,
      ], 200);
    }
    
    return new WP_REST_Response([
      'ok' => true,
      'status' => $device['status'],
      'active' => $device['status'] === 'active',
      'user_id' => $device['wp_user_id'],
    ], 200);
  }
}
