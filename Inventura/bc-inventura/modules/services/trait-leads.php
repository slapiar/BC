<?php
if (!defined('ABSPATH')) exit;

/**
 * Services: Leads CRUD
 */
trait BC_Inv_Trait_Leads {

  /**
   * Write audit log entry related to leads.
   */
  protected static function audit_lead(string $action, ?int $store_id, ?int $lead_id, array $payload = []) {
    global $wpdb;
    $tAudit = self::table('audit');

    $row = [
      'store_id' => $store_id ? (int) $store_id : null,
      'sheet_post_id' => null,
      'actor_user_id' => (int) (function_exists('get_current_user_id') ? get_current_user_id() : 0),
      'action' => sanitize_key($action),
      'payload' => (function_exists('wp_json_encode') ? wp_json_encode(array_merge([
        'lead_id' => $lead_id ? (int) $lead_id : null,
      ], $payload)) : json_encode(array_merge([
        'lead_id' => $lead_id ? (int) $lead_id : null,
      ], $payload))),
    ];

    // Best-effort audit (never break primary flow on audit failure)
    try {
      $wpdb->insert($tAudit, $row);
    } catch (\Throwable $e) {
      // noop
    }
  }

  /**
   * Create a lead.
   *
   * @param array $lead
   * @return int lead_id
   * @throws InvalidArgumentException
   */
  public static function create_lead(array $lead) {
    global $wpdb;
    $tLeads = self::table('leads');

    if (empty($lead['store_id'])) {
      throw new InvalidArgumentException('store_id is required');
    }

    $data = self::sanitize_lead_data($lead, true);
    $ok = $wpdb->insert($tLeads, $data);
    if ($ok === false) {
      throw new RuntimeException('DB insert failed (leads)');
    }
    $lead_id = (int) $wpdb->insert_id;
    self::audit_lead('lead_create', (int)$data['store_id'], $lead_id, [
      'source' => $data['source'] ?? null,
      'status' => $data['status'] ?? null,
    ]);
    return $lead_id; 
  }

