# Running the demo on a server

The same `compose.yaml` runs the public demo, with `compose.prod.yaml` on top. The server needs Docker with the Compose plugin and a reverse proxy for HTTPS. The steps below assume Caddy runs in a container on a Docker network (`infra_default` here) and already serves other sites.

## 1. Code and settings

```bash
git clone https://github.com/manch1915/woo-supplier-sync.git /opt/woo-supplier-sync
cd /opt/woo-supplier-sync
cat > .env <<EOF
SITE_URL=https://supplier-sync.example.com
WP_ENVIRONMENT_TYPE=staging
PROXY_NETWORK=infra_default
DB_PASSWORD=$(openssl rand -hex 16)
DEMO_ADMIN_PASSWORD=$(openssl rand -hex 12)
DEMO_USER_PASSWORD=demo
DEMO_LOGIN_HINT=demo / demo
EOF
chmod 600 .env
```

`compose.prod.yaml` publishes no ports on the host. WordPress joins the proxy network and the proxy reaches it as `supplier-sync-wordpress-1:80`. The made-up supplier site stays on the private network of the stack.

## 2. Start

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
docker compose logs -f setup   # first start: assets, WooCommerce, first sync, photos
```

## 3. Caddy

Add a block, check the config, reload. Do not recreate the Caddy container if other production sites run behind it.

```
supplier-sync.example.com {
	encode zstd gzip
	reverse_proxy supplier-sync-wordpress-1:80 {
		header_up X-Forwarded-Proto {scheme}
	}
}
```

`compose.yaml` tells WordPress to trust `X-Forwarded-Proto: https`, so links and cookies use HTTPS behind the proxy.

## 4. Nightly reset

Visitors can run syncs, so the demo drifts. A cron entry brings it back to the initial state every night:

```
30 3 * * * cd /opt/woo-supplier-sync && docker compose -f compose.yaml -f compose.prod.yaml down -v && docker compose -f compose.yaml -f compose.prod.yaml up -d
```

## Resources

The stack takes about 600 MB of RAM at rest (MariaDB, Apache with PHP, the worker, nginx). The worker runs Action Scheduler every 15 seconds; on a real store a system cron entry is enough:

```
* * * * * wp --path=/var/www/html action-scheduler run --quiet
```
