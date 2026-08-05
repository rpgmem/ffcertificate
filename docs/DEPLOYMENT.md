# Deployment notes

Operational hardening notes for self-hosted Free Form Certificate installs.
These are **deploy-time server config**, not plugin settings — the plugin
cannot apply them for you.

## Protecting the CSV export temp directory on nginx

Batched CSV exports (submissions, appointments, reregistration, public forms,
url-shortener, activity-log, audience-bookings) stage a **temporary file that
may contain decrypted PII** under:

```
wp-content/uploads/ffc-tmp/
```

The file is short-lived (unlinked right after download, and any abandoned job is
reclaimed by a daily cleanup cron), has a random-UUID name, and the plugin drops
an Apache `.htaccess` with `Deny from all` into that directory automatically.

**`.htaccess` is Apache-only.** On **nginx** it is ignored, so the directory
would be web-reachable while a job is mid-flight. Deny it at the server block:

```nginx
# Block direct web access to the FFC export temp dir (holds decrypted PII
# for the lifetime of an export job). Apache uses the plugin's .htaccess;
# nginx needs this explicit rule.
location ^~ /wp-content/uploads/ffc-tmp/ {
    deny all;
    return 404;
}
```

Add it inside the `server { … }` block for the site and reload nginx
(`nginx -t && systemctl reload nginx`). Equivalent hardening: keep
`wp-content/uploads` non-executable and ensure that path is unreadable over HTTP.

If you cannot edit the server block (managed hosting), prefer keeping the
audit-log export — which is **synchronous and never touches disk** — and confirm
with your host that `wp-content/uploads/ffc-tmp/` is not directly served.

> The same directory on **Apache** needs no action: the bundled `.htaccess`
> already denies it.
