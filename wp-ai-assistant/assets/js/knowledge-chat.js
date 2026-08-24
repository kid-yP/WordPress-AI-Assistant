/* global WPAIA_CHAT */
(function () {
	'use strict';

	if ( typeof WPAIA_CHAT === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'wpaia-chat-root' );
	if ( ! root ) {
		return;
	}

	var brand = WPAIA_CHAT.brandName || 'AI Assistant';
	var color = WPAIA_CHAT.primaryColor || '#6366f1';
	document.documentElement.style.setProperty( '--wpaia-primary', color );

	var state = {
		open: false,
		messages: [],
		indexCache: null,
	};

	/** -------------------------------------------------------------
	 * Build DOM
	 * ------------------------------------------------------------- */
	root.innerHTML =
		'<div class="wpaia-widget">' +
		'<button class="wpaia-launcher" aria-label="Open chat">' +
		'<span class="wpaia-launcher-icon">💬</span>' +
		'</button>' +
		'<div class="wpaia-window" hidden>' +
		'<div class="wpaia-chat-header">' +
		'<span class="wpaia-header-icon">✨</span>' +
		'<span class="wpaia-header-title">' + escapeHtml( brand ) + '</span>' +
		'<button class="wpaia-close-btn" aria-label="Close chat">✕</button>' +
		'</div>' +
		'<div class="wpaia-messages" id="wpaia-messages"></div>' +
		'<form class="wpaia-input-row" id="wpaia-input-form">' +
		'<input type="text" id="wpaia-input" placeholder="Ask a question…" autocomplete="off">' +
		'<button type="submit" class="wpaia-send-btn" aria-label="Send">➤</button>' +
		'</form>' +
		'</div>' +
		'</div>';

	var launcher = root.querySelector( '.wpaia-launcher' );
	var win = root.querySelector( '.wpaia-window' );
	var closeBtn = root.querySelector( '.wpaia-close-btn' );
	var messagesEl = root.querySelector( '#wpaia-messages' );
	var form = root.querySelector( '#wpaia-input-form' );
	var input = root.querySelector( '#wpaia-input' );

	launcher.addEventListener( 'click', function () {
		state.open = ! state.open;
		win.hidden = ! state.open;
		launcher.classList.toggle( 'wpaia-launcher-open', state.open );
		if ( state.open && ! state.messages.length ) {
			addMessage( 'bot', "Hi! I'm " + brand + ". Ask me anything about this site." );
		}
		if ( state.open ) {
			input.focus();
		}
	} );

	closeBtn.addEventListener( 'click', function () {
		state.open = false;
		win.hidden = true;
		launcher.classList.remove( 'wpaia-launcher-open' );
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var text = input.value.trim();
		if ( ! text ) {
			return;
		}
		input.value = '';
		addMessage( 'user', text );
		askQuestion( text );
	} );

	/** -------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------- */
	function addMessage( role, text, sources ) {
		state.messages.push( { role: role, text: text } );

		var row = document.createElement( 'div' );
		row.className = 'wpaia-msg-row wpaia-msg-' + role;

		if ( 'bot' === role ) {
			var avatar = document.createElement( 'div' );
			avatar.className = 'wpaia-avatar';
			avatar.textContent = '✨';
			row.appendChild( avatar );
		}

		var bubbleWrap = document.createElement( 'div' );
		bubbleWrap.className = 'wpaia-bubble-wrap';

		var bubble = document.createElement( 'div' );
		bubble.className = 'wpaia-bubble wpaia-bubble-' + role;
		bubble.textContent = text;
		bubbleWrap.appendChild( bubble );

		if ( sources && sources.length ) {
			var sourcesEl = document.createElement( 'div' );
			sourcesEl.className = 'wpaia-sources';
			sourcesEl.innerHTML = 'Sources: ' + sources.map( function ( s ) {
				return '<a href="' + escapeAttr( s.url ) + '" target="_blank" rel="noopener">' + escapeHtml( s.title ) + '</a>';
			} ).join( ', ' );
			bubbleWrap.appendChild( sourcesEl );
		}

		row.appendChild( bubbleWrap );
		messagesEl.appendChild( row );

		requestAnimationFrame( function () {
			row.classList.add( 'wpaia-msg-visible' );
		} );

		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	function showTyping() {
		var row = document.createElement( 'div' );
		row.className = 'wpaia-msg-row wpaia-msg-bot wpaia-typing-row';
		row.id = 'wpaia-typing-indicator';
		row.innerHTML =
			'<div class="wpaia-avatar">✨</div>' +
			'<div class="wpaia-bubble wpaia-bubble-bot wpaia-typing-bubble">' +
			'<span class="wpaia-dot"></span><span class="wpaia-dot"></span><span class="wpaia-dot"></span>' +
			'</div>';
		messagesEl.appendChild( row );
		requestAnimationFrame( function () {
			row.classList.add( 'wpaia-msg-visible' );
		} );
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	function hideTyping() {
		var el = document.getElementById( 'wpaia-typing-indicator' );
		if ( el ) {
			el.remove();
		}
	}

	/** -------------------------------------------------------------
	 * Ask a question — REST API first, local index fallback second
	 * ------------------------------------------------------------- */
	function askQuestion( question ) {
		showTyping();

		fetch( WPAIA_CHAT.restUrl + '/ask', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': WPAIA_CHAT.nonce,
			},
			body: JSON.stringify( { question: question } ),
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'REST request failed' );
				}
				return res.json();
			} )
			.then( function ( data ) {
				hideTyping();
				addMessage( 'bot', data.answer, data.sources );
			} )
			.catch( function () {
				// Fallback: try a client-side search against a cached/localStorage index.
				answerFromLocalIndex( question );
			} );
	}

	function answerFromLocalIndex( question ) {
		getLocalIndex()
			.then( function ( items ) {
				hideTyping();
				if ( ! items || ! items.length ) {
					addMessage( 'bot', "I'm having trouble reaching the knowledge base right now. Please try again shortly." );
					return;
				}

				var tokens = tokenize( question );
				var scored = items
					.map( function ( item ) {
						var score = 0;
						tokens.forEach( function ( t ) {
							score += count( item.title.toLowerCase(), t ) * 3;
							score += count( item.content.toLowerCase(), t );
						} );
						return { item: item, score: score };
					} )
					.filter( function ( s ) {
						return s.score > 0;
					} )
					.sort( function ( a, b ) {
						return b.score - a.score;
					} )
					.slice( 0, 3 );

				if ( ! scored.length ) {
					addMessage( 'bot', "I couldn't find anything about that. Try asking differently." );
					return;
				}

				var top = scored[ 0 ].item;
				var sentence = bestSentence( top.content, tokens ) || top.excerpt;
				addMessage(
					'bot',
					sentence,
					scored.map( function ( s ) {
						return { title: s.item.title, url: s.item.url };
					} )
				);
			} )
			.catch( function () {
				hideTyping();
				addMessage( 'bot', "I'm having trouble reaching the knowledge base right now. Please try again shortly." );
			} );
	}

	function getLocalIndex() {
		if ( state.indexCache ) {
			return Promise.resolve( state.indexCache );
		}

		return fetch( WPAIA_CHAT.restUrl + '/index' )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				var items = data.items || [];
				state.indexCache = items;
				try {
					localStorage.setItem( 'wpaia_index_cache', JSON.stringify( items ) );
				} catch ( e ) {
					// localStorage unavailable — ignore.
				}
				return items;
			} )
			.catch( function () {
				try {
					var cached = localStorage.getItem( 'wpaia_index_cache' );
					if ( cached ) {
						var items = JSON.parse( cached );
						state.indexCache = items;
						return items;
					}
				} catch ( e ) {
					// ignore
				}
				return [];
			} );
	}

	/** -------------------------------------------------------------
	 * Small text utilities (mirrors server-side heuristics)
	 * ------------------------------------------------------------- */
	var STOPWORDS = [ 'the', 'a', 'an', 'and', 'or', 'is', 'are', 'was', 'were', 'to', 'of', 'in',
		'on', 'for', 'with', 'as', 'at', 'by', 'from', 'that', 'this', 'it', 'what', 'which', 'who',
		'when', 'where', 'why', 'how', 'do', 'does', 'did', 'can', 'you', 'your' ];

	function tokenize( text ) {
		var matches = text.toLowerCase().match( /[a-z0-9]+/g ) || [];
		return matches.filter( function ( t ) {
			return t.length > 2 && STOPWORDS.indexOf( t ) === -1;
		} );
	}

	function count( haystack, needle ) {
		return haystack.split( needle ).length - 1;
	}

	function bestSentence( content, tokens ) {
		var sentences = content.split( /(?<=[.!?])\s+/ );
		var best = '';
		var bestScore = 0;
		sentences.forEach( function ( s ) {
			var lc = s.toLowerCase();
			var score = 0;
			tokens.forEach( function ( t ) {
				score += count( lc, t );
			} );
			if ( score > bestScore ) {
				bestScore = score;
				best = s.trim();
			}
		} );
		return best;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}
} )();
