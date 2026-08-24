/* global WPAIA_ADMIN */
(function () {
	'use strict';

	if ( typeof WPAIA_ADMIN === 'undefined' ) {
		return;
	}

	/** ---------------------------------------------------------------
	 * Toast notifications
	 * ------------------------------------------------------------- */
	function toast( message, type ) {
		var root = document.getElementById( 'wpaia-toast-root' );
		if ( ! root ) {
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'wpaia-toast wpaia-toast-' + ( type || 'success' );
		el.textContent = message;
		root.appendChild( el );

		requestAnimationFrame( function () {
			el.classList.add( 'wpaia-toast-visible' );
		} );

		setTimeout( function () {
			el.classList.remove( 'wpaia-toast-visible' );
			setTimeout( function () {
				el.remove();
			}, 300 );
		}, 3200 );
	}

	/** ---------------------------------------------------------------
	 * Button loading state helper
	 * ------------------------------------------------------------- */
	function setLoading( btn, loading ) {
		if ( ! btn ) {
			return;
		}
		var spinner = btn.querySelector( '.wpaia-spinner' );
		btn.disabled = !! loading;
		btn.classList.toggle( 'wpaia-is-loading', !! loading );
		if ( spinner ) {
			spinner.hidden = ! loading;
		}
	}

	/** ---------------------------------------------------------------
	 * AJAX helper (admin-ajax.php)
	 * ------------------------------------------------------------- */
	function ajaxPost( action, data ) {
		var body = new URLSearchParams( Object.assign( { action: action, nonce: WPAIA_ADMIN.nonce }, data || {} ) );

		return fetch( WPAIA_ADMIN.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/** ---------------------------------------------------------------
	 * Build / Rebuild Index
	 * ------------------------------------------------------------- */
	var buildBtn = document.getElementById( 'wpaia-build-index-btn' );
	if ( buildBtn ) {
		buildBtn.addEventListener( 'click', function () {
			setLoading( buildBtn, true );
			ajaxPost( 'wpaia_build_index' )
				.then( function ( res ) {
					setLoading( buildBtn, false );
					if ( res.success ) {
						toast( 'Index built! ' + res.data.count + ' items indexed.', 'success' );
						var countEl = document.getElementById( 'wpaia-stat-indexed' );
						if ( countEl ) {
							countEl.textContent = res.data.count;
						}
						var atEl = document.getElementById( 'wpaia-stat-indexed-at' );
						if ( atEl ) {
							atEl.textContent = 'Last built: just now';
						}
					} else {
						toast( ( res.data && res.data.message ) || 'Something went wrong.', 'error' );
					}
				} )
				.catch( function () {
					setLoading( buildBtn, false );
					toast( 'Network error while building the index.', 'error' );
				} );
		} );
	}

	/** ---------------------------------------------------------------
	 * Settings save
	 * ------------------------------------------------------------- */
	var saveBtn = document.getElementById( 'wpaia-save-settings-btn' );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function () {
			var get = function ( id ) {
				var el = document.getElementById( id );
				return el ? el : null;
			};

			var brandEl = get( 'wpaia-brand-name' );
			var colorEl = get( 'wpaia-primary-color' );
			var toneEl = get( 'wpaia-tone' );
			var chatEl = get( 'wpaia-enable-chat' );
			var seoEl = get( 'wpaia-enable-seo' );
			var faqEl = get( 'wpaia-enable-faq' );

			setLoading( saveBtn, true );

			ajaxPost( 'wpaia_save_settings', {
				brand_name: brandEl ? brandEl.value : '',
				primary_color: colorEl ? colorEl.value : '',
				tone: toneEl ? toneEl.value : 'professional',
				enable_chat: chatEl && chatEl.checked ? 1 : 0,
				enable_seo: seoEl && seoEl.checked ? 1 : 0,
				enable_faq: faqEl && faqEl.checked ? 1 : 0,
			} )
				.then( function ( res ) {
					setLoading( saveBtn, false );
					if ( res.success ) {
						toast( 'Settings saved!', 'success' );
					} else {
						toast( ( res.data && res.data.message ) || 'Could not save settings.', 'error' );
					}
				} )
				.catch( function () {
					setLoading( saveBtn, false );
					toast( 'Network error while saving settings.', 'error' );
				} );
		} );
	}
} )();
