// Builds the static "Volta Gadgets" supplier site that the scraper profile crawls.
// Run: node demo/tools/build-volta-site.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'feeds', 'volta' );

const products = [
	{ slug: 'gan-65', sku: 'VLT-CH65', ean: '4607001120016', name: 'Volta GaN charger 65 W', category: [ 'Power', 'Chargers' ], price: 27.5, was: null, stock: 25, image: 'gan-65.jpg', text: 'Two USB-C ports, 65 W shared. Folding prongs, fits in a coat pocket.', specs: { Power: '65 W', Ports: '2 × USB-C', Weight: '112 g' } },
	{ slug: 'gan-100', sku: 'VLT-CH100', ean: '4607001120085', name: 'Volta GaN charger 100 W', category: [ 'Power', 'Chargers' ], price: 39, was: 59, stock: 6, image: 'gan-100.jpg', text: 'Three USB-C ports and one USB-A. Charges a 16-inch laptop at full speed.', specs: { Power: '100 W', Ports: '3 × USB-C, 1 × USB-A', Weight: '205 g' } },
	{ slug: 'power-bank-20000', sku: 'VLT-PB20', ean: '4607001120023', name: 'Volta Power Bank 20000 mAh PD', category: [ 'Power', 'Power banks' ], price: 34.9, was: 49, stock: 14, image: 'powerbank-20k.jpg', text: 'Charges a laptop over USB-C Power Delivery and two more devices at once.', specs: { Capacity: '20000 mAh', Power: '45 W', Weight: '410 g' } },
	{ slug: 'power-bank-10000', sku: 'VLT-PB10', ean: '4607001120030', name: 'Volta Power Bank 10000 mAh', category: [ 'Power', 'Power banks' ], price: 22, was: null, stock: 30, image: 'powerbank-10k.jpg', text: 'Slim power bank that fits a jeans pocket. Two full phone charges.', specs: { Capacity: '10000 mAh', Power: '20 W', Weight: '210 g' } },
	{ slug: 'cable-c2c-2m', sku: 'VLT-CBL2', ean: '4607001120047', name: 'Volta braided USB-C cable 2 m', category: [ 'Power', 'Cables' ], price: 6.1, was: null, stock: 200, image: 'cable-2m.jpg', text: 'Nylon-braided USB-C to USB-C cable rated for 100 W.', specs: { Length: '2 m', Power: '100 W' } },
	{ slug: 'wireless-pad-15', sku: 'VLT-WP15', ean: '4607001120054', name: 'Volta wireless charging pad 15 W', category: [ 'Power', 'Wireless' ], price: 15.4, was: null, stock: 33, image: 'wireless-pad.jpg', text: 'Qi2 pad with a soft-touch top and a status light that dims at night.', specs: { Power: '15 W', Standard: 'Qi2', Weight: '96 g' } },
	{ slug: 'car-charger-45', sku: 'VLT-CC45', ean: '4607001120061', name: 'Volta car charger 45 W', category: [ 'Power', 'Chargers' ], price: 12.8, was: null, stock: 0, image: 'car-charger.jpg', text: 'USB-C plus USB-A in the cigarette lighter socket. Sits flush with the dashboard.', specs: { Power: '45 W', Ports: '1 × USB-C, 1 × USB-A' } },
	{ slug: 'travel-adapter', sku: 'VLT-TA1', ean: '4607001120078', name: 'Volta universal travel adapter', category: [ 'Power', 'Adapters' ], price: 17.6, was: null, stock: 9, image: 'travel-adapter.jpg', text: 'Works in 150 countries. Sliding plugs for EU, UK, US and AU sockets plus two USB-C ports.', specs: { Power: '30 W', Ports: '2 × USB-C', Weight: '150 g' } },
];

