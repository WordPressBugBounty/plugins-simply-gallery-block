( ( window, document ) => {
	const namespace = 'PgcSgbMediaFoldersModalBootstrap';
	const rootSelector = '.pgc-sgb-media-folders-modal-root';
	const mobileRootSelector = '.pgc-sgb-media-folders-modal-mobile-root';
	const activeClass = 'pgc-sgb-media-folders-modal-active';
	const mobileActiveClass = 'pgc-sgb-media-folders-modal-mobile-active';
	const placeholderClass = 'pgc-sgb-media-folders-modal-menu-view';
	const mobilePlaceholderClass = 'pgc-sgb-media-folders-modal-mobile-view';
	const patchedFlag = 'pgcSgbMediaFoldersModalBootstrapPatched';
	const observerProp = 'pgcSgbMediaFoldersModalObserver';
	const sessionProp = 'pgcSgbMediaFoldersModalSession';
	const mobileQuery = '(max-width: 1032px)';
	let observedFrames = [];
	let scheduledFrames = [];
	let nextSessionId = 1;

	function createFrameSession() {
		const sessionId = nextSessionId;

		nextSessionId += 1;

		return {
			sessionId,
			activeFolderId: -1,
			activeTag: '',
		};
	}

	function getFrameSession( frame ) {
		if ( ! frame ) {
			return createFrameSession();
		}

		if ( ! frame[ sessionProp ] ) {
			frame[ sessionProp ] = createFrameSession();
		}

		return frame[ sessionProp ];
	}

	function updateFrameSession( frame, nextSession ) {
		const session = getFrameSession( frame );

		if ( ! nextSession ) {
			return session;
		}

		if ( Object.prototype.hasOwnProperty.call( nextSession, 'activeFolderId' ) ) {
			const folderId = parseInt( nextSession.activeFolderId, 10 );
			session.activeFolderId = Number.isNaN( folderId ) ? -1 : folderId;
		}

		if ( Object.prototype.hasOwnProperty.call( nextSession, 'activeTag' ) ) {
			session.activeTag = String( nextSession.activeTag || '' ).trim();
		}

		return session;
	}

	function getWpMedia() {
		if ( ! window.wp || ! window.wp.media || ! window.wp.media.view ) {
			return null;
		}

		return window.wp.media;
	}

	function isUploadLibraryPage() {
		return document.body && document.body.classList.contains( 'upload-php' );
	}

	function isEligibleFrame( frame ) {
		if ( ! frame || ! frame.$el || isUploadLibraryPage() ) {
			return false;
		}

		if ( frame.options && frame.options.modal === false ) {
			return false;
		}

		if ( frame.modal ) {
			return true;
		}

		return !! frame.$el.closest( '.media-modal' ).length;
	}

	function getFrameElement( frame ) {
		if ( ! frame || ! frame.el ) {
			return null;
		}

		return frame.el;
	}

	function getMenuElement( frame ) {
		const frameElement = getFrameElement( frame );

		if ( ! frameElement ) {
			return null;
		}

		return frameElement.querySelector( '.media-frame-menu' );
	}

	function isMobileViewport() {
		if ( window.matchMedia ) {
			return window.matchMedia( mobileQuery ).matches;
		}

		return window.innerWidth <= 1032;
	}

	function getFrameState( frame ) {
		if ( ! frame || typeof frame.state !== 'function' ) {
			return null;
		}

		return frame.state();
	}

	function getFrameStateValue( state, key ) {
		if ( ! state ) {
			return '';
		}

		if ( typeof state.get === 'function' ) {
			return String( state.get( key ) || '' ).trim();
		}

		return String( state[ key ] || '' ).trim();
	}

	function getFrameStateId( frame ) {
		const state = getFrameState( frame );

		return state && state.id ? String( state.id ).trim() : '';
	}

	function isGalleryLibraryState( frame ) {
		const state = getFrameState( frame );
		const stateId = getFrameStateId( frame );
		const title = getFrameStateValue( state, 'title' ).toLowerCase();

		return stateId === 'gallery-library'
			|| title === 'create gallery';
	}

	function frameHasGalleryFlow( frame ) {
		if ( getFrameStateId( frame ).indexOf( 'gallery' ) !== -1 ) {
			return true;
		}

		if ( ! frame || ! frame.states || typeof frame.states.each !== 'function' ) {
			return false;
		}

		let hasGalleryState = false;

		frame.states.each( ( state ) => {
			if ( state && state.id && String( state.id ).indexOf( 'gallery' ) !== -1 ) {
				hasGalleryState = true;
			}
		} );

		return hasGalleryState;
	}

	function canShowMobileRoot( frame ) {
		return ! frameHasGalleryFlow( frame ) || isGalleryLibraryState( frame );
	}

	function getRootPresentation( root ) {
		return root && root.pgcSgbMediaFoldersModalPresentation
			? root.pgcSgbMediaFoldersModalPresentation
			: 'desktop';
	}

	function dispatchRootReady( frame, root ) {
		if ( ! root ) {
			return;
		}

		const session = getFrameSession( frame );
		const presentation = getRootPresentation( root );

		root.pgcSgbMediaFoldersModalSession = session;

		const event = new window.CustomEvent( 'pgcSgbMediaFoldersModalRootReady', {
			detail: {
				frame,
				presentation,
				root,
				session,
			},
		} );

		document.dispatchEvent( event );
	}

	function removeMobileRoot( frameElement ) {
		if ( ! frameElement ) {
			return;
		}

		const root = frameElement.querySelector( mobileRootSelector );
		const placeholder = frameElement.querySelector( `.${ mobilePlaceholderClass }` );

		if ( root ) {
			root.pgcSgbMediaFoldersModalPresentation = 'hidden';
			dispatchRootReady( root.pgcSgbMediaFrame || null, root );
		}

		if ( placeholder && placeholder.parentNode ) {
			placeholder.parentNode.removeChild( placeholder );
		}

		frameElement.classList.remove( mobileActiveClass );
	}

	function shouldRefreshFrameRoot( frameElement ) {
		const isMobile = isMobileViewport();
		const root = frameElement.querySelector(
			isMobile ? mobileRootSelector : rootSelector,
		);
		const expectedActiveClass = isMobile ? mobileActiveClass : activeClass;

		return ! root
			|| ! root.hasChildNodes()
			|| ( ! isMobile && frameElement.classList.contains( 'hide-menu' ) )
			|| ! frameElement.classList.contains( expectedActiveClass );
	}

	function observeFrame( frame, frameElement ) {
		if (
			! window.MutationObserver
			|| ! frame
			|| ! frameElement
			|| frameElement[ observerProp ]
		) {
			return;
		}

		if ( observedFrames.indexOf( frame ) === -1 ) {
			observedFrames.push( frame );
		}

		frameElement[ observerProp ] = new window.MutationObserver( () => {
			if ( shouldRefreshFrameRoot( frameElement ) ) {
				// eslint-disable-next-line no-use-before-define
				scheduleEnsureRoot( frame );
			}
		} );
		frameElement[ observerProp ].observe( frameElement, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class' ],
		} );
	}

	function ensureDesktopRoot( frame, frameElement ) {
		const menuElement = getMenuElement( frame );

		if ( ! frameElement || ! menuElement ) {
			return null;
		}

		let root = frameElement.querySelector( rootSelector );

		if ( ! root ) {
			const placeholder = document.createElement( 'div' );
			placeholder.className = placeholderClass;
			placeholder.innerHTML = '<div class="pgc-sgb-media-folders-modal-root"></div>';
			menuElement.appendChild( placeholder );
			root = placeholder.querySelector( rootSelector );
		}

		frameElement.classList.remove( mobileActiveClass );
		frameElement.classList.remove( 'hide-menu' );
		frameElement.classList.add( activeClass );
		root.pgcSgbMediaFoldersModalPresentation = 'desktop';

		return root;
	}

	function ensureMobileRoot( frame, frameElement ) {
		if ( ! frameElement ) {
			return null;
		}

		if ( ! canShowMobileRoot( frame ) ) {
			removeMobileRoot( frameElement );
			return null;
		}

		let root = frameElement.querySelector( mobileRootSelector );

		if ( ! root ) {
			const placeholder = document.createElement( 'div' );
			placeholder.className = mobilePlaceholderClass;
			placeholder.innerHTML = '<div class="pgc-sgb-media-folders-modal-mobile-root"></div>';
			frameElement.appendChild( placeholder );
			root = placeholder.querySelector( mobileRootSelector );
		}

		frameElement.classList.remove( activeClass );
		frameElement.classList.add( mobileActiveClass );
		root.pgcSgbMediaFoldersModalPresentation = 'mobile';

		return root;
	}

	function ensureRoot( frame ) {
		if ( ! isEligibleFrame( frame ) ) {
			return null;
		}

		const frameElement = getFrameElement( frame );
		const root = isMobileViewport()
			? ensureMobileRoot( frame, frameElement )
			: ensureDesktopRoot( frame, frameElement );

		if ( ! root ) {
			return null;
		}

		root.pgcSgbMediaFrame = frame;
		root.pgcSgbMediaFoldersModalSession = getFrameSession( frame );
		observeFrame( frame, frameElement );
		dispatchRootReady( frame, root );

		return root;
	}

	function scheduleEnsureRoot( frame ) {
		if ( ! frame || scheduledFrames.indexOf( frame ) !== -1 ) {
			return;
		}

		scheduledFrames.push( frame );

		window.setTimeout( () => {
			scheduledFrames = scheduledFrames.filter(
				( scheduledFrame ) => scheduledFrame !== frame,
			);
			ensureRoot( frame );
		}, 0 );
	}

	function scheduleObservedFrames() {
		observedFrames = observedFrames.filter( ( frame ) => isEligibleFrame( frame ) );
		observedFrames.forEach( ( frame ) => scheduleEnsureRoot( frame ) );
	}

	function patchMediaFrame() {
		const media = getWpMedia();

		if ( ! media || ! media.view.MediaFrame ) {
			return false;
		}

		const { MediaFrame } = media.view;
		const { prototype } = MediaFrame;

		if ( prototype[ patchedFlag ] ) {
			return true;
		}

		const originalInitialize = prototype.initialize;
		const originalCreateMenu = prototype.createMenu;
		const originalRender = prototype.render;
		const originalOpen = prototype.open;

		prototype.initialize = function initialize() {
			const result = originalInitialize.apply( this, arguments );

			this.on( 'open content:render router:render toolbar:render menu:render content:render:gallery-library content:render:gallery-edit', () => {
				scheduleEnsureRoot( this );
			} );

			return result;
		};

		prototype.createMenu = function createMenu() {
			const result = originalCreateMenu.apply( this, arguments );
			scheduleEnsureRoot( this );
			return result;
		};

		prototype.render = function render() {
			const result = originalRender.apply( this, arguments );
			scheduleEnsureRoot( this );
			return result;
		};

		if ( typeof originalOpen === 'function' ) {
			prototype.open = function open() {
				const result = originalOpen.apply( this, arguments );
				scheduleEnsureRoot( this );
				return result;
			};
		}

		prototype[ patchedFlag ] = true;

		return true;
	}

	function getRoots() {
		return Array.prototype.slice.call(
			document.querySelectorAll( `${ rootSelector }, ${ mobileRootSelector }` ),
		);
	}

	window[ namespace ] = {
		ensureRoot,
		getSession: getFrameSession,
		getRoots,
		patchMediaFrame,
		updateSession: updateFrameSession,
	};

	if ( window.matchMedia ) {
		const mediaQuery = window.matchMedia( mobileQuery );

		if ( mediaQuery.addEventListener ) {
			mediaQuery.addEventListener( 'change', scheduleObservedFrames );
		} else if ( mediaQuery.addListener ) {
			mediaQuery.addListener( scheduleObservedFrames );
		}
	}

	window.addEventListener( 'resize', scheduleObservedFrames );

	patchMediaFrame();
} )( window, document );
