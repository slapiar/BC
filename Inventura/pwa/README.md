# BC Chat PWA - Onboarding

This is the Progressive Web App for BC Chat onboarding (Phase 1).

## Files

- `index.html` - Onboarding screen with nickname input
- `manifest.json` - PWA manifest

## Features (Phase 1)

1. **Onboarding Screen**
   - Nickname input (2-32 characters)
   - "Zadaj svoj nickname a Pripoj sa" button
   - Device ID generation and storage

2. **Waiting for Approval**
   - Display approval code (6-character readable code)
   - Instructions to call admin with nickname + code
   - Auto-polling device status every 5 seconds

3. **Active Status**
   - Confirmation when approved
   - Ready to redirect to main chat

## Integration

The PWA communicates with WordPress REST API:

- `POST /wp-json/bc-inventura/v1/auth/request-access` - Submit access request
- `GET /wp-json/bc-inventura/v1/auth/device-status` - Check device status

## Deployment

1. Serve these files from your WordPress site (e.g., via a custom page or subdomain)
2. Update `API_BASE_URL` in `index.html` if needed
3. Add icons (icon-192.png, icon-512.png) for PWA installation

## Local Testing

Open `index.html` in a browser. For full PWA features, serve via HTTPS.
