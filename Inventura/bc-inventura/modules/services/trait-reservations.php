<?php
if (!defined('ABSPATH')) exit;

trait BC_Inv_Trait_Reservations {

  // ===== Email logging (Etapa A2) =====
  private static function mailer_last_error(): string {
    if (isset($GLOBALS['phpmailer']) && is_object($GLOBALS['phpmailer']) && !empty($GLOBALS['phpmailer']->ErrorInfo)) {
      return (string)$GLOBALS['phpmailer']->ErrorInfo;
    }
    return '';
  }

  private static function log_email_attempt($store_id, $reservation_id, $to, $subject, $type, $ok, $error_text = ''): void {
    global $wpdb;
    $t = self::table('email_log');
    // Table might not exist yet on very first run; fail silently.
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
    if ($exists !== $t) return;

    $wpdb->insert($t, [
      'store_id' => $store_id ? (int)$store_id : null,
      'reservation_id' => $reservation_id ? (int)$reservation_id : null,
      'mail_to' => sanitize_email((string)$to),
      'subject' => sanitize_text_field((string)$subject),
      'mail_type' => sanitize_text_field((string)$type),
      'ok' => $ok ? 1 : 0,
      'error_text' => $error_text ? wp_strip_all_tags((string)$error_text) : null,
      'created_at' => current_time('mysql'),
    ]);
  }
  private static function evaluate_reservation($reservation, $items) {
    $score = 0;
    $flags = [];

    $pickup_at = isset($reservation['pickup_at']) ? (string)$reservation['pickup_at'] : '';
    $pickup_ts = $pickup_at ? strtotime($pickup_at) : 0;
    $now = time();

    $wp_uid = isset($reservation['customer_wp_user_id']) ? (int)$reservation['customer_wp_user_id'] : 0;
    if ($wp_uid > 0) $score += 25;

    $has_contact = !empty($reservation['customer_phone']) || !empty($reservation['customer_email']);
    if ($has_contact) $score += 20; else $flags[] = 'NO_CONTACT';

    // B2.3 flagy: duplicita podľa kontaktu a času (len ak máme pickup čas aj kontakt)
    $store_id = isset($reservation['store_id']) ? (int)$reservation['store_id'] : 0;
    $phone_norm = !empty($reservation['customer_phone']) ? preg_replace('/\D+/', '', (string)$reservation['customer_phone']) : '';
    $email_norm = !empty($reservation['customer_email']) ? strtolower(trim((string)$reservation['customer_email'])) : '';
    if ($pickup_ts > 0 && $has_contact && $store_id > 0 && ($phone_norm || $email_norm)) {
      $win_from = date('Y-m-d H:i:s', $pickup_ts - 2*3600);
      $win_to   = date('Y-m-d H:i:s', $pickup_ts + 2*3600);
      $rid = isset($reservation['id']) ? (int)$reservation['id'] : 0;
      global $wpdb;
      $tRes = self::table('reservations');
      // Neberieme zrušené/expirnuté; porovnávame len relevantné rezervácie
      $statuses = ['submitted','confirmed','ready','picked_up'];
      $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
      $sql = "SELECT COUNT(*) FROM $tRes WHERE store_id = %d AND pickup_at BETWEEN %s AND %s AND status IN ($placeholders)";
      $args = array_merge([$store_id, $win_from, $win_to], $statuses);
      if ($rid > 0) { $sql .= " AND id <> %d"; $args[] = $rid; }
      // Match podľa emailu alebo telefónu (ak je k dispozícii)
      if ($email_norm && $phone_norm) {
        $sql .= " AND (LOWER(customer_email) = %s OR REGEXP_REPLACE(customer_phone, '[^0-9]', '') = %s)";
        $args[] = $email_norm;
        $args[] = $phone_norm;
      } elseif ($email_norm) {
        $sql .= " AND LOWER(customer_email) = %s";
        $args[] = $email_norm;
      } else {
        $sql .= " AND REGEXP_REPLACE(customer_phone, '[^0-9]', '') = %s";
        $args[] = $phone_norm;
      }
      // REGEXP_REPLACE nemusí byť dostupné na starších MySQL; fallback na jednoduché porovnanie
      try {
        $cnt = (int)$wpdb->get_var($wpdb->prepare($sql, $args));
      } catch (Throwable $e) {
        $sql2 = "SELECT COUNT(*) FROM $tRes WHERE store_id = %d AND pickup_at BETWEEN %s AND %s AND status IN ($placeholders)";
        $args2 = array_merge([$store_id, $win_from, $win_to], $statuses);
        if ($rid > 0) { $sql2 .= " AND id <> %d"; $args2[] = $rid; }
        if ($email_norm) { $sql2 .= " AND LOWER(customer_email) = %s"; $args2[] = $email_norm; }
        if ($phone_norm) { $sql2 .= ($email_norm ? " OR " : " AND ") . "customer_phone = %s"; $args2[] = (string)$reservation['customer_phone']; }
        $cnt = (int)$wpdb->get_var($wpdb->prepare($sql2, $args2));
      }
      if ($cnt > 0) { $flags[] = 'DUPLICATE'; $score -= 25; }
    }

    if ($pickup_ts > 0) {
      $hours = ($pickup_ts - $now) / 3600.0;
      if ($hours >= 12) $score += 20;
      if ($hours < 3) { $score -= 25; $flags[] = 'FAST_PICKUP'; }
      if ($hours < 0) { $score -= 30; $flags[] = 'PAST_DUE'; }
    } else {
      $flags[] = 'NO_PICKUP_TIME';
      $score -= 15;
    }

    $total_qty = 0.0;
    $total_amount = 0.0;
    if (is_array($items)) {
      foreach ($items as $it) {
        $total_qty += (float)($it['qty'] ?? 0);
        $total_amount += (float)($it['line_total'] ?? 0);
      }
    }
    if ($total_qty >= 30) { $score -= 15; $flags[] = 'HIGH_QTY'; }
    if ($total_amount >= 80) { $score -= 10; $flags[] = 'HIGH_VALUE'; }

    if ($score < 0) $score = 0;
    if ($score > 100) $score = 100;

    return ['confidence' => (int)$score, 'flags' => $flags];
  }

