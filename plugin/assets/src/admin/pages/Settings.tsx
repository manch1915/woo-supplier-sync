import {
	Button,
	Notice,
	RadioControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';
import {
	ErrorNotice,
	Loading,
	PageHeader,
	ReadOnlyBanner,
	useMeta,
} from '../components';
import { useAsync } from '../hooks';
import type { Settings as SettingsData } from '../types';

export function Settings() {
	const meta = useMeta();
	const settings = useAsync(
		() => api.get< SettingsData >( '/settings' ),
		[]
	);
	const [ draft, setDraft ] = useState< SettingsData | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const readOnly = ! meta.caps.manage;

	useEffect( () => {
		if ( settings.data ) {
			setDraft( settings.data );
		}
	}, [ settings.data ] );

	if ( settings.error ) {
		return (
			<ErrorNotice
				message={ settings.error }
				onRetry={ settings.reload }
			/>
		);
	}
	if ( ! draft ) {
		return <Loading />;
	}

	const set = < K extends keyof SettingsData >(
		key: K,
		value: Partial< SettingsData[ K ] >
	) =>
		setDraft( {
			...draft,
			[ key ]:
				typeof value === 'object' && value !== null
					? { ...( draft[ key ] as object ), ...value }
					: value,
		} );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			setDraft( await api.put< SettingsData >( '/settings', draft ) );
			setNotice( {
				status: 'success',
				text: __( 'Settings saved.', 'woo-supplier-sync' ),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				text: e instanceof Error ? e.message : String( e ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const testTelegram = async () => {
		setTesting( true );
		setNotice( null );
		try {
			await api.post( '/settings/telegram-test', draft.notifications );
			setNotice( {
				status: 'success',
				text: __(
					'Test message sent. Check the chat.',
					'woo-supplier-sync'
				),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				text: e instanceof Error ? e.message : String( e ),
			} );
		} finally {
			setTesting( false );
		}
	};

	return (
		<>
			<PageHeader title={ __( 'Settings', 'woo-supplier-sync' ) } />
			<ReadOnlyBanner />
			<fieldset
				disabled={ readOnly }
				className="wss-fieldset wss-settings"
			>
				<section className="wss-panel wss-form-section">
					<h2 className="wss-form-section__title">
						{ __( 'When suppliers run out', 'woo-supplier-sync' ) }
					</h2>
					<RadioControl
						label={ __(
							'Product with zero stock at every supplier',
							'woo-supplier-sync'
						) }
						selected={ draft.stock.when_zero }
						options={ [
							{
								value: 'outofstock',
								label: __(
									'Keep it visible as out of stock',
									'woo-supplier-sync'
								),
							},
							{
								value: 'hide',
								label: __(
									'Hide it from the catalog, keep the page',
									'woo-supplier-sync'
								),
							},
							{
								value: 'draft',
								label: __(
									'Move it to drafts',
									'woo-supplier-sync'
								),
							},
						] }
						onChange={ ( value ) =>
							set( 'stock', {
								when_zero:
									value as SettingsData[ 'stock' ][ 'when_zero' ],
							} )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Sell "on order" when a supplier can deliver later',
							'woo-supplier-sync'
						) }
						help={ __(
							'In YML that is available="false". Customers can still buy, and WooCommerce shows "Available on backorder".',
							'woo-supplier-sync'
						) }
						checked={ draft.stock.allow_on_order }
						onChange={ ( allowOnOrder ) =>
							set( 'stock', { allow_on_order: allowOnOrder } )
						}
					/>
				</section>

				<section className="wss-panel wss-form-section">
					<h2 className="wss-form-section__title">
						{ __( 'Photos', 'woo-supplier-sync' ) }
					</h2>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Convert to WebP', 'woo-supplier-sync' ) }
						help={ __(
							'Smaller files, faster pages. Every photo is downloaded once, even if several suppliers or products share it.',
							'woo-supplier-sync'
						) }
						checked={ draft.images.webp }
						onChange={ ( webp ) => set( 'images', { webp } ) }
					/>
					<div className="wss-grid-2">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 40 }
							max={ 100 }
							label={ __( 'Quality, %', 'woo-supplier-sync' ) }
							value={ String( draft.images.quality ) }
							onChange={ ( v ) =>
								set( 'images', { quality: Number( v ) } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 0 }
							label={ __(
								'Longest side, px (0 keeps the original)',
								'woo-supplier-sync'
							) }
							value={ String( draft.images.max_width ) }
							onChange={ ( v ) =>
								set( 'images', { max_width: Number( v ) } )
							}
						/>
					</div>
				</section>

				<section className="wss-panel wss-form-section">
					<h2 className="wss-form-section__title">
						{ __( 'Reports', 'woo-supplier-sync' ) }
					</h2>
					<p className="wss-muted">
						{ __(
							'A short summary after every scheduled or manual sync, with a link to the full log.',
							'woo-supplier-sync'
						) }
					</p>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="email"
						label={ __( 'E-mail', 'woo-supplier-sync' ) }
						value={ draft.notifications.email }
						onChange={ ( email ) =>
							set( 'notifications', { email } )
						}
					/>
					<div className="wss-grid-2">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Telegram bot token',
								'woo-supplier-sync'
							) }
							help={ __(
								'Create a bot with @BotFather.',
								'woo-supplier-sync'
							) }
							value={ draft.notifications.telegram_token }
							onChange={ ( telegramToken ) =>
								set( 'notifications', {
									telegram_token: telegramToken,
								} )
							}
							autoComplete="off"
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Chat ID', 'woo-supplier-sync' ) }
							help={ __(
								'A person, a group or a channel the bot can post to.',
								'woo-supplier-sync'
							) }
							value={ draft.notifications.telegram_chat }
							onChange={ ( telegramChat ) =>
								set( 'notifications', {
									telegram_chat: telegramChat,
								} )
							}
						/>
					</div>
					<div className="wss-inline-actions">
						<Button
							variant="secondary"
							onClick={ testTelegram }
							isBusy={ testing }
							disabled={
								testing ||
								! draft.notifications.telegram_token ||
								! draft.notifications.telegram_chat
							}
						>
							{ __( 'Send a test message', 'woo-supplier-sync' ) }
						</Button>
					</div>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Only report runs with errors',
							'woo-supplier-sync'
						) }
						checked={ draft.notifications.only_problems }
						onChange={ ( onlyProblems ) =>
							set( 'notifications', {
								only_problems: onlyProblems,
							} )
						}
					/>
				</section>

				<section className="wss-panel wss-form-section">
					<h2 className="wss-form-section__title">
						{ __( 'Performance and logs', 'woo-supplier-sync' ) }
					</h2>
					<div className="wss-grid-2">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 5 }
							max={ 60 }
							label={ __(
								'Seconds per background step',
								'woo-supplier-sync'
							) }
							help={ __(
								'Large feeds are processed in short steps so the server never hits its time limit. Lower it on weak shared hosting.',
								'woo-supplier-sync'
							) }
							value={ String( draft.time_budget ) }
							onChange={ ( v ) =>
								setDraft( {
									...draft,
									time_budget: Number( v ),
								} )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							label={ __(
								'Keep run logs, days',
								'woo-supplier-sync'
							) }
							value={ String( draft.log_retention_days ) }
							onChange={ ( v ) =>
								setDraft( {
									...draft,
									log_retention_days: Number( v ),
								} )
							}
						/>
					</div>
					{ meta.urls.scheduler && (
						<p>
							<a href={ meta.urls.scheduler }>
								{ __(
									'Background jobs in WooCommerce › Status › Scheduled actions',
									'woo-supplier-sync'
								) }
							</a>
						</p>
					) }
				</section>

				{ ! readOnly && (
					<div className="wss-savebar">
						{ notice && (
							<Notice
								status={ notice.status }
								isDismissible
								onRemove={ () => setNotice( null ) }
							>
								{ notice.text }
							</Notice>
						) }
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save settings', 'woo-supplier-sync' ) }
						</Button>
					</div>
				) }
			</fieldset>
		</>
	);
}
