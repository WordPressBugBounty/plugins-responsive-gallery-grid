/*
    Responsive Gallery Grid — block editor integration. Adds an RGG panel to the native
    core/gallery block (no new block); the fields come from PHP via rggEditor (gutenberg.php)
    and map to rgg_<slug> attributes read back at render time. Uses global wp.*, no build step.
*/

( function ( wp ) {

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.compose || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;

	var config = window.rggEditor || {};
	var fields = config.fields || {};
	var slugs = Object.keys( fields );
	// Controls bound directly to native core/gallery attributes (sizeSlug, linkTo,
	// randomOrder) so their value is shared with the native block — see gutenberg.php.
	var nativeFields = config.nativeFields || {};

	var TARGET = 'core/gallery';
	var PREFIX = 'rgg_';
	// The per-gallery Native/RGG toggle. 'native'/'rgg' force that mode; the default ''
	// inherits the global 'type' setting, so an untouched gallery follows the settings screen
	// (matching the front-end renderer, which defaults type to the global value).
	var TYPE_ATTR = 'rgg_type';

	// The global 'type' setting (rgg-options.php), handed over by gutenberg.php. Decides how an
	// inheriting gallery (rgg_type === '') renders; anything but 'native' means the RGG grid.
	var GLOBAL_TYPE = config.globalType === 'native' ? 'native' : 'rgg';

	// The global "On click" default (rgg-options.php link_to), in core's linkTo vocabulary
	// (none/media/attachment/lightbox). Seeded as core/gallery's linkTo default below so a fresh
	// gallery starts on this behavior — 'lightbox' (Enlarge on click) by default.
	var DEFAULT_LINK_TO = config.defaultLinkTo || 'lightbox';

	// Effective display mode for one gallery: its explicit rgg_type, else the global default.
	function isNativeGallery( attributes ) {
		var attrType = attributes[ TYPE_ATTR ];
		if ( attrType === 'native' || attrType === 'rgg' ) {
			return attrType === 'native';
		}
		return GLOBAL_TYPE === 'native';
	}

	// Parse a native aspectRatio value ('16/9', '1', 'auto') into a numeric width/height ratio;
	// 0 means 'auto'/unset, so each image keeps its own ratio (mirrors rgg_aspect_ratio_to_float).
	function parseAspectRatio( value ) {
		if ( ! value || value === 'auto' ) {
			return 0;
		}
		if ( value.indexOf( '/' ) !== -1 ) {
			var parts = value.split( '/' );
			var w = parseFloat( parts[ 0 ] );
			var h = parseFloat( parts[ 1 ] );
			return ( w > 0 && h > 0 ) ? w / h : 0;
		}
		var num = parseFloat( value );
		return num > 0 ? num : 0;
	}

	// Sidebar option panels, mirroring the global settings screen (rgg-options.php): each
	// non-empty section becomes a PanelBody, in this order. A field's section comes from its
	// `group` (gutenberg.php); the native-backed controls and any unknown group fall into
	// 'gallery'.
	var SECTIONS = [
		{ key: 'gallery',   title: 'Gallery Settings' },
		{ key: 'display',   title: 'Display Settings' },
		{ key: 'animation', title: 'Animation Settings' },
		{ key: 'captions',  title: 'Captions' }
	];

	/*
	 * In RGG mode, hide the native gallery settings panels (scoped via :has(> .rgg-panel) to
	 * those alongside ours) so the RGG panels read as the main settings. Purely visual; toggled
	 * by body.rgg-grid-mode (GridModeClass below).
	 */
	( function injectEditorStyle() {
		if ( document.getElementById( 'rgg-editor-style' ) ) {
			return;
		}
		var css =
			'body.rgg-grid-mode .block-editor-block-inspector :has(> .rgg-panel) > .components-panel__body:not(.rgg-panel),' +
			'body.rgg-grid-mode .block-editor-block-inspector :has(> .rgg-panel) > .components-tools-panel:not(.rgg-panel)' +
			'{ display: none !important; }' +
			// The display selector is a plain <select> (see renderTypeControl). appearance:none drops
			// the browser arrow; the single chevron is our own CSS background image (WP's chevron
			// path), so it renders identically everywhere — no WP InputBase, no doubled/floating arrow.
			".rgg-card-toggle-inner .rgg-display-select{display:block;width:100%;box-sizing:border-box;padding:8px 28px 8px 8px;border:1px solid #949494;border-radius:2px;background-color:#fff;color:#1e1e1e;font-size:13px;line-height:1.4;-webkit-appearance:none;appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='%231e1e1e'%3E%3Cpath%20d='M17.5%2011.6L12%2016l-5.5-4.4.9-1.2L12%2014l4.5-3.6%201%201.2z'/%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right%206px%20center;background-size:18px;}";
		var style = document.createElement( 'style' );
		style.id = 'rgg-editor-style';
		style.appendChild( document.createTextNode( css ) );
		( document.head || document.documentElement ).appendChild( style );
	} )();

	/*
	 * 1. Register one attribute per RGG option on core/gallery. No `source`, so they
	 *    serialize into the block delimiter without affecting markup. '' = inherit global.
	 */
	function addAttributes( settings, name ) {
		if ( name !== TARGET ) {
			return settings;
		}
		var attributes = {};
		// The Native/RGG toggle, plus one attribute per sidebar option.
		attributes[ TYPE_ATTR ] = { type: 'string', 'default': '' };
		slugs.forEach( function ( slug ) {
			attributes[ PREFIX + slug ] = { type: 'string', 'default': '' };
		} );
		settings.attributes = Object.assign( {}, settings.attributes, attributes );

		// Default a new gallery's link behavior to the global "On click" setting. core/gallery's
		// linkTo carries no default of its own, and its edit component only auto-assigns one (from
		// the media setting) while linkTo is still empty — so giving it a default here makes a fresh
		// gallery start on that behavior (notably 'lightbox' = Enlarge on click) without core
		// overwriting it. The toolbar still changes it per gallery.
		if ( settings.attributes.linkTo ) {
			settings.attributes.linkTo = Object.assign(
				{}, settings.attributes.linkTo, { 'default': DEFAULT_LINK_TO }
			);
		}
		return settings;
	}
	wp.hooks.addFilter( 'blocks.registerBlockType', 'rgg/gallery-attributes', addAttributes );

	/*
	 * 2. Build a single control from a field definition.
	 */
	function renderField( props, slug ) {
		var field = fields[ slug ];
		var attrName = PREFIX + slug;
		var value = props.attributes[ attrName ] || '';

		var onChange = function ( next ) {
			var update = {};
			update[ attrName ] = next;
			props.setAttributes( update );
		};

		if ( field.type === 'select' ) {
			var selectControl = {
				key: slug,
				label: field.label,
				value: value,
				options: field.options,
				onChange: onChange
			};
			// The lightbox engine only applies to galleries linked with "Enlarge on click"; for
			// None / Media file / Attachment page it has no effect (see gutenberg.php). Help text
			// is a React element so the emphasis renders (a plain string would escape the <em>).
			if ( slug === 'lightbox' ) {
				selectControl.help = el(
					'span',
					{},
					'Only takes effect for images that are set to ',
					el( 'em', {}, 'Enlarge on click' )
				);
			}
			return el( SelectControl, selectControl );
		}

		var control = {
			key: slug,
			label: field.label,
			value: value,
			placeholder: field.placeholder,
			onChange: onChange
		};

		// Numeric options get a number input (PHP supplies type/step/min); still stored as a
		// string attribute ('' = inherit) like the rest.
		if ( field.type === 'number' ) {
			control.type = 'number';
			if ( field.step !== undefined ) {
				control.step = field.step;
			}
			if ( field.min !== undefined ) {
				control.min = field.min;
			}
		}

		return el( TextControl, control );
	}

	/*
	 * 2a-bis. Push a new image size down to the gallery's inner core/image blocks, the way the
	 *     native "Resolution" control does. Setting the gallery's sizeSlug alone leaves each inner
	 *     image pointing at its old-size url, so the canvas preview wouldn't change; re-point each
	 *     image's url to the chosen size so the preview updates. resolveSelect waits for the media
	 *     record to load: a plain select can return undefined (record not fetched yet) and then we
	 *     set sizeSlug but never the url, leaving the preview stale until the size is toggled again.
	 *     The front end re-fetches at sizeSlug, so this is editor-only.
	 */
	function updateGalleryImagesSize( clientId, sizeSlug ) {
		if ( ! wp.data ) {
			return;
		}
		var editor = wp.data.select( 'core/block-editor' );
		var block = editor.getBlock( clientId );
		if ( ! block || ! block.innerBlocks || ! block.innerBlocks.length ) {
			return;
		}
		var dispatch = wp.data.dispatch( 'core/block-editor' );
		var resolver = wp.data.resolveSelect ? wp.data.resolveSelect( 'core' ) : null;

		block.innerBlocks.forEach( function ( image ) {
			var attrs = image.attributes || {};
			if ( image.name !== IMAGE || ! attrs.id ) {
				return;
			}

			// Re-point one image once its media record is available: the chosen size's url, or the
			// full-size source_url when that size has no entry ('full', or an image smaller than the
			// size threshold) — matching what the front end (wp_get_attachment_image) loads.
			var apply = function ( media ) {
				var sizes = media && media.media_details && media.media_details.sizes;
				var sizeData = sizes && sizes[ sizeSlug ];
				var url = ( sizeData && sizeData.source_url )
					? sizeData.source_url
					: ( media && media.source_url );
				var update = { sizeSlug: sizeSlug };
				if ( url ) {
					update.url = url;
				}
				dispatch.updateBlockAttributes( image.clientId, update );
			};

			if ( resolver && resolver.getMedia ) {
				resolver.getMedia( attrs.id ).then( apply );
			} else {
				apply( wp.data.select( 'core' ).getMedia( attrs.id ) );
			}
		} );
	}

	/*
	 * 2b. Build a control bound directly to a native core/gallery attribute (not an rgg_<slug>
	 *     attribute), so the value is shared with the native block and persists across the
	 *     Native <-> RGG toggle. The renderer reads these same attributes (gutenberg.php).
	 */
	function renderNativeField( props, name ) {
		var field = nativeFields[ name ];
		var value = props.attributes[ name ];

		var onChange = function ( next ) {
			var update = {};
			update[ name ] = next;
			props.setAttributes( update );
			// The "Resolution" control: also re-point the inner images so the preview updates.
			if ( name === 'sizeSlug' ) {
				updateGalleryImagesSize( props.clientId, next );
			}
		};

		if ( field.type === 'toggle' ) {
			return el( ToggleControl, {
				key: 'native-' + name,
				label: field.label,
				checked: !! value,
				onChange: onChange
			} );
		}

		// Select: fall back to the field's default so an untouched attribute shows the same
		// value the native block (and the renderer) would use.
		var current = ( value === undefined || value === null )
			? ( field[ 'default' ] || '' )
			: value;
		return el( SelectControl, {
			key: 'native-' + name,
			label: field.label,
			value: current,
			options: field.options,
			onChange: onChange
		} );
	}

	/*
	 * 3a. The gallery display selector (Default / Native / RGG), reused below. Its value is the
	 *     raw rgg_type attribute: '' = inherit the global 'type' (the first option's label shows
	 *     what that currently resolves to), 'native'/'rgg' set the mode explicitly.
	 */
	function renderTypeControl( typeValue, setType ) {
		var globalLabel = GLOBAL_TYPE === 'native' ? 'Native Gallery' : 'Responsive Gallery Grid';
		// A plain <select>, not wp.components SelectControl: in this portal under the block card the
		// component's InputBase styles are unreliable across WP versions (the chevron suffix floats
		// past the box, or doubles up with the native arrow). A native select is self-contained and
		// always renders exactly one arrow; .rgg-display-select styles it to match.
		return el( 'select', {
			className: 'rgg-display-select',
			value: typeValue,
			onChange: function ( event ) { setType( event.target.value ); }
		},
			el( 'option', { value: '' }, 'Default (' + globalLabel + ')' ),
			el( 'option', { value: 'native' }, 'Native Gallery' ),
			el( 'option', { value: 'rgg' }, 'Responsive Gallery Grid' )
		);
	}

	/*
	 * 3b. Portal the toggle in just after core's block card (no slot exists there), kept in
	 *     place with a MutationObserver. Renders nothing if the card is absent (the inspector
	 *     reliably paints it for the selected block).
	 */
	function CardTogglePortal( props ) {
		var useState = wp.element.useState;
		var useEffect = wp.element.useEffect;
		var createPortal = wp.element.createPortal;

		var state = useState( null );
		var container = state[ 0 ];
		var setContainer = state[ 1 ];

		useEffect( function () {
			var cancelled = false;
			var attempts = 0;
			var node = null;
			var observer = null;

			function ensure() {
				if ( cancelled ) {
					return;
				}
				var card = document.querySelector(
					'.block-editor-block-inspector .block-editor-block-card'
				);
				if ( ! card ) {
					// Inspector may not be painted yet; retry on the next frames.
					if ( attempts++ < 30 ) {
						window.requestAnimationFrame( ensure );
					}
					return;
				}
				if ( ! node ) {
					node = document.createElement( 'div' );
					node.className = 'rgg-card-toggle';
				}
				// (Re)insert directly after the block card if it drifted out.
				if ( node.previousElementSibling !== card ) {
					card.parentNode.insertBefore( node, card.nextSibling );
				}
				setContainer( node );

				if ( ! observer && window.MutationObserver ) {
					observer = new window.MutationObserver( function () {
						if ( ! cancelled && node && ! node.parentNode ) {
							ensure();
						}
					} );
					observer.observe( card.parentNode, { childList: true } );
				}
			}

			ensure();

			return function () {
				cancelled = true;
				if ( observer ) {
					observer.disconnect();
				}
				if ( node && node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			};
		}, [] );

		if ( ! container ) {
			return null;
		}
		return createPortal( props.children, container );
	}

	/*
	 * 3b-bis. Toggle body.rgg-grid-mode (drives the CSS above) for the selected gallery only,
	 *         so multiple galleries don't fight over the global class.
	 */
	function GridModeClass( props ) {
		var useEffect = wp.element.useEffect;
		useEffect( function () {
			var cls = 'rgg-grid-mode';
			if ( props.active ) {
				document.body.classList.add( cls );
			} else {
				document.body.classList.remove( cls );
			}
			return function () {
				document.body.classList.remove( cls );
			};
		}, [ props.active ] );
		return null;
	}

	/*
	 * 3c. Inject the card toggle and the option panels.
	 */
	var withControls = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( props.name !== TARGET ) {
				return el( BlockEdit, props );
			}

			// Native when the gallery's own selector says so, or when it inherits a global
			// 'type' of native (rgg_type === ''); see isNativeGallery.
			var isNative = isNativeGallery( props.attributes );
			// The raw stored value drives the selector itself ('' = the Default option).
			var typeValue = props.attributes[ TYPE_ATTR ] || '';
			var setType = function ( next ) {
				var update = {};
				update[ TYPE_ATTR ] = next;
				props.setAttributes( update );
			};

			// The display selector, portaled under the block card in the inspector sidebar; only
			// for the selected block.
			var cardToggle = props.isSelected ? el(
				CardTogglePortal,
				{ key: 'rgg-card-toggle' },
				el(
					'div',
					{
						className: 'rgg-card-toggle-inner',
						style: { padding: '16px', borderBottom: '1px solid #e0e0e0' }
					},
					el( 'p', { style: { margin: '0 0 8px', fontWeight: 600 } }, 'Gallery display' ),
					renderTypeControl( typeValue, setType )
				)
			) : null;

			// Collect the controls per section (see SECTIONS); the native-backed controls belong
			// with Gallery Settings. In native mode the RGG options have no effect, so none are
			// built (no RGG panels render) and the block's own panels are what's shown instead.
			var groups = {};
			SECTIONS.forEach( function ( section ) {
				groups[ section.key ] = [];
			} );

			if ( ! isNative ) {
				// Native-backed controls (image size, link, randomize order). Only in RGG mode:
				// in native mode the block's own panel shows them, and here that panel is hidden.
				Object.keys( nativeFields ).forEach( function ( name ) {
					groups.gallery.push( renderNativeField( props, name ) );
				} );

				slugs.forEach( function ( slug ) {
					var group = groups[ fields[ slug ].group ] || groups.gallery;
					group.push( renderField( props, slug ) );
				} );
			}

			// One PanelBody per non-empty section (only populated in RGG mode); only the first
			// (Gallery Settings) opens by default, the rest start collapsed.
			var panels = [];
			SECTIONS.forEach( function ( section, index ) {
				if ( ! groups[ section.key ].length ) {
					return;
				}
				panels.push(
					el(
						PanelBody,
						{
							key: 'rgg-' + section.key,
							className: 'rgg-panel',
							title: section.title,
							initialOpen: index === 0
						},
						groups[ section.key ]
					)
				);
			} );

			return el(
				Fragment,
				{},
				el( BlockEdit, props ),
				cardToggle,
				props.isSelected ? el( GridModeClass, { key: 'rgg-mode', active: ! isNative } ) : null,
				el( InspectorControls, {}, panels )
			);
		};
	}, 'withRggGalleryControls' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'rgg/gallery-controls', withControls );

	// §6's rAF-debounced scheduler, exposed so §4 can poke it on a behavior change. Null until
	// the controller initializes below, so callers must guard.
	var scheduleLastRow = null;

	/*
	 * 4. Approximate RGG grid preview in the editor canvas. Tag each RGG-mode gallery with
	 *    .rgg-editor-grid, an rgg-lrb-<behavior> class and --rgg-row-h / --rgg-gap vars, so
	 *    css/editor-grid.css can flexbox-justify the images. Declarative; alignment only.
	 */
	function galleryGlobalDefault( slug, fallback ) {
		var field = fields[ slug ];
		var value = field && field.placeholder;
		return ( value === undefined || value === '' ) ? fallback : value;
	}

	function galleryNumber( attrValue, slug, fallback ) {
		var raw = ( attrValue !== undefined && attrValue !== '' )
			? attrValue
			: galleryGlobalDefault( slug, fallback );
		var num = parseInt( raw, 10 );
		return isNaN( num ) ? fallback : num;
	}

	// Effective last-row behavior: the gallery's own rgg_lastrowbehavior, else the global
	// default, else 'last_row_same_height' (the only one the free front end implements).
	function galleryLastRowBehavior( attrs ) {
		var attr = attrs[ PREFIX + 'lastrowbehavior' ];
		if ( attr !== undefined && attr !== '' ) {
			return attr;
		}
		var field = fields.lastrowbehavior;
		return ( field && field.global ) ? field.global : 'last_row_same_height';
	}

	var withGridPreview = createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var useEffect = wp.element.useEffect;
			var attrs = props.attributes || ( props.block && props.block.attributes ) || {};
			var isGrid = props.name === TARGET && ! isNativeGallery( attrs );

				// The behavior is applied below as a class, which §6's observer ignores (it watches
				// 'style' only), so poke the controller directly on a change to re-tag the last row.
				// Effect re-runs only on a real change, so no loop.
			var behavior = isGrid ? galleryLastRowBehavior( attrs ) : null;
			useEffect( function () {
				if ( isGrid && scheduleLastRow ) {
					scheduleLastRow();
				}
			}, [ isGrid, behavior ] );

			if ( ! isGrid ) {
				return el( BlockListBlock, props );
			}

			var rowH = galleryNumber( attrs.rgg_maxrowheight, 'maxrowheight', 220 );
			var gap = galleryNumber( attrs.rgg_margin, 'margin', 8 );

			// Tag the gallery with its last-row behavior so css/editor-grid.css can align (or
			// hide) the last row; the actual last-row tiles are found by the controller in §6.
			// rgg-crop switches the tiles to cover-fill when a fixed aspect ratio is set.
			var crop = parseAspectRatio( attrs.aspectRatio ) > 0 ? ' rgg-crop' : '';
			var className = ( ( props.className || '' ) + ' rgg-editor-grid rgg-lrb-' + behavior + crop ).trim();
			var wrapperProps = Object.assign( {}, props.wrapperProps );
			wrapperProps.style = Object.assign( {}, wrapperProps.style, {
				'--rgg-row-h': rowH + 'px',
				'--rgg-gap': gap + 'px'
			} );

			return el( BlockListBlock, Object.assign( {}, props, {
				className: className,
				wrapperProps: wrapperProps
			} ) );
		};
	}, 'withRggGridPreview' );

	wp.hooks.addFilter( 'editor.BlockListBlock', 'rgg/gallery-grid-preview', withGridPreview );

	/*
	 * 5. Per-image aspect ratio for the justified preview. CSS can't read an image's intrinsic
	 *    ratio, so expose it as --rgg-ar (from the attachment dimensions in the data store) on
	 *    each core/image in an RGG-mode gallery. Unset until the media record resolves.
	 */
	var IMAGE = 'core/image';

	var withImageAspectRatio = createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			// useSelect must run on every render (rules of hooks); the work is gated inside.
			var ratio = wp.data.useSelect( function ( select ) {
				if ( props.name !== IMAGE ) {
					return null;
				}
				var attrs = props.attributes || {};
				if ( ! attrs.id ) {
					return null;
				}
				// Only annotate images inside an RGG-mode core/gallery.
				var editor = select( 'core/block-editor' );
				var rootId = editor.getBlockRootClientId( props.clientId );
				if ( ! rootId ) {
					return null;
				}
				var parent = editor.getBlock( rootId );
				if ( ! parent || parent.name !== TARGET || isNativeGallery( parent.attributes ) ) {
					return null;
				}
				// A fixed gallery aspect ratio crops every tile to one shape, so report that ratio
				// for all images instead of their own (matches the front-end .rgg-crop renderer).
				var fixed = parseAspectRatio( parent.attributes.aspectRatio );
				if ( fixed ) {
					return fixed;
				}
				var media = select( 'core' ).getMedia( attrs.id );
				var details = media && media.media_details;
				if ( ! details ) {
					return null;
				}
				// Use the dimensions of the size actually loaded (core/gallery sizeSlug, default
				// 'large'), not the intrinsic full-size ones: cropped sizes like 'thumbnail' (a
				// square by default) have a different aspect ratio than the original. Fall back to
				// the full size when the chosen size has no entry ('full', or an image smaller than
				// the size threshold), matching what the front end (wp_get_attachment_image) loads.
				var sizeSlug = parent.attributes.sizeSlug || 'large';
				var sized = details.sizes && details.sizes[ sizeSlug ];
				if ( sized && sized.width && sized.height ) {
					return sized.width / sized.height;
				}
				if ( details.width && details.height ) {
					return details.width / details.height;
				}
				return null;
			}, [ props.name, props.clientId, ( props.attributes || {} ).id ] );

			if ( ! ratio ) {
				return el( BlockListBlock, props );
			}

			var wrapperProps = Object.assign( {}, props.wrapperProps );
			wrapperProps.style = Object.assign( {}, wrapperProps.style, {
				'--rgg-ar': ratio
			} );

			return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
		};
	}, 'withRggImageAspectRatio' );

	wp.hooks.addFilter( 'editor.BlockListBlock', 'rgg/image-aspect-ratio', withImageAspectRatio );

	/*
	 * 6. Compute the justified layout in the canvas preview, porting js/main.js init_grid
	 *    (pack rows, then scale to fit so a full row stays <= max row height). Widths go to a
	 *    managed <style> keyed by data-rgg-lr / data-rgg-i; rAF-debounced with settle passes.
	 */
	( function lastRowController() {

		if ( ! window.requestAnimationFrame ) {
			return;
		}

		// 1px safety so per-tile widths (rounded for CSS) never sum to *more* than the row width,
		// which flexbox would resolve by wrapping a tile onto an unwanted extra line.
		var SAFETY = 1;
		var lrSeq = 0;
		var rafId = null;
		var boundDoc = null;
		var contentObservers = [];

		// The block canvas is an iframe in modern WP, but a plain element in some setups; this
		// resolves whichever document currently holds the rendered blocks.
		function canvasDoc() {
			var iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
			return ( iframe && iframe.contentDocument ) ? iframe.contentDocument : document;
		}

		// The flex container is the gallery itself when .rgg-editor-grid landed on it, else the
		// .wp-block-gallery it wraps (matches the dual selector in css/editor-grid.css).
		function flexContainer( grid ) {
			if ( grid.classList.contains( 'wp-block-gallery' ) ) {
				return grid;
			}
			return grid.querySelector( '.wp-block-gallery' ) || grid;
		}

		function childImages( container ) {
			var out = [];
			var children = container.children;
			for ( var i = 0; i < children.length; i++ ) {
				if ( children[ i ].classList && children[ i ].classList.contains( 'wp-block-image' ) ) {
					out.push( children[ i ] );
				}
			}
			return out;
		}

		// The effective last-row behavior, read from the rgg-lrb-<behavior> class that §4 stamps on
		// the gallery (that's how the resolved per-gallery value reaches the canvas).
		function behaviorOf( grid ) {
			var classes = ( grid.className || '' ).split( /\s+/ );
			for ( var i = 0; i < classes.length; i++ ) {
				if ( classes[ i ].indexOf( 'rgg-lrb-' ) === 0 ) {
					return classes[ i ].slice( 8 );
				}
			}
			return 'normal';
		}

		// Port of js/main.js init_grid: pack tiles into a row until they overflow, then scale the
		// row to fit so a full row lands at <= max row height. Returns per-tile { width, marginLeft,
		// dim }; height follows from width / aspect-ratio. The last row is laid out per the behavior
		// (capped at the row's own fill scale so it never grows wider than the container).
		function computeLayout( tiles, width, rowH, gap, behavior ) {
			var out = new Array( tiles.length );
			var prevScale = 1;
			var row = [];
			var baseSum = 0;
			var n = tiles.length;
			for ( var i = 0; i < n; i++ ) {
				var ar = parseFloat( tiles[ i ].style.getPropertyValue( '--rgg-ar' ) ) || 1.5;
				row.push( { idx: i, base: ar * rowH } );
				baseSum += ar * rowH;
				var avail = width - ( row.length - 1 ) * gap;
				var rowIsFull = baseSum > avail;
				var isLast = i === n - 1;
				var scale = 1;
				var offset = 0;
				var dim = false;

				if ( rowIsFull ) {
					scale = ( avail - SAFETY ) / baseSum;
					prevScale = scale;
				}

				if ( isLast && ! rowIsFull ) {
					var fillScale = ( avail - SAFETY ) / baseSum;
					if ( behavior === 'force_justified' ) {
						scale = fillScale;
					} else if ( behavior === 'center' ) {
						scale = Math.min( prevScale, fillScale );
						offset = ( avail - baseSum * scale ) / 2;
					} else if ( behavior === 'align_right' ) {
						scale = Math.min( prevScale, fillScale );
						// -SAFETY: right-aligning spans the row to the full width, so trim a pixel
						// to stay clear of a sub-pixel wrap (matches the SAFETY on full rows).
						offset = avail - baseSum * scale - SAFETY;
					} else if ( behavior === 'last_row_same_height' ) {
						scale = Math.min( prevScale, fillScale );
					} else if ( behavior === 'hide' ) {
						dim = true; // editor keeps the row visible-but-faded; the front end drops it
					}
					// 'normal' / anything else: scale stays 1 (base height, left-aligned).
				}

				if ( rowIsFull || isLast ) {
					for ( var r = 0; r < row.length; r++ ) {
						out[ row[ r ].idx ] = {
							width: row[ r ].base * scale,
							marginLeft: r === 0 ? offset : 0,
							dim: dim
						};
					}
					row = [];
					baseSum = 0;
				}
			}
			return out;
		}

		function relayout() {
			rafId = null;
			var doc = canvasDoc();
			var grids = doc.querySelectorAll( '.rgg-editor-grid' );
			var rules = '';
			for ( var i = 0; i < grids.length; i++ ) {
				var grid = grids[ i ];
				var container = flexContainer( grid );
				var cs = ( container.ownerDocument.defaultView || window ).getComputedStyle( container );
				// Lay out within the content box: flex items only get the content width, and
				// overestimating would push rows past the edge.
				var width = container.clientWidth -
					( parseFloat( cs.paddingLeft ) || 0 ) - ( parseFloat( cs.paddingRight ) || 0 );

				// Not laid out yet (width 0 while the iframe sizes, or hidden); skip it. The
				// observers and settle passes re-run once it has a width.
				if ( width < 1 ) {
					continue;
				}

				var rowH = parseFloat( cs.getPropertyValue( '--rgg-row-h' ) ) || 220;
				var gap = parseFloat( cs.getPropertyValue( '--rgg-gap' ) ) || 0;
				var tiles = childImages( container );
				var layout = computeLayout( tiles, width, rowH, gap, behaviorOf( grid ) );

				// Address the sizes from the managed stylesheet via data-rgg-lr (gallery) + data-rgg-i
				// (tile): a stylesheet because React would strip inline styles, and data-* attrs don't
				// trip the 'style' observer. Guarded so a stable layout is a no-op.
				var key = grid.getAttribute( 'data-rgg-lr' );
				if ( ! key ) {
					key = String( ++lrSeq );
					grid.setAttribute( 'data-rgg-lr', key );
				}
				for ( var j = 0; j < tiles.length; j++ ) {
					var t = layout[ j ];
					if ( ! t ) {
						continue;
					}
					if ( tiles[ j ].getAttribute( 'data-rgg-i' ) !== String( j ) ) {
						tiles[ j ].setAttribute( 'data-rgg-i', String( j ) );
					}
					// flex-grow 0 + the exact basis = the front-end width; flex-shrink 1 only absorbs
					// sub-pixel rounding so a full row never wraps.
					var decl = 'flex:0 1 ' + t.width.toFixed( 2 ) + 'px !important;';
					if ( t.marginLeft > 0.5 ) {
						decl += 'margin-left:' + t.marginLeft.toFixed( 2 ) + 'px !important;';
					}
					if ( t.dim ) {
						decl += 'opacity:0.35;';
					}
					rules += '.rgg-editor-grid[data-rgg-lr="' + key + '"] .wp-block-image[data-rgg-i="' + j + '"]{' + decl + '}\n';
				}
			}
			writeLayoutRules( doc, rules );
		}

		// The managed <style> lives in the canvas <head>, outside the observed body, so rewriting
		// it never re-triggers the observers. Guarded so a stable layout is a no-op.
		function writeLayoutRules( doc, rules ) {
			var styleEl = doc.getElementById( 'rgg-editor-layout' );
			if ( ! styleEl ) {
				if ( ! rules ) {
					return;
				}
				styleEl = doc.createElement( 'style' );
				styleEl.id = 'rgg-editor-layout';
				( doc.head || doc.documentElement ).appendChild( styleEl );
			}
			if ( styleEl.textContent !== rules ) {
				styleEl.textContent = rules;
			}
		}

		function schedule() {
			if ( ! rafId ) {
				rafId = window.requestAnimationFrame( relayout );
			}
		}

		// Expose the scheduler so §4 can request a re-layout the moment the behavior class changes
		// (the content MutationObserver deliberately ignores class writes; see withGridPreview).
		scheduleLastRow = schedule;

		// Watch the canvas for size and block changes, listening only for 'style' attribute
		// changes (e.g. --rgg-ar resolving); our own writes aren't 'style' attrs, so no loop.
		function observe( doc ) {
			if ( window.MutationObserver ) {
				var mo = new window.MutationObserver( schedule );
				mo.observe( doc.body || doc.documentElement, {
					childList: true,
					subtree: true,
					attributes: true,
					attributeFilter: [ 'style' ]
				} );
				contentObservers.push( mo );
			}
			if ( window.ResizeObserver && doc.body ) {
				var ro = new window.ResizeObserver( schedule );
				ro.observe( doc.body );
				contentObservers.push( ro );
			}
			// Image loads can change the canvas size, so re-measure. 'load' doesn't bubble, hence
			// capture phase; wrapped so disconnectContent() can detach it.
			doc.addEventListener( 'load', schedule, true );
			contentObservers.push( {
				disconnect: function () {
					doc.removeEventListener( 'load', schedule, true );
				}
			} );
		}

		function disconnectContent() {
			for ( var i = 0; i < contentObservers.length; i++ ) {
				contentObservers[ i ].disconnect();
			}
			contentObservers = [];
		}

		// A fresh canvas paints in stages, and a grid measured before it has a width is skipped
		// (see relayout); these delayed passes catch it without spinning rAF forever.
		function settle() {
			window.setTimeout( schedule, 200 );
			window.setTimeout( schedule, 600 );
			window.setTimeout( schedule, 1200 );
		}

		function bind() {
			var doc = canvasDoc();
			if ( doc !== boundDoc ) {
				boundDoc = doc;
				disconnectContent();
				observe( doc );
				settle(); // new canvas (initial load or iframe recreation): catch the staged paint
			}
			schedule();
		}

		function init() {
			if ( ! document.body ) {
				window.requestAnimationFrame( init );
				return;
			}
			// Parent document: only to notice the canvas iframe appearing or being replaced.
			if ( window.MutationObserver ) {
				new window.MutationObserver( bind ).observe( document.body, { childList: true, subtree: true } );
			}
			window.addEventListener( 'resize', schedule );
			bind();
		}

		init();

	} )();

} )( window.wp );
