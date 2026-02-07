# Phase 1 Implementation - Testing Guide

## Overview
This guide covers testing the Phase 1 implementation of the chat access request system.

## Prerequisites
- WordPress installation with BC Inventúra plugin active
- Admin access to WordPress
- Modern web browser for testing PWA

## Testing Steps

### 1. Database Schema Verification

After activating/updating the plugin, verify tables were created:

```sql
-- Check if tables exist
SHOW TABLES LIKE 'wp_bc_inv_devices';
SHOW TABLES LIKE 'wp_bc_inv_access_requests';
SHOW TABLES LIKE 'wp_bc_inv_chat_rooms';

-- Check table structure
DESCRIBE wp_bc_inv_devices;
DESCRIBE wp_bc_inv_access_requests;
DESCRIBE wp_bc_inv_chat_rooms;
```

### 2. REST API Testing

#### Test Request Access Endpoint

```bash
# Test with valid data
curl -X POST "https://your-site.com/wp-json/bc-inventura/v1/auth/request-access" \
  -H "Content-Type: application/json" \
  -H "X-Device-Id: test_device_123" \
  -d '{
    "nickname": "TestUser",
    "phone_hint": "+421900000000",
    "client_info": {
      "platform": "Linux",
      "app_version": "1.0.0"
    }
  }'

# Expected response:
# {
#   "ok": true,
#   "approval_code": "ABC123",
#   "status": "pending",
#   "message": "Access request submitted..."
# }

# Test with invalid nickname (too short)
curl -X POST "https://your-site.com/wp-json/bc-inventura/v1/auth/request-access" \
  -H "Content-Type: application/json" \
  -H "X-Device-Id: test_device_456" \
  -d '{"nickname": "X"}'

# Expected error response

# Test without device_id
curl -X POST "https://your-site.com/wp-json/bc-inventura/v1/auth/request-access" \
  -H "Content-Type: application/json" \
  -d '{"nickname": "TestUser"}'

# Expected error response
```

#### Test Device Status Endpoint

```bash
# Check status of device
curl "https://your-site.com/wp-json/bc-inventura/v1/auth/device-status" \
  -H "X-Device-Id: test_device_123"

# Expected response (for pending):
# {
#   "ok": true,
#   "status": "pending",
#   "active": false,
#   "user_id": null
# }

# Expected response (for active):
# {
#   "ok": true,
#   "status": "active",
#   "active": true,
#   "user_id": 123
# }
```

### 3. Admin UI Testing

1. **Access Admin Queue**
   - Login to WordPress admin
   - Navigate to "Inventúra" → "Chat → Žiadosti"
   - Verify page loads without errors

2. **View Pending Requests**
   - Check if submitted test request appears in the table
   - Verify all fields display correctly:
     - ID
     - Created timestamp
     - Nickname
     - Device ID (shortened)
     - Approval code
     - Status
     - Expiration time

3. **Test Filters**
   - Click on different status filters (Pending, Waiting, Approved, Denied, Expired, All)
   - Verify filtering works correctly

4. **Test Actions**

   **Approve Action:**
   - Click "Approve" button on a pending request
   - Verify success message appears
   - Check that:
     - Device status changed to 'active'
     - WP user was created
     - Personal room was created
     - Audit log entry was created

   **Deny Action:**
   - Submit a new test request
   - Click "Deny" button
   - Verify request status changes to 'denied'
   - Check audit log

   **Wait Action:**
   - Submit a new test request
   - Click "Wait" button
   - Verify request status changes to 'waiting'
   - Check audit log

   **Expire Action:**
   - Click "Expire" button on a request
   - Verify request status changes to 'expired'
   - Check audit log

### 4. PWA Testing

1. **Open Onboarding Screen**
   - Navigate to `/Inventura/pwa/index.html` (or wherever deployed)
   - Verify page loads correctly with proper styling

2. **Test Form Validation**
   - Try submitting empty form → should show validation error
   - Try nickname with 1 character → should show validation error
   - Try nickname with 33+ characters → should show validation error
   - Enter valid nickname (2-32 chars) → character counter should update

3. **Submit Access Request**
   - Enter valid nickname (e.g., "TestUser")
   - Click "Zadaj svoj nickname a Pripoj sa"
   - Verify:
     - Form disabled during submission
     - Waiting screen appears
     - Approval code is displayed
     - Instructions show nickname and code
     - Status polling spinner appears

