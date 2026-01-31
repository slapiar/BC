<?php
if (!defined('ABSPATH')) exit;

/**
 * Services: Leads CRUD
 */
trait BC_Inv_Trait_Leads {

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
    return (int) $wpdb->insert_id;
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

    $data = self::sanitize_lead_data($patch, false);
    if (!$data) return false;

    $ok = $wpdb->update($tLeads, $data, ['id' => $lead_id]);
    return ($ok !== false);
  }

  /**
   * Soft delete lead (keeps row, hides from default lists).
   */
  public static function delete_lead(int $lead_id) {
    return self::edit_lead($lead_id, [
      'status' => 'deleted',
      'deleted_at' => current_time('mysql'),
    ]);
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
    return self::edit_lead($lead_id, [
      'status' => $status,
      'deleted_at' => null,
    ]);
  }

  /**
   * Hard delete lead (physically removes row). Use for test data cleanup.
   */
  public static function hard_delete_lead(int $lead_id) {
    global $wpdb;
    $tLeads = self::table('leads');
    $ok = $wpdb->delete($tLeads, ['id' => $lead_id]);
    return ($ok !== false);
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
