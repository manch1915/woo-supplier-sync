#!/bin/sh
# Installs the demo store: WordPress, WooCommerce, Supplier Sync, three suppliers,
# price rules, a demo account and the first sync. Safe to run again.
set -eu
cd /var/www/html
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

log() { printf '\n==> %s\n' "$1"; }

log "Waiting for WordPress files and the database"
i=0
until [ -f wp-config.php ] && wp db query "SELECT 1" >/dev/null 2>&1; do
	i=$((i + 1))
	[ "$i" -gt 90 ] && { echo "WordPress is not ready"; exit 1; }
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	log "Installing WordPress"
	ADMIN_PASSWORD="${DEMO_ADMIN_PASSWORD:-$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)}"
	wp core install \
		--url="$SITE_URL" \
		--title="Northwind Supply" \
		--admin_user=admin \
		--admin_password="$ADMIN_PASSWORD" \
		--admin_email=admin@northwind.example \
		--skip-email
	echo "Admin login: admin / $ADMIN_PASSWORD"
fi

log "Plugins and theme"
wp plugin is-installed woocommerce || wp plugin install woocommerce --version=11.2.1
wp plugin activate woocommerce woo-supplier-sync
wp theme activate twentytwentyfive
wp plugin delete akismet hello >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' >/dev/null

log "Store settings"
wp option update blogdescription "Electronics and outdoor gear from three suppliers"
wp option update woocommerce_currency USD
wp option update woocommerce_price_num_decimals 2
wp option update woocommerce_default_country "US:CA"
wp option update woocommerce_store_address "410 Harbor Street"
wp option update woocommerce_store_city "Oakland"
wp option update woocommerce_store_postcode "94607"
wp option update woocommerce_coming_soon no
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_task_list_hidden yes || true
wp option update woocommerce_show_marketplace_suggestions no || true
wp option update woocommerce_allow_tracking no || true

log "Store categories used by price rules"
term_id() {
	# $1 name, $2 parent ID
	id=$(wp term list product_cat --name="$1" --parent="$2" --field=term_id --hide_empty=0 | head -n1)
	[ -n "$id" ] || id=$(wp term create product_cat "$1" --parent="$2" --porcelain)
	echo "$id"
}
AUDIO=$(term_id "Audio" 0)
POWER=$(term_id "Power & charging" 0)
OUTDOOR=$(term_id "Outdoor" 0)
CLOTHING=$(term_id "Clothing" "$OUTDOOR")

if [ "$(wp supplier-sync list --format=count 2>/dev/null || echo 0)" = "0" ]; then
	log "Supplier profiles"
	wp supplier-sync import-profiles /demo/suppliers.json
fi
NORDLINE=$(wp supplier-sync list --format=json | php -r '$s = json_decode(stream_get_contents(STDIN), true); foreach ($s as $r) { if (str_starts_with($r["name"], "Nordline")) echo $r["id"]; }')

log "Pricing"
cat > /tmp/supplier-sync-settings.json <<JSON
{
  "rates": { "RUB": 0.0124, "EUR": 1.09 },
  "default_rule": { "markup_percent": 35, "markup_fixed": 0, "min_margin_percent": 12, "rounding": "ending_99" },
  "rules": [
    { "id": "nordline-audio", "label": "Nordline audio", "supplier_id": $NORDLINE, "category_id": $AUDIO, "markup_percent": 28, "min_margin_percent": 15, "rounding": "ending_99", "priority": 10 },
    { "id": "clothing", "label": "Outdoor clothing", "category_id": $CLOTHING, "markup_percent": 45, "min_margin_percent": 20, "rounding": "whole", "priority": 10 },
    { "id": "accessories", "label": "Accessories under \$10", "max_cost": 10, "markup_percent": 80, "min_margin_percent": 30, "rounding": "ending_90_cents", "priority": 10 },
    { "id": "premium", "label": "Premium items over \$150", "min_cost": 150, "markup_percent": 22, "min_margin_percent": 15, "rounding": "whole", "priority": 10 },
    { "id": "power", "label": "Power & charging", "category_id": $POWER, "markup_percent": 40, "min_margin_percent": 15, "rounding": "ending_99", "priority": 20 }
  ],
  "stock": { "when_zero": "outofstock", "allow_on_order": true },
  "images": { "webp": true, "quality": 82, "max_width": 1200 },
  "notifications": { "email": "", "telegram_token": "", "telegram_chat": "", "only_problems": false },
  "time_budget": 20,
  "log_retention_days": 60
}
JSON
wp option update supplier_sync_settings --format=json < /tmp/supplier-sync-settings.json

log "Demo account"
wp role list --field=role | grep -qx supplier_sync_demo || wp role create supplier_sync_demo "Demo (read-only)"
wp cap add supplier_sync_demo read supplier_sync_view supplier_sync_run >/dev/null
wp user get demo >/dev/null 2>&1 || wp user create demo demo@northwind.example --role=supplier_sync_demo --user_pass="${DEMO_USER_PASSWORD:-demo}" --display_name="Demo visitor"

if [ "$(wp post list --post_type=product --format=count)" = "0" ]; then
	log "First sync with last week's catalogs"
	# Nordline starts from last week's feed. The current one has price drops, a new colour,
	# a new product and a discontinued camera, so a dry run in the demo has something to show.
	wp eval-file /demo/set-feed-url.php "Nordline" "http://feeds/nordline/catalog-week1.yml"
	wp supplier-sync run all
	wp supplier-sync import-images
	wp eval-file /demo/set-feed-url.php "Nordline" "http://feeds/nordline/catalog.yml"
fi

log "Storefront"
wp eval-file /demo/shop-template.php
SHOP_ID=$(wp option get woocommerce_shop_page_id)
wp option update show_on_front page
wp option update page_on_front "$SHOP_ID"
wp post update "$SHOP_ID" --post_title="Catalog" >/dev/null

log "Done. Store: $SITE_URL  Admin: $SITE_URL/wp-admin/admin.php?page=supplier-sync"
