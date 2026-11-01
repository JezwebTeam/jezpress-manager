/**
 * JezPress Manager — dashboard behaviour.
 *
 * - Activate / deactivate toggles (Manager's own AJAX action, nonce per input).
 * - Check for updates (Manager's own AJAX action).
 * - Update one / Update all through core's wp.updates.ajax( 'update-plugin' ), which
 *   carries core's 'updates' nonce; the server side is core's own handler.
 * - Auto-update toggles POST core's 'toggle-auto-updates' action directly with the same
 *   nonce (as core's Plugins screen does): that handler replies with an empty success
 *   payload, and wp.updates.ajaxAlways() dereferences the payload, so routing it through
 *   wp.updates.ajax() throws and leaves wp.updates.ajaxLocked stuck on.
 * - Search + "Needs attention" filter, Copy site details, kebab menus.
 *
 * Core's wp.updates.updatePlugin() assumes the Plugins-screen DOM and throws on any
 * other pagenow, so updates always go through wp.updates.ajax() with our own callbacks.
 *
 * @since 1.2.0
 */
( function( window, document ) {
	'use strict';

	var cfg  = window.jezpressManager || {};
	var i18n = cfg.i18n || {};
	var wrap = document.getElementById( 'jezpress-manager' );

	if ( ! wrap ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function sprintf( template, value ) {
		return String( template ).replace( '%s', value );
	}

	function notice( message, type ) {
		var el = document.getElementById( 'jezpress-notice' );
		if ( ! el ) {
			return;
		}
		el.className = 'jezpress-notice' + ( type ? ' is-' + type : '' );
		el.textContent = message;
		el.style.display = 'block';
		window.clearTimeout( el._hideTimer );
		el._hideTimer = window.setTimeout( function() {
			el.style.display = 'none';
		}, 5000 );
	}

	function post( action, fields ) {
		var body = new window.URLSearchParams();
		body.append( 'action', action );
		Object.keys( fields ).forEach( function( key ) {
			body.append( key, fields[ key ] );
		} );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function( response ) {
			return response.json();
		} );
	}

	function setRowMessage( row, text, type, link ) {
		var el = row.querySelector( '.jezpress-row-message' );
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.className = 'jezpress-row-message' + ( type ? ' is-' + type : '' );
		if ( link ) {
			el.appendChild( document.createTextNode( ' ' ) );
			el.appendChild( link );
		}
		el.hidden = ! text;
	}

	function reloadSoon() {
		window.setTimeout( function() {
			window.location.reload();
		}, 1200 );
	}

	function hasUpdatesApi() {
		return !! ( cfg.canUpdate && window.wp && window.wp.updates && typeof window.wp.updates.ajax === 'function' );
	}

	/* ------------------------------------------------------------------ */
	/* Activate / deactivate toggle                                        */
	/* ------------------------------------------------------------------ */

	function onToggleChange( event ) {
		var checkbox   = event.target;
		var pluginFile = checkbox.getAttribute( 'data-plugin-file' );
		var pluginName = checkbox.getAttribute( 'data-plugin-name' );
		var nonce      = checkbox.getAttribute( 'data-nonce' );
		var isChecked  = checkbox.checked;
		var row        = checkbox.closest( '.jezpress-row' );
		var text       = checkbox.parentNode.querySelector( '.jezpress-toggle-text' );

		checkbox.disabled = true;

		post( 'jezpress_manager_toggle_plugin', {
			toggle_action: isChecked ? 'activate' : 'deactivate',
			plugin_file: pluginFile,
			nonce: nonce
		} ).then( function( data ) {
			if ( data && data.success ) {
				if ( row ) {
					row.classList.toggle( 'is-active', isChecked );
					row.classList.toggle( 'is-inactive', ! isChecked );
				}
				if ( text ) {
					text.textContent = isChecked ? i18n.on : i18n.off;
				}
				notice( ( isChecked ? i18n.activated : i18n.deactivated ) + ' ' + pluginName, 'success' );
			} else {
				checkbox.checked = ! isChecked;
				notice( ( data && data.data && data.data.message ) ? data.data.message : i18n.genericError, 'error' );
			}
		} ).catch( function() {
			checkbox.checked = ! isChecked;
			notice( i18n.networkError, 'error' );
		} ).then( function() {
			checkbox.disabled = false;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Check for updates                                                   */
	/* ------------------------------------------------------------------ */

	function onCheckUpdates( event ) {
		var button = event.currentTarget;
		var label  = button.textContent;

		button.disabled = true;
		button.classList.add( 'is-busy' );
		button.textContent = i18n.checking;

		post( 'jezpress_manager_check_updates', { nonce: cfg.checkNonce } ).then( function( data ) {
			if ( data && data.success ) {
				notice( i18n.checkDone, 'success' );
				reloadSoon();
			} else {
				button.disabled = false;
				button.classList.remove( 'is-busy' );
				button.textContent = label;
				notice( ( data && data.data && data.data.message ) ? data.data.message : i18n.genericError, 'error' );
			}
		} ).catch( function() {
			button.disabled = false;
			button.classList.remove( 'is-busy' );
			button.textContent = label;
			notice( i18n.networkError, 'error' );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Plugin updates via core                                             */
	/* ------------------------------------------------------------------ */

	function updateRow( row ) {
		return new window.Promise( function( resolve ) {
			var file = row.getAttribute( 'data-plugin-file' );
			var slug = row.getAttribute( 'data-slug' );
			var name = row.getAttribute( 'data-name' );
			var btn  = row.querySelector( '.jezpress-update-btn' );

			if ( ! file || ! slug ) {
				resolve( false );
				return;
			}

			if ( window.wp.updates.ajaxLocked ) {
				// Another core update job is in flight; queueing through core would
				// re-enter updatePlugin() and throw on this page, so just bail.
				setRowMessage( row, i18n.genericError, 'error' );
				resolve( false );
				return;
			}

			row.classList.add( 'is-updating' );
			if ( btn ) {
				btn.disabled = true;
				btn.textContent = i18n.updating;
			}
			setRowMessage( row, sprintf( i18n.updatingName, name ), 'info' );

			window.wp.updates.ajax( 'update-plugin', {
				plugin: file,
				slug: slug,
				success: function() {
					row.classList.remove( 'is-updating' );
					row.classList.add( 'is-updated' );
					setRowMessage( row, sprintf( i18n.updated, name ), 'ok' );
					resolve( true );
				},
				error: function( response ) {
					var message, link;
					row.classList.remove( 'is-updating' );
					if ( btn ) {
						btn.disabled = false;
						btn.textContent = i18n.updateLabel;
					}
					if ( response && 'unable_to_connect_to_filesystem' === response.errorCode ) {
						// Core keeps its lock on this error expecting its credentials modal,
						// which this page does not embed. Release it and point to core's screen.
						window.wp.updates.ajaxLocked = false;
						link = document.createElement( 'a' );
						link.href = cfg.pluginsUrl;
						link.textContent = i18n.fsLink;
						setRowMessage( row, i18n.fsCredentials, 'error', link );
					} else {
						message = ( response && response.errorMessage ) ? response.errorMessage : i18n.genericError;
						setRowMessage( row, sprintf( i18n.updateFailed, message ), 'error' );
					}
					resolve( false );
				}
			} );
		} );
	}

	function onUpdateOne( event ) {
		var row = event.currentTarget.closest( '.jezpress-row' );
		if ( ! row || ! hasUpdatesApi() ) {
			return;
		}
		updateRow( row ).then( function( ok ) {
			if ( ok ) {
				notice( sprintf( i18n.updated, row.getAttribute( 'data-name' ) ) + ' ' + i18n.allDone, 'success' );
				reloadSoon();
			}
		} );
	}

	function onUpdateAll( event ) {
		var button = event.currentTarget;
		var rows   = Array.prototype.slice.call( wrap.querySelectorAll( '.jezpress-row[data-has-update="1"]' ) );
		var anyOk  = false;

		if ( ! rows.length || ! hasUpdatesApi() ) {
			return;
		}

		button.disabled = true;
		button.classList.add( 'is-busy' );

		function next() {
			var row = rows.shift();
			if ( ! row ) {
				button.classList.remove( 'is-busy' );
				if ( anyOk ) {
					notice( i18n.allDone, 'success' );
					reloadSoon();
				} else {
					button.disabled = false;
				}
				return;
			}
			updateRow( row ).then( function( ok ) {
				anyOk = anyOk || ok;
				next();
			} );
		}

		next();
	}

	/**
	 * Core's plugin-information iframe ("What changed") posts {action:'update-plugin'}
	 * to the parent, and core's own listener routes that to wp.updates.updatePlugin(),
	 * which throws on this page. Capture listeners at the window target run before
	 * jQuery's, so take the message here and run the same path as the row button.
	 */
	function onIframeMessage( event ) {
		var message, row;

		if ( event.origin !== document.location.protocol + '//' + document.location.host ) {
			return;
		}
		try {
			message = JSON.parse( event.data );
		} catch ( e ) {
			return;
		}
		if ( ! message || 'update-plugin' !== message.action ) {
			return;
		}

		event.stopImmediatePropagation();
		if ( typeof window.tb_remove === 'function' ) {
			window.tb_remove();
		}
		if ( ! hasUpdatesApi() || ! message.data || ! message.data.plugin ) {
			return;
		}

		row = wrap.querySelector( '.jezpress-row[data-plugin-file="' + window.CSS.escape( message.data.plugin ) + '"]' );
		if ( ! row ) {
			notice( i18n.genericError, 'error' );
			return;
		}
		updateRow( row ).then( function( ok ) {
			if ( ok ) {
				notice( sprintf( i18n.updated, row.getAttribute( 'data-name' ) ) + ' ' + i18n.allDone, 'success' );
				reloadSoon();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Auto-updates via core                                               */
	/* ------------------------------------------------------------------ */

	function onAutoToggle( event ) {
		var button = event.currentTarget;
		var row    = button.closest( '.jezpress-row' );
		var file   = button.getAttribute( 'data-plugin-file' );
		var state  = button.getAttribute( 'data-state' ); // 'enable' | 'disable'
		var menu   = button.closest( 'details' );

		if ( ! file || ! hasUpdatesApi() || ! window.wp.updates.ajaxNonce ) {
			return;
		}

		button.disabled = true;

		// Core's own handler (wp_ajax_toggle_auto_updates): 'updates' nonce + update_plugins.
		post( 'toggle-auto-updates', {
			_ajax_nonce: window.wp.updates.ajaxNonce,
			type: 'plugin',
			asset: file,
			state: state
		} ).then( function( data ) {
			var nowOn = ( 'enable' === state );
			var label = row ? row.querySelector( '.jezpress-auto-state' ) : null;
			button.disabled = false;
			if ( ! data || ! data.success ) {
				notice( ( data && data.data && data.data.error ) ? data.data.error : i18n.genericError, 'error' );
				return;
			}
			button.setAttribute( 'data-state', nowOn ? 'disable' : 'enable' );
			button.textContent = nowOn ? button.getAttribute( 'data-label-disable' ) : button.getAttribute( 'data-label-enable' );
			if ( label ) {
				label.textContent = nowOn ? i18n.autoOn : i18n.autoOff;
				label.setAttribute( 'data-auto-state', nowOn ? 'on' : 'off' );
			}
			if ( menu ) {
				menu.removeAttribute( 'open' );
			}
			notice( nowOn ? i18n.autoEnabled : i18n.autoDisabled, 'success' );
		} ).catch( function() {
			button.disabled = false;
			notice( i18n.networkError, 'error' );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Search + filter                                                     */
	/* ------------------------------------------------------------------ */

	var currentFilter = 'all';

	function applyFilter() {
		var search  = document.getElementById( 'jezpress-search' );
		var query   = search ? search.value.trim().toLowerCase() : '';
		var rows    = wrap.querySelectorAll( '#jezpress-plugin-table tbody tr' );
		var visible = 0;
		var empty   = document.getElementById( 'jezpress-table-empty' );

		Array.prototype.forEach.call( rows, function( row ) {
			var text = row.getAttribute( 'data-search' ) || '';
			var hit  = ( ! query || text.indexOf( query ) !== -1 ) &&
				( 'all' === currentFilter || '1' === row.getAttribute( 'data-attention' ) );
			row.classList.toggle( 'is-hidden', ! hit );
			if ( hit && row.classList.contains( 'jezpress-row' ) ) {
				visible++;
			}
		} );

		if ( empty ) {
			empty.hidden = visible > 0;
		}
	}

	function setFilter( filter ) {
		currentFilter = filter;
		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-segmented button' ), function( btn ) {
			var active = btn.getAttribute( 'data-filter' ) === filter;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
		applyFilter();
	}

	/* ------------------------------------------------------------------ */
	/* Copy site details                                                   */
	/* ------------------------------------------------------------------ */

	function onCopySite( event ) {
		var button   = event.currentTarget;
		var textarea = document.getElementById( 'jezpress-site-report' );
		var original = button.innerHTML;
		var text;

		if ( ! textarea ) {
			return;
		}
		text = textarea.value;

		function done() {
			button.textContent = button.getAttribute( 'data-copied' );
			notice( i18n.copied, 'success' );
			window.setTimeout( function() {
				button.innerHTML = original;
			}, 2000 );
		}

		function fallback() {
			try {
				textarea.classList.remove( 'screen-reader-text' );
				textarea.focus();
				textarea.select();
				var ok = document.execCommand( 'copy' );
				textarea.classList.add( 'screen-reader-text' );
				if ( ok ) {
					done();
					return;
				}
			} catch ( e ) {
				textarea.classList.add( 'screen-reader-text' );
			}
			notice( i18n.copyFailed, 'error' );
		}

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Kebab menus                                                         */
	/* ------------------------------------------------------------------ */

	function closeMenus( except ) {
		Array.prototype.forEach.call( wrap.querySelectorAll( 'details.jezpress-menu[open]' ), function( menu ) {
			if ( menu !== except ) {
				menu.removeAttribute( 'open' );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Wire up                                                             */
	/* ------------------------------------------------------------------ */

	function init() {
		var check    = document.getElementById( 'jezpress-check-updates' );
		var all      = document.getElementById( 'jezpress-update-all' );
		var copy     = document.getElementById( 'jezpress-copy-site' );
		var search   = document.getElementById( 'jezpress-search' );

		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-toggle-input' ), function( input ) {
			input.addEventListener( 'change', onToggleChange );
		} );

		if ( check ) {
			check.addEventListener( 'click', onCheckUpdates );
		}
		if ( all ) {
			all.addEventListener( 'click', onUpdateAll );
		}
		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-update-btn' ), function( btn ) {
			btn.addEventListener( 'click', onUpdateOne );
		} );
		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-auto-btn' ), function( btn ) {
			btn.addEventListener( 'click', onAutoToggle );
		} );

		if ( search ) {
			search.addEventListener( 'input', applyFilter );
		}
		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-segmented button' ), function( btn ) {
			btn.addEventListener( 'click', function() {
				setFilter( btn.getAttribute( 'data-filter' ) );
			} );
		} );
		Array.prototype.forEach.call( wrap.querySelectorAll( '.jezpress-filter-link' ), function( link ) {
			link.addEventListener( 'click', function() {
				setFilter( link.getAttribute( 'data-filter' ) || 'all' );
			} );
		} );

		if ( copy ) {
			copy.addEventListener( 'click', onCopySite );
		}

		window.addEventListener( 'message', onIframeMessage, true );

		Array.prototype.forEach.call( wrap.querySelectorAll( 'details.jezpress-menu' ), function( menu ) {
			menu.addEventListener( 'toggle', function() {
				if ( menu.open ) {
					closeMenus( menu );
				}
			} );
		} );
		document.addEventListener( 'click', function( event ) {
			if ( ! event.target.closest( 'details.jezpress-menu' ) ) {
				closeMenus( null );
			}
		} );
		document.addEventListener( 'keydown', function( event ) {
			if ( 'Escape' === event.key ) {
				closeMenus( null );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );
