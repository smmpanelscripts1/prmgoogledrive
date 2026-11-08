# PRM Google Drive Backup

Blueprint extension for Pterodactyl / Blueprint.

Connect an **admin Google account**, grant Drive permission, then back up servers to Google Drive automatically.

## Features

- Google OAuth (“yetki ver”) for the admin Drive account
- Backup modes:
  - **Wings archive** (recommended): native panel backup → upload `.tar.gz` to Drive
  - **ZIP**: pack `/peto-volumes/{uuid}` with an ignore list (useful to skip huge `game/` trees)
- Manual run + schedule (hourly / twice daily / daily) via cron URL
- Per-server selection or all servers
- Activity log on the admin page

## Version

`1.0.0`

## Auth UX

End users do **not** open Google Cloud Console. They only click **Google ile bağlan / yetki ver** and grant Drive access to their own account.

Google still requires **one** OAuth client for the extension. The publisher sets it once:

- `PRM_GDRIVE_CLIENT_ID`
- `PRM_GDRIVE_CLIENT_SECRET`

Redirect URI to whitelist (once):

`http://localhost:8080/extensions/prmgoogledrive/oauth/callback`

(or your panel URL + `/extensions/prmgoogledrive/oauth/callback`)

Optional: put the same values into `lib/AppCredentials.php`.

## Panel mount (Wings mode)

```yaml
- D:/PETO-FILES/backups:/peto-backups:ro
```

Then recreate the panel container.

## Cron

Use the cron URL from the admin page with Task Scheduler / curl every few minutes.
The extension only runs when the chosen schedule is due.

## Install

```bash
blueprint -install prmgoogledrive
```
