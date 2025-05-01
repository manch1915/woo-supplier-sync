// Draws the product photos of the made-up suppliers as flat studio illustrations
// and renders them to JPEG. Run: npm install && npm run images (inside demo/tools).
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const feeds = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'feeds' );
const S = 1200;

// Shading helpers: every object gets a lighter top and a darker bottom of its own colour.
const hex = ( c ) => c.replace( '#', '' );
const mix = ( a, b, t ) => {
	const pa = hex( a ).match( /.{2}/g ).map( ( x ) => parseInt( x, 16 ) );
	const pb = hex( b ).match( /.{2}/g ).map( ( x ) => parseInt( x, 16 ) );
	return '#' + pa.map( ( v, i ) => Math.round( v + ( pb[ i ] - v ) * t ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
};
const light = ( c, t = 0.28 ) => mix( c, '#ffffff', t );
const dark = ( c, t = 0.28 ) => mix( c, '#000000', t );

let gid = 0;
const grad = ( c, angle = 90 ) => {
	const id = `g${ ++gid }`;
	const vertical = angle === 90;
	return {
		id,
		def: `<linearGradient id="${ id }" x1="0" y1="0" x2="${ vertical ? 0 : 1 }" y2="${ vertical ? 1 : 0 }"><stop offset="0" stop-color="${ light( c ) }"/><stop offset="1" stop-color="${ dark( c, 0.18 ) }"/></linearGradient>`,
		url: `url(#${ id })`,
	};
};

function scene( body, defs = [], bg = '#eef0ec' ) {
	return `<svg xmlns="http://www.w3.org/2000/svg" width="${ S }" height="${ S }" viewBox="0 0 ${ S } ${ S }">
<defs>
<radialGradient id="bg" cx="0.5" cy="0.38" r="0.75"><stop offset="0" stop-color="${ light( bg, 0.55 ) }"/><stop offset="1" stop-color="${ dark( bg, 0.06 ) }"/></radialGradient>
<radialGradient id="shadow" cx="0.5" cy="0.5" r="0.5"><stop offset="0" stop-color="#000" stop-opacity="0.22"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
${ defs.join( '\n' ) }
</defs>
<rect width="${ S }" height="${ S }" fill="url(#bg)"/>
<ellipse cx="600" cy="1010" rx="380" ry="46" fill="url(#shadow)"/>
${ body }
</svg>`;
}

const drawings = {
	headphones( c ) {
		const g = grad( c );
		return scene(
			`<path d="M330 640 C330 330 870 330 870 640" fill="none" stroke="${ dark( c, 0.1 ) }" stroke-width="54" stroke-linecap="round"/>
			<path d="M350 600 C360 380 840 380 850 600" fill="none" stroke="${ light( c, 0.15 ) }" stroke-width="14" stroke-linecap="round" opacity="0.6"/>
			<rect x="300" y="560" width="90" height="120" rx="20" fill="${ dark( c, 0.25 ) }"/>
			<rect x="810" y="560" width="90" height="120" rx="20" fill="${ dark( c, 0.25 ) }"/>
			<rect x="230" y="600" width="210" height="330" rx="100" fill="${ g.url }"/>
			<rect x="760" y="600" width="210" height="330" rx="100" fill="${ g.url }"/>
			<rect x="420" y="640" width="46" height="250" rx="23" fill="${ dark( c, 0.45 ) }"/>
			<rect x="734" y="640" width="46" height="250" rx="23" fill="${ dark( c, 0.45 ) }"/>
			<ellipse cx="335" cy="700" rx="40" ry="16" fill="#fff" opacity="0.25"/>
			<ellipse cx="865" cy="700" rx="40" ry="16" fill="#fff" opacity="0.25"/>`,
			[ g.def ]
		);
	},
	headphonesCase() {
		const g = grad( '#3a3f45' );
		return scene(
			`<ellipse cx="600" cy="640" rx="330" ry="300" fill="${ g.url }"/>
			<path d="M300 640 C300 820 900 820 900 640" fill="none" stroke="#1b1e22" stroke-width="10" stroke-dasharray="18 10"/>
			<rect x="860" y="620" width="70" height="34" rx="10" fill="#c9ccd0"/>
			<ellipse cx="520" cy="470" rx="130" ry="40" fill="#fff" opacity="0.12"/>`,
			[ g.def ]
		);
	},
	earbuds( c ) {
		const g = grad( c );
		return scene(
			`<rect x="390" y="520" width="420" height="380" rx="150" fill="${ g.url }"/>
			<path d="M390 660 H810" stroke="${ dark( c, 0.25 ) }" stroke-width="8"/>
			<circle cx="600" cy="790" r="9" fill="#5fd38d"/>
			<g transform="translate(330 330) rotate(-18)"><circle cx="0" cy="0" r="70" fill="${ g.url }"/><rect x="-22" y="40" width="44" height="170" rx="22" fill="${ g.url }"/><circle cx="-20" cy="-18" r="26" fill="${ dark( c, 0.5 ) }"/></g>
			<g transform="translate(860 330) rotate(18)"><circle cx="0" cy="0" r="70" fill="${ g.url }"/><rect x="-22" y="40" width="44" height="170" rx="22" fill="${ g.url }"/><circle cx="20" cy="-18" r="26" fill="${ dark( c, 0.5 ) }"/></g>`,
			[ g.def ]
		);
	},
	speakerMini( c ) {
		const g = grad( c );
		let dots = '';
		for ( let y = 470; y <= 840; y += 34 ) {
			for ( let x = 450; x <= 750; x += 34 ) {
				dots += `<circle cx="${ x }" cy="${ y }" r="7" fill="${ dark( c, 0.4 ) }"/>`;
			}
		}
		return scene(
			`<path d="M700 330 C820 300 860 400 760 430" fill="none" stroke="${ dark( c, 0.3 ) }" stroke-width="22" stroke-linecap="round"/>
			<rect x="390" y="380" width="420" height="600" rx="190" fill="${ g.url }"/>
			<clipPath id="grille"><rect x="430" y="440" width="340" height="440" rx="150"/></clipPath>
			<g clip-path="url(#grille)">${ dots }</g>
			<rect x="555" y="905" width="90" height="24" rx="12" fill="${ dark( c, 0.45 ) }"/>`,
			[ g.def ]
		);
	},
	speakerMax() {
		const g = grad( '#2b3036' );
		return scene(
			`<rect x="420" y="250" width="360" height="90" rx="40" fill="none" stroke="#1d2125" stroke-width="40"/>
			<rect x="300" y="320" width="600" height="660" rx="60" fill="${ g.url }"/>
			<circle cx="600" cy="520" r="150" fill="#1a1d21"/><circle cx="600" cy="520" r="90" fill="#33383e"/><circle cx="600" cy="520" r="30" fill="#4a5058"/>
			<circle cx="600" cy="820" r="110" fill="#1a1d21"/><circle cx="600" cy="820" r="60" fill="#33383e"/>
			<path d="M330 360 H870" stroke="#ff6a3d" stroke-width="10" stroke-linecap="round" opacity="0.85"/>`,
			[ g.def ]
		);
	},
	charger( watts ) {
		const big = watts > 70;
		const g = grad( '#f4f5f6' );
		const h = big ? 440 : 360;
		const ports = big ? [ 470, 560, 650 ] : [ 520, 610 ];
		return scene(
			`<rect x="${ 600 - 170 }" y="${ 330 - ( h - 360 ) }" width="340" height="40" rx="10" fill="#9aa0a6"/>
			<rect x="470" y="${ 250 - ( h - 360 ) }" width="34" height="110" rx="8" fill="#b9bec4"/><rect x="696" y="${ 250 - ( h - 360 ) }" width="34" height="110" rx="8" fill="#b9bec4"/>
			<rect x="400" y="${ 360 - ( h - 360 ) }" width="400" height="${ h + 260 }" rx="70" fill="${ g.url }" stroke="#d7dade" stroke-width="4"/>
			${ ports.map( ( y ) => `<rect x="560" y="${ y + 250 }" width="80" height="26" rx="13" fill="#25292e"/>` ).join( '' ) }
			<text x="600" y="${ 520 }" text-anchor="middle" font-family="Arial, sans-serif" font-weight="700" font-size="54" fill="#22262b">${ watts }W</text>`,
			[ g.def ]
		);
	},
	powerbank( big ) {
		const g = grad( '#33393f' );
		const h = big ? 620 : 480;
		return scene(
			`<rect x="410" y="${ 970 - h }" width="380" height="${ h }" rx="56" fill="${ g.url }"/>
			<rect x="430" y="${ 990 - h }" width="340" height="16" rx="8" fill="#fff" opacity="0.12"/>
			${ [ 0, 1, 2, 3 ].map( ( i ) => `<circle cx="${ 540 + i * 40 }" cy="${ 970 - h + 90 }" r="9" fill="${ i < 3 ? '#5fd38d' : '#555b62' }"/>` ).join( '' ) }
			<text x="600" y="${ 970 - h / 2 + 30 }" text-anchor="middle" font-family="Arial, sans-serif" font-weight="700" font-size="${ big ? 64 : 56 }" fill="#e8eaec">${ big ? '20 000' : '10 000' }</text>
			<text x="600" y="${ 970 - h / 2 + 90 }" text-anchor="middle" font-family="Arial, sans-serif" font-size="34" fill="#9aa0a6">mAh</text>`,
			[ g.def ]
		);
	},
	cable() {
		const loops = [ 0, 1, 2, 3 ].map( ( i ) => `<ellipse cx="600" cy="${ 640 + i * 8 }" rx="${ 260 - i * 28 }" ry="${ 170 - i * 18 }" fill="none" stroke="#1f2a36" stroke-width="30"/><ellipse cx="600" cy="${ 640 + i * 8 }" rx="${ 260 - i * 28 }" ry="${ 170 - i * 18 }" fill="none" stroke="#4a6a8a" stroke-width="30" stroke-dasharray="6 10"/>` ).join( '' );
		return scene(
			`${ loops }
			<path d="M840 650 C900 760 880 860 800 900" fill="none" stroke="#1f2a36" stroke-width="30"/>
			<rect x="740" y="880" width="120" height="60" rx="16" fill="#c7ccd2"/><rect x="700" y="895" width="50" height="30" rx="10" fill="#8f969e"/>
			<rect x="300" y="520" width="60" height="120" rx="16" fill="#c7ccd2" transform="rotate(-30 330 580)"/>`
		);
	},
	lamp() {
		return scene(
			`<ellipse cx="560" cy="960" rx="190" ry="40" fill="#30353b"/>
			<path d="M560 950 L470 620 L760 400" fill="none" stroke="#3a4047" stroke-width="34" stroke-linecap="round" stroke-linejoin="round"/>
			<circle cx="470" cy="620" r="30" fill="#25292e"/>
			<path d="M700 330 L900 440 L860 500 L660 390 Z" fill="#3a4047"/>
			<path d="M680 395 L870 500" stroke="#ffe7a8" stroke-width="16" stroke-linecap="round"/>
			<path d="M690 420 L560 900 L980 900 L880 530 Z" fill="#ffe7a8" opacity="0.18"/>`
		);
	},
	nightLight() {
		return scene(
			`<circle cx="600" cy="610" r="300" fill="#ffd98a" opacity="0.18"/>
			<circle cx="600" cy="610" r="230" fill="#fff4dc"/>
			<circle cx="560" cy="560" r="90" fill="#fff" opacity="0.7"/>
			<path d="M420 880 C420 800 780 800 780 880 V940 H420 Z" fill="#c9a77c"/>
			<circle cx="600" cy="905" r="10" fill="#5fd38d"/>`
		);
	},
	bulbs() {
		const bulb = ( x ) => `<g transform="translate(${ x } 0)"><path d="M0 420 C-110 420 -130 560 -60 640 C-30 680 -30 700 -30 730 H30 C30 700 30 680 60 640 C130 560 110 420 0 420 Z" fill="#fbfbf7" stroke="#d9dad3" stroke-width="5"/><rect x="-36" y="728" width="72" height="80" rx="10" fill="#b8bcc2"/><path d="M-36 752 H36 M-36 778 H36" stroke="#8d939a" stroke-width="6"/><circle cx="-30" cy="500" r="18" fill="#fff"/></g>`;
		return scene( [ 330, 510, 690, 870 ].map( bulb ).join( '' ) + '<rect x="230" y="830" width="740" height="140" rx="20" fill="#2f6e5e"/><text x="600" y="918" text-anchor="middle" font-family="Arial, sans-serif" font-weight="700" font-size="56" fill="#fff">4 × E27</text>' );
	},
	watch( c ) {
		const g = grad( c );
		return scene(
			`<rect x="510" y="180" width="180" height="300" rx="40" fill="${ dark( c, 0.35 ) }"/>
			<rect x="510" y="760" width="180" height="280" rx="40" fill="${ dark( c, 0.35 ) }"/>
			<circle cx="600" cy="620" r="240" fill="${ g.url }"/>
			<circle cx="600" cy="620" r="200" fill="#111418"/>
			<text x="600" y="610" text-anchor="middle" font-family="Arial, sans-serif" font-weight="700" font-size="96" fill="#f1f3f5">10:09</text>
			<path d="M520 670 H680" stroke="#5fd38d" stroke-width="12" stroke-linecap="round"/>
			<rect x="830" y="580" width="34" height="80" rx="12" fill="${ dark( c, 0.2 ) }"/>`,
			[ g.def ]
		);
	},
	actionCam() {
		const g = grad( '#2a2e33' );
		return scene(
			`<rect x="330" y="420" width="540" height="420" rx="60" fill="${ g.url }"/>
			<circle cx="480" cy="630" r="130" fill="#15181b"/><circle cx="480" cy="630" r="86" fill="#2b4a63"/><circle cx="450" cy="600" r="24" fill="#9cc7ea" opacity="0.7"/>
			<rect x="640" y="520" width="180" height="140" rx="16" fill="#3a4148"/><rect x="660" y="540" width="140" height="100" rx="8" fill="#5d7f9b"/>
			<rect x="680" y="380" width="120" height="48" rx="14" fill="#e2453d"/>`,
			[ g.def ]
		);
	},
	jacket( c ) {
		const g = grad( c );
		return scene(
			`<path d="M600 230 C520 230 470 300 470 360 L380 390 L250 520 L230 900 L340 905 L360 600 L380 980 L820 980 L840 600 L860 905 L970 900 L950 520 L820 390 L730 360 C730 300 680 230 600 230 Z" fill="${ g.url }"/>
			<path d="M470 360 C500 430 700 430 730 360 C700 320 500 320 470 360 Z" fill="${ dark( c, 0.35 ) }"/>
			<path d="M600 400 V980" stroke="${ dark( c, 0.4 ) }" stroke-width="10"/>
			<path d="M440 640 L520 660 M760 660 L680 640" stroke="${ dark( c, 0.3 ) }" stroke-width="12" stroke-linecap="round"/>
			<path d="M380 980 H820" stroke="${ dark( c, 0.35 ) }" stroke-width="14"/>`,
			[ g.def ]
		);
	},
	fleece( c ) {
		const g = grad( c );
		let grid = '';
		for ( let y = 420; y < 960; y += 40 ) {
			grid += `<path d="M380 ${ y } H820" stroke="${ light( c, 0.12 ) }" stroke-width="3" opacity="0.6"/>`;
		}
		return scene(
			`<path d="M520 290 L460 320 L300 420 L240 900 L350 905 L380 600 L390 980 L810 980 L820 600 L850 905 L960 900 L900 420 L740 320 L680 290 Z" fill="${ g.url }"/>
			<clipPath id="body"><rect x="390" y="330" width="420" height="650"/></clipPath><g clip-path="url(#body)">${ grid }</g>
			<path d="M520 290 C540 330 660 330 680 290" fill="none" stroke="${ dark( c, 0.35 ) }" stroke-width="22"/>
			<path d="M600 320 V520" stroke="#c9ccd0" stroke-width="10"/><rect x="588" y="500" width="24" height="40" rx="6" fill="#c9ccd0"/>`,
			[ g.def ]
		);
	},
	daypack( c ) {
		const g = grad( c );
		return scene(
			`<path d="M520 230 C520 180 680 180 680 230" fill="none" stroke="${ dark( c, 0.4 ) }" stroke-width="26"/>
			<rect x="380" y="250" width="440" height="730" rx="150" fill="${ g.url }"/>
			<rect x="440" y="610" width="320" height="300" rx="70" fill="${ dark( c, 0.12 ) }"/>
			<path d="M450 640 H750" stroke="${ dark( c, 0.45 ) }" stroke-width="8"/>
			<path d="M420 330 C470 300 730 300 780 330" fill="none" stroke="${ dark( c, 0.45 ) }" stroke-width="8"/>
			<rect x="575" y="400" width="50" height="110" rx="14" fill="${ dark( c, 0.5 ) }"/>
			<rect x="560" y="625" width="80" height="24" rx="8" fill="#c9ccd0"/>`,
			[ g.def ]
		);
	},
	bottle() {
		return scene(
			`<rect x="510" y="250" width="180" height="90" rx="30" fill="#2f6e5e"/><rect x="560" y="210" width="80" height="60" rx="16" fill="#2f6e5e"/>
			<rect x="460" y="330" width="280" height="650" rx="90" fill="#d9eef0" opacity="0.92" stroke="#aac8cb" stroke-width="6"/>
			<rect x="460" y="600" width="280" height="380" rx="0" fill="#9fd3d9" opacity="0.55"/>
			<rect x="500" y="380" width="30" height="520" rx="15" fill="#fff" opacity="0.55"/>`
		);
	},
	thermo() {
		const g = grad( '#3c6e8f' );
		return scene(
			`<rect x="480" y="180" width="240" height="170" rx="30" fill="#2a3238"/>
			<rect x="450" y="330" width="300" height="660" rx="60" fill="${ g.url }"/>
			<rect x="480" y="370" width="26" height="560" rx="13" fill="#fff" opacity="0.25"/>
			<path d="M450 420 H750" stroke="#2a3238" stroke-width="10"/>`,
			[ g.def ]
		);
	},
	mug() {
		return scene(
			`<path d="M790 560 C930 560 930 820 790 820" fill="none" stroke="#e9ecea" stroke-width="56"/>
			<path d="M790 560 C930 560 930 820 790 820" fill="none" stroke="#2c3a4a" stroke-width="14" opacity="0.6"/>
			<path d="M360 470 H830 L800 940 C800 975 390 975 390 940 Z" fill="#eef1ef"/>
			<ellipse cx="595" cy="470" rx="235" ry="40" fill="#cfd5d2"/><ellipse cx="595" cy="470" rx="235" ry="40" fill="none" stroke="#2c3a4a" stroke-width="12"/>
			<path d="M392 935 C430 975 760 975 800 935" fill="none" stroke="#2c3a4a" stroke-width="12"/>
			<circle cx="510" cy="700" r="14" fill="#2c3a4a" opacity="0.25"/>`
		);
	},
	headlamp() {
		return scene(
			`<ellipse cx="600" cy="640" rx="330" ry="220" fill="none" stroke="#3d4a3b" stroke-width="56"/>
			<ellipse cx="600" cy="640" rx="330" ry="220" fill="none" stroke="#e8a33d" stroke-width="8" stroke-dasharray="40 30"/>
			<rect x="470" y="740" width="260" height="190" rx="50" fill="#2b3036"/>
			<circle cx="600" cy="835" r="66" fill="#fff6d6"/><circle cx="600" cy="835" r="40" fill="#ffe7a8"/>`
		);
	},
	poles() {
		const pole = ( x, r ) => `<g transform="rotate(${ r } ${ x } 600)"><rect x="${ x - 12 }" y="250" width="24" height="760" rx="12" fill="#2b3036"/><rect x="${ x - 30 }" y="240" width="60" height="190" rx="26" fill="#b8835a"/><rect x="${ x - 14 }" y="600" width="28" height="18" fill="#9aa0a6"/><circle cx="${ x }" cy="940" r="40" fill="none" stroke="#2b3036" stroke-width="12"/><path d="M${ x - 34 } 245 C${ x - 70 } 300 ${ x - 60 } 380 ${ x - 30 } 420" fill="none" stroke="#e2453d" stroke-width="10"/></g>`;
		return scene( pole( 520, -10 ) + pole( 680, 10 ) );
	},
	wirelessPad() {
		return scene(
			`<ellipse cx="600" cy="720" rx="330" ry="170" fill="#1f2328"/>
			<ellipse cx="600" cy="690" rx="330" ry="170" fill="#33393f"/>
			<ellipse cx="600" cy="690" rx="250" ry="122" fill="none" stroke="#5fd38d" stroke-width="6" opacity="0.8"/>
			<path d="M560 680 L600 640 L640 680 M600 640 V730" fill="none" stroke="#9aa0a6" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>`
		);
	},
	carCharger() {
		return scene(
			`<rect x="520" y="300" width="160" height="440" rx="70" fill="#2b3036"/>
			<rect x="470" y="700" width="260" height="230" rx="40" fill="#3a4047"/>
			<rect x="560" y="760" width="80" height="26" rx="13" fill="#15181b"/><rect x="545" y="820" width="110" height="34" rx="6" fill="#15181b"/>
			<rect x="585" y="250" width="30" height="70" rx="12" fill="#c9ccd0"/>
			<rect x="500" y="440" width="20" height="60" rx="8" fill="#c9ccd0"/><rect x="680" y="440" width="20" height="60" rx="8" fill="#c9ccd0"/>`
		);
	},
	adapter() {
		const g = grad( '#f1f2f3' );
		return scene(
			`<rect x="390" y="390" width="420" height="500" rx="60" fill="${ g.url }" stroke="#d7dade" stroke-width="4"/>
			<rect x="470" y="290" width="40" height="120" rx="12" fill="#b9bec4"/><rect x="690" y="290" width="40" height="120" rx="12" fill="#b9bec4"/><rect x="580" y="250" width="40" height="150" rx="12" fill="#b9bec4"/>
			<rect x="520" y="560" width="160" height="30" rx="15" fill="#25292e"/><rect x="520" y="620" width="160" height="30" rx="15" fill="#25292e"/>
			<path d="M440 780 H760" stroke="#2f6e5e" stroke-width="18" stroke-linecap="round"/>`,
			[ g.def ]
		);
	},
};

const jobs = [
	[ 'nordline/img/aurora-anc-black.jpg', drawings.headphones( '#2b2f34' ) ],
	[ 'nordline/img/aurora-anc-sand.jpg', drawings.headphones( '#cdb894' ) ],
	[ 'nordline/img/aurora-anc-navy.jpg', drawings.headphones( '#2d4a7a' ) ],
	[ 'nordline/img/aurora-anc-case.jpg', drawings.headphonesCase() ],
	[ 'nordline/img/aurora-buds-black.jpg', drawings.earbuds( '#2b2f34' ) ],
	[ 'nordline/img/aurora-buds-white.jpg', drawings.earbuds( '#f2f2ef' ) ],
	[ 'nordline/img/pulse-mini-graphite.jpg', drawings.speakerMini( '#43494f' ) ],
	[ 'nordline/img/pulse-mini-coral.jpg', drawings.speakerMini( '#ec7a62' ) ],
	[ 'nordline/img/pulse-mini-teal.jpg', drawings.speakerMini( '#2f8f87' ) ],
	[ 'nordline/img/pulse-mini-sand.jpg', drawings.speakerMini( '#d6c09c' ) ],
	[ 'nordline/img/pulse-max.jpg', drawings.speakerMax() ],
	[ 'nordline/img/volta-ch65.jpg', drawings.charger( 65 ) ],
	[ 'nordline/img/volta-pb20.jpg', drawings.powerbank( true ) ],
	[ 'nordline/img/volta-pb10.jpg', drawings.powerbank( false ) ],
	[ 'nordline/img/volta-cable.jpg', drawings.cable() ],
	[ 'nordline/img/lumen-arc.jpg', drawings.lamp() ],
	[ 'nordline/img/lumen-bulbs.jpg', drawings.bulbs() ],
	[ 'nordline/img/lumen-glow.jpg', drawings.nightLight() ],
	[ 'nordline/img/orbit-s2-black.jpg', drawings.watch( '#2b2f34' ) ],
	[ 'nordline/img/orbit-s2-silver.jpg', drawings.watch( '#c3c7cc' ) ],
	[ 'nordline/img/kite-4k.jpg', drawings.actionCam() ],
	[ 'pinecrest/img/ridge-red.jpg', drawings.jacket( '#c2412d' ) ],
	[ 'pinecrest/img/ridge-olive.jpg', drawings.jacket( '#5f6b3c' ) ],
	[ 'pinecrest/img/ember-charcoal.jpg', drawings.fleece( '#4a4f55' ) ],
	[ 'pinecrest/img/summit-slate.jpg', drawings.daypack( '#4f6475' ) ],
	[ 'pinecrest/img/summit-rust.jpg', drawings.daypack( '#b4562f' ) ],
	[ 'pinecrest/img/trail-bottle.jpg', drawings.bottle() ],
	[ 'pinecrest/img/thermo-bottle.jpg', drawings.thermo() ],
	[ 'pinecrest/img/enamel-mug.jpg', drawings.mug() ],
	[ 'pinecrest/img/headlamp.jpg', drawings.headlamp() ],
	[ 'pinecrest/img/trekking-poles.jpg', drawings.poles() ],
	// Volta's site reuses some of the photos Nordline sends: same bytes, different URLs.
	// The importer stores each of them once.
	[ 'volta/img/gan-65.jpg', drawings.charger( 65 ) ],
	[ 'volta/img/gan-100.jpg', drawings.charger( 100 ) ],
	[ 'volta/img/powerbank-20k.jpg', drawings.powerbank( true ) ],
	[ 'volta/img/powerbank-10k.jpg', drawings.powerbank( false ) ],
	[ 'volta/img/cable-2m.jpg', drawings.cable() ],
	[ 'volta/img/wireless-pad.jpg', drawings.wirelessPad() ],
	[ 'volta/img/car-charger.jpg', drawings.carCharger() ],
	[ 'volta/img/travel-adapter.jpg', drawings.adapter() ],
];

for ( const [ path, svg ] of jobs ) {
	const out = join( feeds, path );
	mkdirSync( dirname( out ), { recursive: true } );
	writeFileSync( out, await sharp( Buffer.from( svg ) ).jpeg( { quality: 84, mozjpeg: true } ).toBuffer() );
}
console.log( `Rendered ${ jobs.length } images.` );
