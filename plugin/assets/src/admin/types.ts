export type FeedType = 'yml' | 'xml' | 'csv' | 'xlsx' | 'json' | 'site';
export type RunStatus =
	'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
export type RunPhase = 'fetch' | 'parse' | 'apply' | 'sweep' | 'finish';
export type ItemAction =
	'created' | 'updated' | 'unpublished' | 'error' | 'unchanged' | 'skipped';

export interface RunStats {
	parsed: number;
	created: number;
	updated: number;
	unchanged: number;
	unpublished: number;
	errors: number;
	skipped: number;
}

export interface Run {
	id: number;
	supplier_id: number;
	supplier_name?: string;
	supplier_type?: FeedType | null;
	user_name?: string | null;
	dry_run: boolean;
	source: 'manual' | 'schedule' | 'cli';
	status: RunStatus;
	phase: RunPhase;
	progress: number;
	stats: RunStats;
	error: string | null;
	created_at: string | null;
	started_at: string | null;
	finished_at: string | null;
}

export interface RunItem {
	id: number;
	action: ItemAction;
	group_key: string;
	product_id: number;
	title: string;
	message: string;
	changes: Record< string, [ unknown, unknown ] >;
	edit_url: string | null;
	view_url: string | null;
}

export interface SupplierMapping {
	delimiter?: string;
	encoding?: string;
	decimal?: string;
	columns?: Record< string, string >;
	attribute_columns?: string[];
	attribute_prefix?: string;
	items_path?: string;
	item_tag?: string;
	fields?: Record< string, string >;
	attributes_path?: string;
	attribute_name?: string;
}

export interface SupplierConfig {
	source: {
		url: string;
		attachment_id?: number;
		headers?: Record< string, string >;
	};
	currency: string;
	mapping?: SupplierMapping;
	scraper?: {
		start_urls?: string[];
		max_pages?: number;
		max_products?: number;
		delay_ms?: number;
		selectors?: Record< string, string >;
	};
	attributes?: Record< string, string >;
	unmapped_attributes?: 'keep' | 'skip';
	categories?: Record< string, number | string >;
	unmapped_categories?: 'create' | 'skip' | number;
	category_root?: number | string;
	update_fields?: string[];
	max_removal_percent?: number;
}

export interface Supplier {
	id: number;
	name: string;
	type: FeedType;
	schedule: string;
	enabled: boolean;
	config: SupplierConfig;
	can_edit?: boolean;
	offers?: number;
	last_run_id: number | null;
	last_run?: Run | null;
	active_run?: Run | null;
	next_run?: string | null;
}

export interface PriceRule {
	id: string;
	label: string;
	supplier_id: number | null;
	category_id: number | null;
	min_cost: number | null;
	max_cost: number | null;
	markup_percent: number;
	markup_fixed: number;
	min_margin_percent: number;
	rounding: string;
	priority: number;
}

export interface Settings {
	rates: Record< string, number >;
	default_rule: Partial< PriceRule >;
	rules: PriceRule[];
	stock: {
		when_zero: 'outofstock' | 'hide' | 'draft';
		allow_on_order: boolean;
	};
	images: { webp: boolean; quality: number; max_width: number };
	notifications: {
		email: string;
		telegram_token: string;
		telegram_chat: string;
		only_problems: boolean;
	};
	time_budget: number;
	log_retention_days: number;
	store_currency: string;
}

export interface Category {
	id: number;
	name: string;
	parent: number;
	count: number;
}

export interface Meta {
	version: string;
	store_currency: string;
	currencies: string[];
	categories: Category[];
	attributes: string[];
	types: FeedType[];
	schedules: string[];
	roundings: string[];
	update_fields: string[];
	caps: { view: boolean; run: boolean; manage: boolean };
	urls: { products: string | null; shop: string; scheduler: string | null };
}

export interface Dashboard {
	products: number;
	offers: number;
	merged: number;
	stock: Record< string, string | number >;
	errors_24h: number;
	active_runs: number;
	activity: Array< {
		day: string;
		created: number;
		updated: number;
		unpublished: number;
		errors: number;
	} >;
	recent_runs: Run[];
}

export interface Preview {
	columns: string[];
	rows: Array< Record< string, string > >;
	offers: Array<
		Record< string, unknown > & {
			name: string;
			retail?: number | null;
			price_error?: string;
		}
	>;
	errors: Array< { reference: string; message: string } >;
}

export interface PricePreview {
	cost: number;
	regular_price: number;
	sale_price: number | null;
	final_price: number;
	margin: number;
	margin_percent: number | null;
	rule: PriceRule;
	margin_floor_applied: boolean;
	currency: string;
}