4. **Test Status Polling**
   - Leave PWA open on waiting screen
   - Go to admin and approve the request
   - Within 5 seconds, PWA should:
     - Detect the approval
     - Show active screen
     - Redirect to chat (or show ready message)

5. **Test Persistence**
   - Refresh the page while on waiting screen
   - Verify approval code and nickname are restored from localStorage
   - Waiting screen should appear again with same data

### 5. Database Verification

After completing flows, verify database state:

```sql
-- Check devices table
SELECT * FROM wp_bc_inv_devices;

-- Check access requests
SELECT * FROM wp_bc_inv_access_requests ORDER BY created_at DESC;

-- Check personal rooms created
SELECT * FROM wp_bc_inv_chat_rooms WHERE type='personal';

-- Check audit logs
SELECT * FROM wp_bc_inv_audit WHERE action LIKE 'chat_access_%' ORDER BY created_at DESC;

-- Check created users
SELECT * FROM wp_users WHERE user_login LIKE 'testuser%';
```

### 6. Edge Cases to Test

1. **Duplicate Device Request**
   - Submit request with same device_id twice
   - Should return existing approval code

2. **Already Active Device**
   - Approve a device
   - Try submitting new request with same device_id
   - Should reject with "device_already_active" error

3. **Expired Request**
   - Create request with expires_at in the past (manually in DB)
   - Verify admin UI shows "Expired" indicator
   - Verify auto-expiration logic if implemented

4. **Long Nickname**
   - Test with exactly 32 characters → should work
   - Test with 33 characters → should reject

5. **Special Characters in Nickname**
   - Test with unicode characters (ľščťžýáíé)
   - Should handle correctly

### 7. Security Testing

1. **REST Endpoint Access**
   - Verify `/auth/request-access` is publicly accessible (no login required)
   - Verify `/auth/device-status` is publicly accessible
   - Verify admin actions require admin login

2. **SQL Injection Prevention**
   - Try injecting SQL in nickname field
   - Verify proper sanitization

3. **XSS Prevention**
   - Try injecting JavaScript in nickname
   - Verify proper escaping in admin UI

### 8. Performance Testing

1. **Load Testing**
   - Create 100+ test requests
   - Verify admin page loads reasonably fast
   - Check if pagination is needed

2. **Polling Impact**
   - Open multiple PWA tabs
   - Verify server can handle concurrent polling
   - Monitor server load

## Expected Results

### Success Criteria

✅ All database tables created correctly  
✅ REST endpoints respond as expected  
✅ Admin UI displays requests correctly  
✅ All actions (Approve/Deny/Wait/Expire) work  
✅ Users created automatically on approval  
✅ Personal rooms created for approved users  
✅ Audit logs captured for all actions  
✅ PWA displays onboarding correctly  
✅ PWA polls and detects approval  
✅ Form validation works correctly  
✅ Device ID persistence works  
✅ No PHP errors in error log  
✅ No JavaScript console errors  

### Known Limitations (Phase 1)

- No email notifications (future phase)
- No automated expiration cron job (manual expire only)
- No bulk actions in admin UI
- No request detail view
- No admin notes on requests
- No phone number verification
- No duplicate detection by phone/email
- Chat redirect is placeholder (actual chat UI in future phase)

## Troubleshooting

### Common Issues

**Database tables not created:**
- Check plugin activation
- Manually run: `BC_Inventura::activate();`
- Check WordPress error log

**REST endpoints return 404:**
- Flush permalinks (Settings → Permalinks → Save)
- Check if plugin is active
- Verify REST_NS constant matches

**Admin page not showing:**
- Verify user has `manage_options` capability
- Check for JavaScript errors
- Clear browser cache

**PWA not connecting:**
- Update `API_BASE_URL` in index.html
- Check CORS settings if on different domain
- Verify SSL certificate if using HTTPS

## Next Phase Items

Items explicitly out of scope for Phase 1:
- Badge system and "pre mňa" indicators
- Task management system
- Conversation closure
- Speech-to-text
- Disconnect/Remove user actions
- Advanced filtering and search
- Email/SMS notifications
