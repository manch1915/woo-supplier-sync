# Running the demo on a server

The same `compose.yaml` runs the public demo. The server only needs Docker with the Compose plugin and a reverse proxy in front for HTTPS. The steps below assume Caddy is already serving other sites on the box.

## 1. Code and settings

```bash
git clone https://github.com/manch1915/woo-supplier-sync.git /opt/woo-supplier-sync
cd /opt/woo-supplier-sync
cat > .env <<'EOF'
SITE_URL=https://supplier-sync.example.com
WP_PORT=127.0.0.1:8090
FEEDS_PORT=127.0.0.1:8091
WP_ENVIRONMENT_TYPE=staging
DB_PASSWORD=change-me
DEMO_ADMIN_PASSWORD=change-me-too
DEMO_USER_PASSWORD=demo
DEMO_LOGIN_HINT=demo / demo
EOF
chmod 600 .env
```

Binding the ports to `127.0.0.1` keeps WordPress and the fake supplier site off the public interface; only Caddy talks to them.

## 2. Start

```bash
docker compose up -d
docker compose logs -f setup   # first start: assets, WooCommerce, first sync, photos
```

## 3. Caddy

Add a block, check the config, reload. Do not recreate the Caddy container if other production sites run behind it.

```
supplier-sync.example.com {
	encode zstd gzip
	reverse_proxy 127.0.0.1:8090 {
		header_up X-Forwarded-Proto {scheme}
	}
}
```

`compose.yaml` already tells WordPress to trust `X-Forwarded-Proto: https`, so links and cookies use HTTPS behind the proxy.

If Caddy runs in its own container, point `reverse_proxy` at the host gateway or put both stacks on a shared Docker network instead of `127.0.0.1`.

## 4. Nightly reset

Visitors can run syncs, so the demo drifts. A cron entry brings it back to the initial state every night:

```
30 3 * * * cd /opt/woo-supplier-sync && docker compose down -v && docker compose up -d
```

## Resources

The stack takes about 600 MB of RAM at rest (MariaDB, Apache with PHP, the worker, nginx). The worker runs Action Scheduler every few seconds; on a real store a system cron entry is enough:

```
* * * * * wp --path=/var/www/html action-scheduler run --quiet
```