  private static function reservation_items_to_text($items) {
    $lines = [];
    foreach ((array)$items as $it) {
      $name = (string)($it['name'] ?? '');
      $qty = isset($it['qty']) ? (float)$it['qty'] : 0.0;
      $unit = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;
      $lines[] = trim($name) . ' × ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' @ ' . number_format($unit, 2, ',', ' ') . ' €';
    }
    return implode("\n", $lines);
  }
  private static function notify_store_staff($store_id, $subject, $body, $roles = ['manager'], $meta = []) {
    $emails = self::get_store_user_emails($store_id, $roles);
    if (empty($emails)) return false;
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $ok_any = false;
    $reservation_id = isset($meta['reservation_id']) ? (int)$meta['reservation_id'] : 0;
    $mail_type = isset($meta['mail_type']) ? (string)$meta['mail_type'] : 'staff';
    foreach ($emails as $to) {
      // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
      $ok = wp_mail($to, $subject, $body, $headers);
      $err = $ok ? '' : self::mailer_last_error();
      self::log_email_attempt($store_id, $reservation_id, $to, $subject, $mail_type, (bool)$ok, $err);
      if ($ok) $ok_any = true;
    }
    return $ok_any;
  }
  private static function notify_customer($customer_wp_user_id, $subject, $body, $meta = []) {
    $uid = (int)$customer_wp_user_id;
    if ($uid <= 0) return false;
    $u = get_userdata($uid);
    $to = ($u && !empty($u->user_email)) ? sanitize_email($u->user_email) : '';
    if (!$to) return false;
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
    $ok = wp_mail($to, $subject, $body, $headers);
    $store_id = isset($meta['store_id']) ? (int)$meta['store_id'] : 0;
    $reservation_id = isset($meta['reservation_id']) ? (int)$meta['reservation_id'] : 0;
    $mail_type = isset($meta['mail_type']) ? (string)$meta['mail_type'] : 'customer';
    $err = $ok ? '' : self::mailer_last_error();
    self::log_email_attempt($store_id, $reservation_id, $to, $subject, $mail_type, (bool)$ok, $err);
    return (bool)$ok;
  }
  private static function set_reservation_status_internal($reservation_id, $new_status, $actor_user_id = null) {
    $reservation_id = (int)$reservation_id;
    $new_status = sanitize_text_field((string)$new_status);
    $allowed = ['submitted','confirmed','ready','picked_up','cancelled','expired'];
    if ($reservation_id <= 0 || !in_array($new_status, $allowed, true)) return false;

    $row = self::db_get_reservation($reservation_id);
    if (!$row) return false;

    $old_status = (string)($row['status'] ?? '');
    if ($old_status === $new_status) return true;

    $fields = [
      'status' => $new_status,
      'staff_user_id' => $actor_user_id ? (int)$actor_user_id : get_current_user_id(),
      'updated_at' => current_time('mysql'),
    ];
    if ($new_status === 'confirmed') $fields['confirmed_at'] = current_time('mysql');
    if ($new_status === 'ready') $fields['ready_at'] = current_time('mysql');
    if ($new_status === 'picked_up') $fields['picked_up_at'] = current_time('mysql');
    if ($new_status === 'cancelled') $fields['cancelled_at'] = current_time('mysql');

    $ok = self::db_update_reservation($reservation_id, $fields);
    if (!$ok) return false;

    // Reload for email context (fresh timestamps)
    $row = self::db_get_reservation($reservation_id);
    $items = self::db_get_reservation_items($reservation_id);
    $store_id = (int)($row['store_id'] ?? 0);
    $store_name = self::get_store_name($store_id);
    $pickup = (string)($row['pickup_at'] ?? '');
    $sum = number_format((float)($row['total_amount'] ?? 0), 2, ',', ' ') . ' ' . (string)($row['currency'] ?? 'EUR');
    $items_txt = self::reservation_items_to_text($items);

    // Notifications (minimal set, can be expanded later)
    if ($new_status === 'confirmed' && self::is_notify_enabled_for_status('confirmed')) {
      self::notify_customer((int)($row['customer_wp_user_id'] ?? 0),
        'Rezervácia potvrdená #' . $reservation_id,
        "Vaša rezervácia bola potvrdená.\n\nPrevádzka: $store_name\nVyzdvihnutie: $pickup\n\nPoložky:\n$items_txt\n\nSuma: $sum\n\nĎakujeme.",
        ['store_id' => $store_id, 'reservation_id' => $reservation_id, 'mail_type' => 'confirmed']
      );
    } elseif ($new_status === 'ready' && self::is_notify_enabled_for_status('ready')) {
      self::notify_customer((int)($row['customer_wp_user_id'] ?? 0),
        'Rezervácia pripravená #' . $reservation_id,
        "Vaša rezervácia je pripravená na vyzdvihnutie.\n\nPrevádzka: $store_name\nVyzdvihnutie: $pickup\n\nPoložky:\n$items_txt\n\nSuma: $sum\n\nĎakujeme.",
        ['store_id' => $store_id, 'reservation_id' => $reservation_id, 'mail_type' => 'ready']
      );
    } elseif ($new_status === 'cancelled' && self::is_notify_enabled_for_status('cancelled')) {
      self::notify_customer((int)($row['customer_wp_user_id'] ?? 0),
        'Rezervácia zrušená #' . $reservation_id,
        "Vaša rezervácia bola zrušená.\n\nPrevádzka: $store_name\nVyzdvihnutie: $pickup\n\nAk ide o omyl, kontaktujte nás.\n\nĎakujeme.",
        ['store_id' => $store_id, 'reservation_id' => $reservation_id, 'mail_type' => 'cancelled']
      );
    } elseif ($new_status === 'expired' && self::is_notify_enabled_for_status('expired')) {
      // Notify staff that a reservation expired (managers only)
      $subj = 'Rezervácia expirovala #' . $reservation_id . ' (' . $store_name . ')';
      $body = "Rezervácia expirovala (nevyzdvihnuté včas).\n\nPrevádzka: $store_name\nVyzdvihnutie: $pickup\nStatus: $old_status → $new_status\n\nPoložky:\n$items_txt\n\nSuma: $sum";
      self::notify_store_staff($store_id, $subj, $body, ['manager'], ['reservation_id' => $reservation_id, 'mail_type' => 'expired']);
    }

    return true;
  }
  private static function expire_overdue_reservations_for_store($store_id, $grace_hours = 2) {
    $store_id = (int)$store_id;
    $grace_hours = (int)$grace_hours;
    if ($store_id <= 0) return 0;

    global $wpdb;
    $tRes = self::table('reservations');

    $cutoff_ts = time() - max(0, $grace_hours) * 3600;
    $cutoff = date('Y-m-d H:i:s', $cutoff_ts);

    // Find overdue reservations that should expire.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT id FROM $tRes
        WHERE store_id=%d
          AND pickup_at IS NOT NULL
          AND pickup_at < %s
          AND status IN ('submitted','confirmed','ready')
        ORDER BY pickup_at ASC
        LIMIT 50",
      $store_id, $cutoff
    ));
    $n = 0;
    if (is_array($ids)) {
      foreach ($ids as $rid) {
        if (self::set_reservation_status_internal((int)$rid, 'expired', 0)) $n++;
      }
    }
    return $n;
  }

  private static function db_get_reservation($id) {
    global $wpdb;
    $t = self::table('reservations');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d LIMIT 1", (int)$id), ARRAY_A);
    return $row ?: null;
  }

  private static function db_get_reservation_items($reservation_id) {
    global $wpdb;
    $t = self::table('reservation_items');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE reservation_id=%d ORDER BY id ASC", (int)$reservation_id), ARRAY_A);
    return $rows ?: [];
  }

  private static function db_insert_reservation($res, $items) {
    global $wpdb;
    $tRes = self::table('reservations');
    $tItems = self::table('reservation_items');

    return self::with_transaction(function () use ($tRes, $tItems, $res, $items, $wpdb) {
      $ok = $wpdb->insert($tRes, $res);
      $id = (int)$wpdb->insert_id;
      if (!$ok || $id <= 0) return 0;

      foreach ($items as $it) {
        $it['reservation_id'] = $id;
        $wpdb->insert($tItems, $it);
      }
      return $id;
    });
  }

  private static function db_update_reservation($id, $fields) {
    global $wpdb;
    $t = self::table('reservations');
    return (bool)$wpdb->update($t, $fields, ['id' => (int)$id]);
  }
  public static function create_test_reservation($store_id, $pickup_at) {
    global $wpdb;
    $store_id = (int)$store_id;
    if ($store_id <= 0) return 0;

    $tRes   = self::table('reservations');
    $tItems = self::table('reservation_items');
    $tAudit = self::table('audit');

    $user_id = (int)get_current_user_id();
    if ($user_id <= 0) return 0;

    // Minimal customer upsert (ties reservation to current WP user)
    $u = get_userdata($user_id);
    $cust_id = 0;
    if ($u) {
      $cust_id = self::upsert_customer_for_wp_user($user_id, $u->display_name, $u->user_email);
    }

    $res = [
      'store_id' => $store_id,
      'customer_id' => $cust_id ?: null,
      'customer_wp_user_id' => $user_id,
      'created_by_user_id' => $user_id,
      'staff_user_id' => $user_id,
      'status' => 'submitted',
      'source' => 'staff',
      'pickup_at' => $pickup_at,
      'confidence' => 80,
      'system_flags' => wp_json_encode(['TEST']),
      'suggested_at' => current_time('mysql'),
      'total_amount' => 0.00,
      'currency' => 'EUR',
      'customer_note' => 'Test rezervácia (z adminu).',
      'internal_note' => 'Vytvorené tlačidlom Pridať test.',
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ];

    $rid = self::with_transaction(function () use ($tRes, $tItems, $tAudit, $res, $store_id, $user_id, $wpdb) {
      $ok = $wpdb->insert($tRes, $res);
      if (!$ok) return 0;
      $rid = (int)$wpdb->insert_id;
      if ($rid <= 0) return 0;

      $items = [
        [
          'reservation_id' => $rid,
          'wc_product_id' => null,
          'plu' => 'TEST-1',
          'name' => 'Test položka 1',
          'qty' => 2.000,
          'unit_price' => 1.50,
          'line_total' => 3.00,
          'note' => 'demo',
        ],
        [
          'reservation_id' => $rid,
          'wc_product_id' => null,
          'plu' => 'TEST-2',
          'name' => 'Test položka 2',
          'qty' => 1.000,
          'unit_price' => 2.20,
          'line_total' => 2.20,
          'note' => '',
        ],
      ];

      $total = 0.0;
      foreach ($items as $it) {
        $total += (float)$it['line_total'];
        $wpdb->insert($tItems, $it);
      }

      $wpdb->update($tRes, ['total_amount' => $total, 'updated_at' => current_time('mysql')], ['id' => $rid]);

      // Audit (if table exists)
      $audit_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tAudit));
      if ($audit_exists === $tAudit) {
        $wpdb->insert($tAudit, [
          'store_id' => $store_id,
          'sheet_post_id' => null,
          'actor_user_id' => $user_id,
          'action' => 'test_reservation',
          'payload' => wp_json_encode(['reservation_id' => $rid]),
          'created_at' => current_time('mysql'),
        ]);
      }

      return $rid;
    });

    return $rid;
  }

  private static function upsert_customer_for_wp_user($wp_user_id, $name, $email) {
    global $wpdb;
    $t = self::table('customers');
    $wp_user_id = (int)$wp_user_id;
    if ($wp_user_id <= 0) return 0;

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE wp_user_id=%d LIMIT 1", $wp_user_id));
    if ($existing) {
      $wpdb->update($t, [
        'name' => $name ?: null,
        'email' => $email ?: null,
        'updated_at' => current_time('mysql'),
      ], ['id' => (int)$existing]);
      return (int)$existing;
    }
    $wpdb->insert($t, [
      'wp_user_id' => $wp_user_id,
      'name' => $name ?: null,
      'email' => $email ?: null,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ]);
    return (int)$wpdb->insert_id;
  }

  private static function sanitize_reservation_payload($payload) {
    if (!is_array($payload)) return null;

    $store_id = isset($payload['store_id']) ? (int)$payload['store_id'] : 0;
    if ($store_id <= 0) return null;

    $pickup_at = isset($payload['pickup_at']) ? sanitize_text_field($payload['pickup_at']) : '';
    $pickup_dt = null;
    if ($pickup_at !== '') {
      $ts = strtotime($pickup_at);
      if ($ts) $pickup_dt = date('Y-m-d H:i:s', $ts);
    }

    $customer_note = isset($payload['customer_note']) ? wp_kses_post($payload['customer_note']) : '';
    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    if (empty($items)) return null;

    $clean_items = [];
    $total = 0.0;

    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $qty = isset($it['qty']) ? (float)$it['qty'] : 0.0;
      if ($qty <= 0) continue;

      $wc_product_id = isset($it['wc_product_id']) ? (int)$it['wc_product_id'] : 0;
      $plu = isset($it['plu']) ? sanitize_text_field($it['plu']) : null;
      $name = isset($it['name']) ? sanitize_text_field($it['name']) : '';
      if ($name === '' && $wc_product_id > 0) {
        $pp = get_post($wc_product_id);
        if ($pp) $name = $pp->post_title;
      }
      if ($name === '') $name = 'Položka';

      $unit_price = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;
      if ($unit_price <= 0 && $wc_product_id > 0 && function_exists('wc_get_product')) {
        $prod = wc_get_product($wc_product_id);
        if ($prod) $unit_price = (float)$prod->get_price();
      }

      $line_total = isset($it['line_total']) ? (float)$it['line_total'] : ($unit_price * $qty);
      $total += $line_total;

      $clean_items[] = [
        'reservation_id' => 0,
        'wc_product_id' => $wc_product_id > 0 ? $wc_product_id : null,
        'plu' => $plu ?: null,
        'name' => $name,
        'qty' => $qty,
        'unit_price' => $unit_price,
        'line_total' => $line_total,
        'note' => isset($it['note']) ? sanitize_text_field($it['note']) : null,
      ];
    }

    if (empty($clean_items)) return null;

    return [
      'store_id' => $store_id,
      'pickup_at' => $pickup_dt,
      'customer_note' => $customer_note,
      'items' => $clean_items,
      'total_amount' => $total,
    ];
  }

  private static function convert_reservation_to_order_internal($id) {
    if (!self::is_woocommerce_active()) {
      return new WP_Error('no_woo', 'WooCommerce not active');
    }
    $id = (int)$id;
    $res = self::db_get_reservation($id);
    if (!$res) return new WP_Error('not_found', 'Reservation not found');

    $items = self::db_get_reservation_items($id);
    if (empty($items)) return new WP_Error('no_items', 'No reservation items');

    $order = wc_create_order();
    if (is_wp_error($order)) return $order;

    foreach ($items as $it) {
      $pid = (int)($it['wc_product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      $line_total = (float)($it['line_total'] ?? 0);

      if ($pid > 0 && function_exists('wc_get_product')) {
        $prod = wc_get_product($pid);
        if ($prod) {
          $order->add_product($prod, $qty, [
            'subtotal' => $line_total,
            'total' => $line_total,
          ]);
          continue;
        }
      }
      $fee = new WC_Order_Item_Fee();
      $fee->set_name((string)($it['name'] ?? 'Položka'));
      $fee->set_amount($line_total);
      $fee->set_total($line_total);
      $order->add_item($fee);
    }

    $cust_uid = (int)($res['customer_wp_user_id'] ?? 0);
    if ($cust_uid > 0) $order->set_customer_id($cust_uid);

    $order->update_meta_data('_bc_reservation_id', $id);
    $order->update_meta_data('_bc_store_id', (int)$res['store_id']);
    $order->update_meta_data('_pickup_at', $res['pickup_at']);
    $order->calculate_totals();
    $order->save();

    return $order->get_id();
  }



// ===== Etapa B1: Admin create reservation (form) =====
private static function upsert_customer_manual($name, $phone, $email) {
  global $wpdb;
  $t = $wpdb->prefix . 'bc_inv_customers';

  $name = $name ? sanitize_text_field((string)$name) : '';
  $phone = $phone ? sanitize_text_field((string)$phone) : '';
  $email = $email ? sanitize_email((string)$email) : '';

  // Try match by email first, then phone.
  $existing = 0;
  if ($email !== '') {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE email=%s LIMIT 1", $email));
  }
  if ($existing <= 0 && $phone !== '') {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE phone=%s LIMIT 1", $phone));
  }

  $data = [
    'name' => $name !== '' ? $name : null,
    'phone' => $phone !== '' ? $phone : null,
    'email' => $email !== '' ? $email : null,
    'updated_at' => current_time('mysql'),
  ];

  if ($existing > 0) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->update($t, $data, ['id' => $existing]);
    return $existing;
  }

  $data['created_at'] = current_time('mysql');
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
  $wpdb->insert($t, $data);
  return (int)$wpdb->insert_id;
}

public static function create_admin_reservation($payload) {
  if (!is_array($payload)) return new WP_Error('bc_inv_invalid', 'Invalid payload');
  $store_id = isset($payload['store_id']) ? (int)$payload['store_id'] : 0;
  if ($store_id <= 0) return new WP_Error('bc_inv_invalid_store', 'Missing store_id');
  if (!self::store_exists_and_active($store_id)) return new WP_Error('bc_inv_unknown_store', 'Unknown store');

  $uid = (int)get_current_user_id();
  if ($uid <= 0) return new WP_Error('bc_inv_no_user', 'No user');

  // Access control: allow manage_options always, else require store access.
  if (!user_can($uid, 'manage_options') && !self::current_user_has_store_access($store_id)) {
    return new WP_Error('bc_inv_no_access', 'No store access');
  }

  $pickup_at = isset($payload['pickup_at']) ? sanitize_text_field((string)$payload['pickup_at']) : '';
  $pickup_dt = null;
  if ($pickup_at !== '') {
    $ts = strtotime($pickup_at);
    if ($ts) $pickup_dt = date('Y-m-d H:i:s', $ts);
  }

  // B2.1: Minimum lead time for pickup (minutes)
  $min_pick_min = (int) self::get_setting_min_pickup_minutes();
  if ($min_pick_min < 0) $min_pick_min = 0;
  if ($pickup_dt !== null && $min_pick_min > 0) {
    $pickup_ts = strtotime($pickup_dt);
    $now_ts = (int) current_time('timestamp');
    $min_ts = $now_ts + ($min_pick_min * 60);
    if ($pickup_ts !== false && $pickup_ts < $min_ts) {
      $suggest = date('Y-m-d H:i:s', $min_ts);
      return new WP_Error(
        'bc_inv_pickup_too_soon',
        'Čas vyzdvihnutia je príliš skoro. Najskorší povolený čas je: ' . $suggest
      );
    }
  }


  // B2.2a: Capacity per time interval (default 15 minutes). 0 = disabled.
  $cap_max = (int) self::get_setting_capacity_max_per_interval();
  $cap_interval = (int) self::get_setting_capacity_interval_minutes();
  if ($cap_interval <= 0) $cap_interval = 15;

  if ($pickup_dt !== null && $cap_max > 0) {
    $pickup_ts = strtotime($pickup_dt);
    if ($pickup_ts !== false) {
      // floor to interval start
      $slot_start_ts = $pickup_ts - ($pickup_ts % ($cap_interval * 60));
      $slot_end_ts = $slot_start_ts + ($cap_interval * 60);

      global $wpdb;
      $tRes = $wpdb->prefix . 'bc_inv_reservations';

      $slot_start = date('Y-m-d H:i:s', $slot_start_ts);
      $slot_end = date('Y-m-d H:i:s', $slot_end_ts);

      // count active reservations in the same slot (exclude cancelled/expired)
      $cnt = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tRes
         WHERE store_id = %d
           AND pickup_at >= %s AND pickup_at < %s
           AND status NOT IN ('cancelled','expired')",
        $store_id, $slot_start, $slot_end
      ));

      if ($cnt >= $cap_max) {
        // Suggest next free slot (scan forward)
        $suggest_ts = $slot_start_ts;
        $max_scan = 96; // up to 24h in 15-min slots (adjustable later)
        for ($k = 0; $k < $max_scan; $k++) {
          $suggest_ts += ($cap_interval * 60);
          $s_start = date('Y-m-d H:i:s', $suggest_ts);
          $s_end = date('Y-m-d H:i:s', $suggest_ts + ($cap_interval * 60));
          $s_cnt = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tRes
             WHERE store_id = %d
               AND pickup_at >= %s AND pickup_at < %s
               AND status NOT IN ('cancelled','expired')",
            $store_id, $s_start, $s_end
          ));
          if ($s_cnt < $cap_max) {
            break;
          }
        }
        $suggest = date('Y-m-d H:i:s', $suggest_ts);
        return new WP_Error(
          'bc_inv_capacity_full',
          'Kapacita pre zvolený čas je už naplnená (' . $cap_max . ' rezerv./' . $cap_interval . ' min). Navrhovaný najbližší voľný čas: ' . $suggest
        );
      }
    }
  }


  $customer_wp_user_id = isset($payload['customer_wp_user_id']) ? (int)$payload['customer_wp_user_id'] : 0;
  $customer_name = isset($payload['customer_name']) ? sanitize_text_field((string)$payload['customer_name']) : '';
  $customer_email = isset($payload['customer_email']) ? sanitize_email((string)$payload['customer_email']) : '';
  $customer_phone = isset($payload['customer_phone']) ? sanitize_text_field((string)$payload['customer_phone']) : '';

  $customer_id = 0;
  if ($customer_wp_user_id > 0) {
    $u = get_userdata($customer_wp_user_id);
    if ($u) {
      $customer_id = self::upsert_customer_for_wp_user($customer_wp_user_id, $u->display_name, $u->user_email);
      $customer_name = $customer_name !== '' ? $customer_name : (string)$u->display_name;
      $customer_email = $customer_email !== '' ? $customer_email : (string)$u->user_email;
    }
  } else {
    // For manual contact, upsert into customers table if we have at least one identifier.
    if ($customer_name !== '' || $customer_email !== '' || $customer_phone !== '') {
      $customer_id = self::upsert_customer_manual($customer_name, $customer_phone, $customer_email);
    }
  }

  $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
  if (empty($items)) return new WP_Error('bc_inv_no_items', 'No items');

  // Sanitize items similarly to REST.
  $clean_items = [];
  $total = 0.0;
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $qty = isset($it['qty']) ? (float)$it['qty'] : 0.0;
    if ($qty <= 0) continue;

    $wc_product_id = isset($it['wc_product_id']) ? (int)$it['wc_product_id'] : 0;
    $plu = isset($it['plu']) ? sanitize_text_field((string)$it['plu']) : null;
    $name = isset($it['name']) ? sanitize_text_field((string)$it['name']) : '';

    if ($name === '' && $wc_product_id > 0) {
      $pp = get_post($wc_product_id);
      if ($pp) $name = (string)$pp->post_title;
    }
    if ($name === '') $name = 'Položka';

    $unit_price = isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0;
    if ($unit_price <= 0 && $wc_product_id > 0 && function_exists('wc_get_product')) {
      $prod = wc_get_product($wc_product_id);
      if ($prod) $unit_price = (float)$prod->get_price();
    }

    $line_total = isset($it['line_total']) ? (float)$it['line_total'] : ($unit_price * $qty);
    $total += $line_total;

    $clean_items[] = [
      'reservation_id' => 0,
      'wc_product_id' => $wc_product_id > 0 ? $wc_product_id : null,
      'plu' => $plu ?: null,
      'name' => $name,
      'qty' => $qty,
      'unit_price' => $unit_price,
      'line_total' => $line_total,
      'note' => isset($it['note']) ? sanitize_text_field((string)$it['note']) : null,
    ];
  }

  if (empty($clean_items)) return new WP_Error('bc_inv_no_items', 'No valid items');

  $eval = self::evaluate_reservation([
    'pickup_at' => $pickup_dt,
    'customer_wp_user_id' => $customer_wp_user_id > 0 ? $customer_wp_user_id : 0,
    'customer_phone' => $customer_phone,
    'customer_email' => $customer_email,
  ], $clean_items);

  $res = [
    'store_id' => $store_id,
    'customer_id' => $customer_id ?: null,
    'customer_wp_user_id' => $customer_wp_user_id > 0 ? $customer_wp_user_id : null,
    'created_by_user_id' => $uid,
    'staff_user_id' => $uid,
    'status' => 'submitted',
    'source' => 'staff',
    'pickup_at' => $pickup_dt,
    'confidence' => (int)($eval['confidence'] ?? 0),
    'system_flags' => wp_json_encode($eval['flags'] ?? []),
    'suggested_at' => current_time('mysql'),
    'total_amount' => $total,
    'currency' => 'EUR',
    'customer_note' => isset($payload['customer_note']) ? wp_kses_post($payload['customer_note']) : null,
    'internal_note' => isset($payload['internal_note']) ? sanitize_textarea_field($payload['internal_note']) : null,
    'created_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
  ];

  $rid = self::db_insert_reservation($res, $clean_items);
  if ($rid <= 0) return new WP_Error('bc_inv_db', 'DB insert failed');

  // Notify staff about submitted reservation
  $store_name = self::get_store_name($store_id);
  $items_txt = self::reservation_items_to_text($clean_items);
  $sum_txt = number_format((float)$total, 2, ',', ' ') . ' EUR';
  $who = $customer_name !== '' ? $customer_name : 'Zákazník';
  $who_line = $who;
  if ($customer_email !== '') $who_line .= ' <' . $customer_email . '>';
  if ($customer_phone !== '') $who_line .= ' tel: ' . $customer_phone;

  $subj = 'Nová rezervácia #' . $rid . ' (' . $store_name . ')';
  $body = "Prišla nová rezervácia (z adminu).\n\nPrevádzka: $store_name\nVyzdvihnutie: " . ($pickup_dt ?: '-') . "\nZákazník: $who_line\n\nPoložky:\n$items_txt\n\nSuma: $sum_txt";
  if (self::is_notify_enabled_for_status('submitted')) {
    $roles = self::get_setting_notify_submitted_roles();
    self::notify_store_staff($store_id, $subj, $body, $roles, ['reservation_id' => $rid, 'mail_type' => 'submitted']);
  }

  return $rid;
}

}