const pageSize = 5;
const money = ( v ) => `$${ v.toFixed( 2 ) }`;
const esc = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( /"/g, '&quot;' );

const layout = ( title, body ) => `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${ esc( title ) } · Volta Gadgets wholesale</title>
<link rel="stylesheet" href="/volta/style.css">
</head>
<body>
<header class="top"><a class="logo" href="/volta/catalog/">Volta<span>Gadgets</span></a><span class="tag">Wholesale catalog · prices excl. VAT</span></header>
<main>${ body }</main>
<footer class="foot">Demo supplier for Supplier Sync. Made-up products and prices.</footer>
</body>
</html>
`;

const card = ( p ) => `<li class="card">
  <a class="card__link" href="/volta/p/${ p.slug }.html">
    <img src="/volta/img/${ p.image }" alt="" width="300" height="300" loading="lazy">
    <span class="card__name">${ esc( p.name ) }</span>
  </a>
  <span class="card__price">${ money( p.price ) }</span>
</li>`;

const write = ( path, html ) => {
	mkdirSync( dirname( path ), { recursive: true } );
	writeFileSync( path, html );
};

const pages = Math.ceil( products.length / pageSize );
for ( let page = 1; page <= pages; page++ ) {
	const slice = products.slice( ( page - 1 ) * pageSize, page * pageSize );
	const next = page < pages ? `<a class="pager__next" rel="next" href="/volta/catalog/page-${ page + 1 }.html">Next page</a>` : '';
	const prev = page > 1 ? `<a class="pager__prev" rel="prev" href="/volta/catalog/${ page === 2 ? '' : `page-${ page - 1 }.html` }">Previous</a>` : '';
	write(
		join( root, 'catalog', page === 1 ? 'index.html' : `page-${ page }.html` ),
		layout( 'Catalog', `<h1>Power &amp; charging</h1><ul class="grid">${ slice.map( card ).join( '\n' ) }</ul><nav class="pager">${ prev }${ next }</nav>` )
	);
}

for ( const p of products ) {
	const stock = p.stock > 0 ? `<p class="stock stock--in">In stock: ${ p.stock } pcs</p>` : '<p class="stock stock--out">Out of stock</p>';
	const was = p.was ? ` <s class="price__was">${ money( p.was ) }</s>` : '';
	const specs = Object.entries( p.specs ).map( ( [ k, v ] ) => `<tr><th>${ esc( k ) }</th><td>${ esc( v ) }</td></tr>` ).join( '' );
	write(
		join( root, 'p', `${ p.slug }.html` ),
		layout(
			p.name,
			`<nav class="breadcrumbs"><a href="/volta/catalog/">Catalog</a>${ p.category.map( ( c, i ) => ( i === p.category.length - 1 ? `<span>${ esc( c ) }</span>` : `<a href="/volta/catalog/">${ esc( c ) }</a>` ) ).join( '' ) }</nav>
<article class="product" data-sku="${ p.sku }">
  <div class="gallery"><img src="/volta/img/${ p.image }" alt="${ esc( p.name ) }" width="600" height="600"></div>
  <div class="info">
    <h1 class="product__title">${ esc( p.name ) }</h1>
    <p class="sku">SKU ${ p.sku } · EAN <span itemprop="gtin13">${ p.ean }</span></p>
    <div class="price"><span class="price__now">${ money( p.price ) }</span>${ was }</div>
    ${ stock }
    <div class="description"><p>${ esc( p.text ) }</p></div>
    <table class="specs">${ specs }</table>
  </div>
</article>`
		)
	);
}

write( join( root, 'index.html' ), '<!doctype html><meta http-equiv="refresh" content="0; url=/volta/catalog/">' );
write( join( root, '..', 'robots.txt' ), 'User-agent: *\nDisallow: /volta/account/\n' );
write(
	join( root, 'style.css' ),
	`*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;color:#1d232b;background:#f4f5f2}
.top{display:flex;gap:16px;align-items:baseline;flex-wrap:wrap;padding:18px 24px;background:#10151c;color:#e9edf1}
.logo{font-weight:800;font-size:22px;color:#ffd23f;text-decoration:none}.logo span{color:#e9edf1;font-weight:400;margin-left:4px}
.tag{font-size:13px;opacity:.7}main{max-width:1080px;margin:0 auto;padding:24px}
.grid{list-style:none;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
.card{background:#fff;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px}
.card img{width:100%;height:auto;border-radius:6px;background:#eee}.card__link{color:inherit;text-decoration:none;display:grid;gap:8px}
.card__price{font-weight:700}.pager{display:flex;justify-content:space-between;margin-top:24px}
.breadcrumbs{display:flex;gap:8px;font-size:13px;margin-bottom:16px}.breadcrumbs>*+*:before{content:"/";margin-right:8px;opacity:.4}
.product{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:32px;background:#fff;border-radius:12px;padding:24px}
.gallery img{width:100%;height:auto;border-radius:8px}.price__now{font-size:28px;font-weight:800}.price__was{margin-left:8px;opacity:.5}
.stock--in{color:#16794a}.stock--out{color:#b42318}.specs{border-collapse:collapse;width:100%}.specs th,.specs td{text-align:left;padding:6px 0;border-bottom:1px solid #eee}
.foot{text-align:center;font-size:12px;opacity:.6;padding:24px}`
);

console.log( `Volta site: ${ products.length } products, ${ pages } listing pages.` );
