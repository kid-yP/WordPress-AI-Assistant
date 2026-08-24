/* global ajaxurl */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var panel = document.getElementById( 'wpaia-editor-panel' );
		if ( ! panel ) {
			return;
		}

		var postId = panel.getAttribute( 'data-post-id' );
		var nonceField = document.getElementById( 'wpaia_editor_nonce_field' );
		var nonce = nonceField ? nonceField.value : '';

		/** ---------------------------------------------------------
		 * Tabs
		 * --------------------------------------------------------- */
		var tabs = panel.querySelectorAll( '.wpaia-tab' );
		var tabPanels = panel.querySelectorAll( '.wpaia-tab-panel' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) {
					t.classList.remove( 'active' );
				} );
				tabPanels.forEach( function ( p ) {
					p.classList.remove( 'active' );
				} );
				tab.classList.add( 'active' );
				var target = panel.querySelector( '[data-panel="' + tab.getAttribute( 'data-tab' ) + '"]' );
				if ( target ) {
					target.classList.add( 'active' );
				}
			} );
		} );

		/** ---------------------------------------------------------
		 * Toast (lightweight, local to editor screen)
		 * --------------------------------------------------------- */
		function toast( message, type ) {
			var root = document.getElementById( 'wpaia-toast-root' );
			if ( ! root ) {
				root = document.createElement( 'div' );
				root.id = 'wpaia-toast-root';
				document.body.appendChild( root );
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

		function setLoading( btn, loading ) {
			var spinner = btn.querySelector( '.wpaia-spinner' );
			btn.disabled = !! loading;
			if ( spinner ) {
				spinner.hidden = ! loading;
			}
		}

		function ajaxPost( action, data ) {
			var params = Object.assign( { action: action, nonce: nonce, post_id: postId }, data || {} );
			var body = new URLSearchParams( params );

			return fetch( ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( res ) {
				return res.json();
			} );
		}

		function copyButton( getText ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'wpaia-copy-btn';
			btn.title = 'Copy to clipboard';
			btn.textContent = '📋 Copy';
			btn.addEventListener( 'click', function () {
				var text = getText();
				navigator.clipboard.writeText( text ).then( function () {
					toast( 'Copied to clipboard!', 'success' );
				} ).catch( function () {
					toast( 'Could not copy — please copy manually.', 'error' );
				} );
			} );
			return btn;
		}

		function fieldBlock( label, value ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'wpaia-field-block';

			var labelEl = document.createElement( 'div' );
			labelEl.className = 'wpaia-field-label';
			labelEl.textContent = label;

			var valueRow = document.createElement( 'div' );
			valueRow.className = 'wpaia-field-value-row';

			var valueEl = document.createElement( 'div' );
			valueEl.className = 'wpaia-field-value';
			valueEl.textContent = value;

			valueRow.appendChild( valueEl );
			valueRow.appendChild( copyButton( function () {
				return value;
			} ) );

			wrap.appendChild( labelEl );
			wrap.appendChild( valueRow );
			return wrap;
		}

		/** ---------------------------------------------------------
		 * SEO / Copy generation
		 * --------------------------------------------------------- */
		var seoBtn = panel.querySelector( '[data-action="generate-seo"]' );
		if ( seoBtn ) {
			seoBtn.addEventListener( 'click', function () {
				var brief = document.getElementById( 'wpaia-brief' ).value.trim();
				if ( ! brief ) {
					toast( 'Please enter a brief first.', 'error' );
					return;
				}

				setLoading( seoBtn, true );
				ajaxPost( 'wpaia_generate_seo', { brief: brief } )
					.then( function ( res ) {
						setLoading( seoBtn, false );
						var results = document.getElementById( 'wpaia-seo-results' );
						results.innerHTML = '';
						if ( ! res.success ) {
							toast( ( res.data && res.data.message ) || 'Generation failed.', 'error' );
							return;
						}
						results.appendChild( fieldBlock( 'SEO Title', res.data.seo_title ) );
						results.appendChild( fieldBlock( 'Meta Description', res.data.meta_description ) );
						results.appendChild( fieldBlock( 'Hero Headline', res.data.hero_headline ) );
						results.appendChild( fieldBlock( 'Summary Paragraph', res.data.summary ) );
						toast( 'Content generated!', 'success' );
					} )
					.catch( function () {
						setLoading( seoBtn, false );
						toast( 'Network error.', 'error' );
					} );
			} );
		}

		/** ---------------------------------------------------------
		 * Summarizer
		 * --------------------------------------------------------- */
		var summaryBtn = panel.querySelector( '[data-action="summarize"]' );
		if ( summaryBtn ) {
			summaryBtn.addEventListener( 'click', function () {
				setLoading( summaryBtn, true );
				ajaxPost( 'wpaia_summarize' )
					.then( function ( res ) {
						setLoading( summaryBtn, false );
						var results = document.getElementById( 'wpaia-summary-results' );
						results.innerHTML = '';
						if ( ! res.success ) {
							toast( ( res.data && res.data.message ) || 'Could not summarize.', 'error' );
							return;
						}
						results.appendChild( fieldBlock( 'AI Summary', res.data.summary ) );
						toast( 'Summary generated!', 'success' );
					} )
					.catch( function () {
						setLoading( summaryBtn, false );
						toast( 'Network error.', 'error' );
					} );
			} );
		}

		/** ---------------------------------------------------------
		 * FAQ generator
		 * --------------------------------------------------------- */
		var faqBtn = panel.querySelector( '[data-action="generate-faq"]' );
		if ( faqBtn ) {
			faqBtn.addEventListener( 'click', function () {
				setLoading( faqBtn, true );
				ajaxPost( 'wpaia_generate_faq' )
					.then( function ( res ) {
						setLoading( faqBtn, false );
						var results = document.getElementById( 'wpaia-faq-results' );
						results.innerHTML = '';
						if ( ! res.success ) {
							toast( ( res.data && res.data.message ) || 'Could not generate FAQs.', 'error' );
							return;
						}
						if ( ! res.data.faqs.length ) {
							results.innerHTML = '<p class="wpaia-hint">Not enough content to generate FAQs yet.</p>';
							return;
						}
						res.data.faqs.forEach( function ( faq ) {
							var item = document.createElement( 'div' );
							item.className = 'wpaia-faq-item';
							item.innerHTML =
								'<div class="wpaia-faq-q">Q: ' + escapeHtml( faq.question ) + '</div>' +
								'<div class="wpaia-faq-a">A: ' + escapeHtml( faq.answer ) + '</div>';
							results.appendChild( item );
						} );
						toast( 'FAQs generated!', 'success' );
					} )
					.catch( function () {
						setLoading( faqBtn, false );
						toast( 'Network error.', 'error' );
					} );
			} );
		}

		/** ---------------------------------------------------------
		 * Tone changer
		 * --------------------------------------------------------- */
		var toneBtn = panel.querySelector( '[data-action="change-tone"]' );
		if ( toneBtn ) {
			toneBtn.addEventListener( 'click', function () {
				var tone = document.getElementById( 'wpaia-tone-select' ).value;
				setLoading( toneBtn, true );
				ajaxPost( 'wpaia_change_tone', { tone: tone } )
					.then( function ( res ) {
						setLoading( toneBtn, false );
						var results = document.getElementById( 'wpaia-tone-results' );
						results.innerHTML = '';
						if ( ! res.success ) {
							toast( ( res.data && res.data.message ) || 'Could not rewrite content.', 'error' );
							return;
						}
						results.appendChild( fieldBlock( 'Rewritten Content (' + tone + ')', res.data.content ) );
						toast( 'Content rewritten!', 'success' );
					} )
					.catch( function () {
						setLoading( toneBtn, false );
						toast( 'Network error.', 'error' );
					} );
			} );
		}

		function escapeHtml( str ) {
			var div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		}
	} );
} )();