  /**
   * Get lead by id.
   */
  public static function get_lead(int $lead_id) {
    global $wpdb;
    $tLeads = self::table('leads');
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM $tLeads WHERE id = %d", $lead_id),
      ARRAY_A
    );
  }

  /**
   * List leads with basic filters.
   *
   * Supported filters:
   * - store_id (int)
   * - status (string)
   * - q (string) search in name/email/phone/interest
   * - limit (int, default 50, max 200)
   * - offset (int, default 0)
   */
  public static function list_leads(array $filters = []) {
    global $wpdb;
    $tLeads = self::table('leads');

    $where = [];
    $args = [];

    /**
     * Soft-delete filter:
     * - 'exclude' (default): hide deleted
     * - 'only': show only deleted
     * - 'include': show all (including deleted)
     */
    $deleted_mode = isset($filters['deleted']) ? (string) $filters['deleted'] : 'exclude';
    if (!in_array($deleted_mode, ['exclude','only','include'], true)) {
      $deleted_mode = 'exclude';
    }
    if ($deleted_mode === 'exclude') {
      $where[] = "status <> %s";
      $args[] = 'deleted';
    } elseif ($deleted_mode === 'only') {
      $where[] = "status = %s";
      $args[] = 'deleted';
    }

    if (!empty($filters['store_id'])) {
      $where[] = 'store_id = %d';
      $args[] = (int) $filters['store_id'];
    }
    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $args[] = sanitize_key((string) $filters['status']);
    }
    if (!empty($filters['q'])) {
      $q = '%' . $wpdb->esc_like((string) $filters['q']) . '%';
      $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR interest LIKE %s)';
      array_push($args, $q, $q, $q, $q);
    }

    $limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
    if ($limit < 1) $limit = 50;
    if ($limit > 200) $limit = 200;
    $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
    if ($offset < 0) $offset = 0;

    $sql = "SELECT * FROM $tLeads";
    if ($where) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';
    $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);

    // If we used placeholders earlier, prepare whole SQL:
    if ($args) {
      $sql = $wpdb->prepare($sql, $args);
    }

    return $wpdb->get_results($sql, ARRAY_A);
  }

  /**
   * Update lead.
   *
   * @return bool
   */
  public static function edit_lead(int $lead_id, array $patch) {
    global $wpdb;
    $tLeads = self::table('leads');

    // Do not allow changing primary key
    unset($patch['id']);

    $before = self::get_lead($lead_id);
    $data = self::sanitize_lead_data($patch, false);
    if (!$data) return false;

    $ok = $wpdb->update($tLeads, $data, ['id' => $lead_id]);
    $success = ($ok !== false);
    if ($success) {
      // Try to infer store_id (prefer current row)
      $store_id = null;
      if (isset($before['store_id'])) $store_id = (int) $before['store_id'];
      if (isset($data['store_id'])) $store_id = (int) $data['store_id'];
      self::audit_lead('lead_edit', $store_id, $lead_id, [
        'changed' => array_keys($data),
      ]);
    }
    return $success; 
  }

  /**
   * Soft delete lead (keeps row, hides from default lists).
   */
  public static function delete_lead(int $lead_id) {
    $row = self::get_lead($lead_id);
    $ok = self::edit_lead($lead_id, [
      'status' => 'deleted',
      'deleted_at' => current_time('mysql'),
    ]);
    if ($ok) {
      self::audit_lead('lead_soft_delete', isset($row['store_id']) ? (int)$row['store_id'] : null, $lead_id, [
        'prev_status' => $row['status'] ?? null,
      ]);
    }
    return $ok;
  }

  /**
   * Restore a previously soft-deleted lead.
   *
   * @param int $lead_id
   * @param string $status Status after restore (default 'new')
   * @return bool
   */
  public static function restore_lead(int $lead_id, string $status = 'new') {
    $status = sanitize_key($status);
    if ($status === '' || $status === 'deleted') {
      $status = 'new';
    }
    $row = self::get_lead($lead_id);
    $ok = self::edit_lead($lead_id, [
      'status' => $status,
      'deleted_at' => null,
    ]);
    if ($ok) {
      self::audit_lead('lead_restore', isset($row['store_id']) ? (int)$row['store_id'] : null, $lead_id, [
        'to_status' => $status,
      ]);
    }
    return $ok;
  }

  /**
   * Hard delete lead (physically removes row). Use for test data cleanup.
   */
  public static function hard_delete_lead(int $lead_id) {
    global $wpdb;
    $tLeads = self::table('leads');
    $before = self::get_lead($lead_id);
    $ok = $wpdb->delete($tLeads, ['id' => $lead_id]);
    $success = ($ok !== false);
    if ($success) {
      self::audit_lead('lead_hard_delete', isset($before['store_id']) ? (int)$before['store_id'] : null, $lead_id, [
        'snapshot' => $before,
      ]);
    }
    return $success;
  }

  /**
   * Sanitize and normalize lead data for DB.
   */
  protected static function sanitize_lead_data(array $in, bool $is_insert) {
    $out = [];

    // Required on insert
    if ($is_insert) {
      $out['store_id'] = (int) ($in['store_id'] ?? 0);
      $out['source'] = isset($in['source']) ? sanitize_key((string) $in['source']) : 'pwa';
      $out['status'] = isset($in['status']) ? sanitize_key((string) $in['status']) : 'new';
      $out['consent'] = isset($in['consent']) ? (int) ((bool) $in['consent']) : 0;
      if (!empty($in['consent_at'])) {
        $out['consent_at'] = self::sanitize_datetime((string) $in['consent_at']);
      }
    } else {
      // Optional on update
      if (isset($in['store_id'])) $out['store_id'] = (int) $in['store_id'];
      if (isset($in['source'])) $out['source'] = sanitize_key((string) $in['source']);
      if (isset($in['status'])) $out['status'] = sanitize_key((string) $in['status']);
      if (isset($in['consent'])) $out['consent'] = (int) ((bool) $in['consent']);
      if (array_key_exists('consent_at', $in)) {
        $out['consent_at'] = empty($in['consent_at']) ? null : self::sanitize_datetime((string) $in['consent_at']);
      }
      if (array_key_exists('deleted_at', $in)) {
        $out['deleted_at'] = empty($in['deleted_at']) ? null : self::sanitize_datetime((string) $in['deleted_at']);
      }
    }

    // Common fields
    if (isset($in['name'])) $out['name'] = sanitize_text_field((string) $in['name']);
    if (isset($in['email'])) $out['email'] = sanitize_email((string) $in['email']);
    if (isset($in['phone'])) $out['phone'] = sanitize_text_field((string) $in['phone']);
    if (isset($in['interest'])) $out['interest'] = sanitize_text_field((string) $in['interest']);
    if (array_key_exists('note', $in)) {
      $out['note'] = (isset($in['note']) && $in['note'] !== '') ? wp_kses_post((string) $in['note']) : null;
    }

    // Avoid empty update payload
    return $out;
  }

  /**
   * Accepts 'Y-m-d H:i:s' (or ISO-ish) and returns MySQL DATETIME string.
   */
  protected static function sanitize_datetime(string $dt) {
    $dt = trim($dt);
    if ($dt === '') return null;

    // Try to parse various formats
    $ts = strtotime($dt);
    if (!$ts) return null;
    return gmdate('Y-m-d H:i:s', $ts);
  }
}
