<?php

global $rgg_instance_fallback;
$rgg_instance_fallback = 10135; //give it this value for debugging purposes

function rgg_gallery_shortcode($output, $attr, $instance = false) {

    global $rgg_instance_fallback;

    if ($instance === false) {
        $instance = $rgg_instance_fallback;
        $rgg_instance_fallback++;
    }

    return rgg_render_gallery($attr, $instance);
}

/**
 * Render a gallery grid. Shared by the [gallery] shortcode and the Gutenberg
 * core/gallery block (see gutenberg.php), which pass source-specific attributes.
 *
 * @param array $attr              Gallery attributes (same keys the shortcode accepts).
 * @param int   $instance          Unique gallery instance id used to key $rgg_settings.
 * @param array $caption_overrides Map of attachment id => per-occurrence caption HTML.
 * @param array $container_atts    Extra class/style for the rgg-container (block background).
 * @param array $duotones          Map of attachment id => per-occurrence duotone slugs.
 * @param array $link_overrides    Map of attachment id => per-occurrence link (destination/href).
 * @return string Gallery HTML, or '' to fall back to native output.
 */
function rgg_render_gallery($attr, $instance, $caption_overrides = array(), $container_atts = array(), $duotones = array(), $link_overrides = array()) {

    global $rgg_settings, $rgg_options;

    $post = get_post();
    $post_id = $post->ID ?? '';

    // image_size is deprecated, but if it is set and size is empty => size = image_size.
    if (!empty($attr['image_size']) && empty($attr['size'])) {
        $attr['size'] = $attr['image_size'];
    }

    // create settings based on the attributes set in the shortcode.
    $settings_arr = shortcode_atts(array(
        'gallery_instance' => $instance,
        'type' => $rgg_options['type'],
        'class' => '',
        'ids' => $rgg_options['ids'],
        'fid' => null, // RML compatibility (https://bit.ly/3d9QSUb)
        'margin' => intval($rgg_options['margin']),
        'scale' => doubleval($rgg_options['scale']),
        'maxrowheight' => intval($rgg_options['maxrowheight']),
        'aspectratio' => '', // '' / 'auto' = each image keeps its own ratio; '16/9' etc. crops to a fixed shape
        'intime' => intval($rgg_options['intime']),
        'outtime' => intval($rgg_options['outtime']),
        'captions' => $rgg_options['captions'],
        'captions_effect' => $rgg_options['captions_effect'],
        'captions_intime' => intval($rgg_options['captions_intime']),
        'captions_outtime' => intval($rgg_options['captions_outtime']),
        'linked_image_size' => $rgg_options['linked_image_size'],
        'lightbox' => $rgg_options['lightbox'],

        //pro params:
        'lastrowbehavior' => $rgg_options['lastrowbehavior'],
        'effect' => $rgg_options['effect'],

        // default params  that can be inherited from gallery_shortcode
        'order'      => 'ASC',
        'orderby'    => $rgg_options['orderby'],
        'link'       => $rgg_options['link'],
        'id'         => $rgg_options['id'] == '' ? $post_id : $rgg_options['id'],
        'size'       => $rgg_options['size'], // default changed from thumbnail to medium, because that makes more sense
        'include'    => $rgg_options['include'],
        'exclude'    => $rgg_options['exclude'],
    ), $attr);

    // swipebox is no longer supported
    if ($settings_arr['lightbox'] === 'swipebox') {
        // Fall back to simplelightbox
        $settings_arr['lightbox'] = 'simplelightbox';
    }

    // Normalize the numeric settings. They can be overridden per shortcode/block, where they
    // arrive as raw strings, so re-cast each to its type and floor it — a stray negative (or a
    // zero, where it would collapse the grid) must never reach the front-end. margin and the
    // animation times may legitimately be 0 (touching tiles / instant), so they only clamp to >= 0.
    foreach (array('margin', 'intime', 'outtime', 'captions_intime', 'captions_outtime') as $key) {
        $settings_arr[$key] = max(0, intval($settings_arr[$key]));
    }

    // maxrowheight and scale would collapse the grid at 0, so they must be strictly positive;
    // fall back to the configured default (then the built-in default) when they aren't.
    $settings_arr['maxrowheight'] = intval($settings_arr['maxrowheight']) > 0
        ? intval($settings_arr['maxrowheight'])
        : intval($rgg_options['maxrowheight']);

    if (doubleval($settings_arr['scale']) <= 0) {
        $fallback = doubleval($rgg_options['scale']);
        $settings_arr['scale'] = $fallback > 0 ? $fallback : RGG_DEFAULT_SCALE;
    }

    // Sanitize the settings (cast to string first; htmlentities() rejects null on PHP 8.1+)
    $settings_arr = array_map( function ( $value ) {
        return htmlentities( (string) $value );
    }, $settings_arr );

    $type = $settings_arr['type'];
    $class = $settings_arr['class'];
    $ids = $settings_arr['ids'];
    $margin = $settings_arr['margin'];
    $scale = $settings_arr['scale'];
    $maxrowheight = $settings_arr['maxrowheight'];
    $aspectratio = $settings_arr['aspectratio'];
    $intime = $settings_arr['intime'];
    $outtime = $settings_arr['outtime'];
    $captions = $settings_arr['captions'];
    $captions_effect = $settings_arr['captions_effect'];
    $captions_intime = $settings_arr['captions_intime'];
    $captions_outtime = $settings_arr['captions_outtime'];
    $linked_image_size = $settings_arr['linked_image_size'];
    $lightbox = $settings_arr['lightbox'];

    //pro
    $lastrowbehavior = $settings_arr['lastrowbehavior'];
    $effect = $settings_arr['effect'];

    // default params  that can be inherited from gallery_shortcode
    $order = $settings_arr['order'];
    $orderby = $settings_arr['orderby'];
    $link = $settings_arr['link'];
    $id = $settings_arr['id'];
    $size = $settings_arr['size'];
    $include = $settings_arr['include'];
    $exclude = $settings_arr['exclude'];

    // Native "Aspect ratio" crop: a fixed ratio forces every tile to the same shape (data-ratio
    // below + .rgg-crop cover); 0 means 'auto'/unset, so each tile keeps its own image ratio.
    $crop_ratio = rgg_aspect_ratio_to_float($aspectratio);

    if ($type == 'native') {
        // returning nothing will make gallery_shortcode take over
        return '';
    }

    /* code below is based on default gallery_shortcode (wp-includes/media.php) */
    /* BEGIN ------------------- */

    if (!empty($ids)) {
        // 'ids' is explicitly ordered, unless you specify otherwise.
        if (empty($orderby)) $orderby = 'post__in';
        $include = $ids;
    }

    // Allow plugins/themes to override the default gallery template. // NOT NEEDED ofcourse
    // $output = apply_filters('post_gallery', '', $attr);
    // if ( $output != '' ) return $output;

    // We're trusting author input, so let's at least make sure it looks like a valid orderby statement
    $orderby = sanitize_sql_orderby($orderby);
    if (!$orderby) unset($orderby);

    $id = intval($id);
    if ('RAND' == $order)
        $orderby = 'none';

    // RML compatibility (https://bit.ly/3d9QSUb)
    if (function_exists('wp_rml_get_object_by_id') && $settings_arr['fid'] !== null && ($rml_folder = wp_rml_get_object_by_id($settings_arr['fid'])) !== null) {
        // We need WP_Query because get_posts suppress filters
        $_attachments = (new WP_Query(array(
            'include' => $include,
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'rml_folder' => $rml_folder->getId(),
            'order' => $order,
            'orderby' => $orderby
        )))->get_posts();
        $attachments = array();
        foreach ($_attachments as $key => $val) {
            $attachments[$val->ID] = $_attachments[$key];
        }
    } elseif (!empty($include)) {
        $_attachments = get_posts(array('include' => $include, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby));

        // Result can only be a subset of the requested ids, unlike the RML branch (folder query).
        $ids_scoped_query = true;
        $attachments = array();
        foreach ($_attachments as $key => $val) {
            $attachments[$val->ID] = $_attachments[$key];
        }
    } elseif (!empty($exclude)) {
        $attachments = get_children(array('post_parent' => $id, 'exclude' => $exclude, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby));
    } else {
        $attachments = get_children(array('post_parent' => $id, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby));
    }

    if (empty($attachments))
        return '';

    if (is_feed()) {
        $output = "\n";
        foreach ($attachments as $att_id => $attachment)
            $output .= wp_get_attachment_link($att_id, $size, true) . "\n";
        return $output;
    }

    /* ------------------------- END */



    $rgg_settings[$instance] = $settings_arr;

    // Render in explicit id order, allowing the same image more than once ($attachments is
    // keyed/deduped by id). Without an id list, use the queried order.
    if (!empty($ids)) {
        $render_ids = array();
        foreach (explode(',', $ids) as $raw_id) {
            $mid = (int) $raw_id;
            if ($mid && isset($attachments[$mid])) {
                $render_ids[] = $mid;
            }
        }
        // Fewer render ids than attachments means the query was remapped: WPML swaps attachment
        // ids for their translations, so none of the requested ids match. Render what came back.
        if (!empty($ids_scoped_query) && count($render_ids) < count($attachments)) {
            $render_ids = array_keys($attachments);
        }
    } else {
        $render_ids = array_keys($attachments);
    }

    // Native (core) lightbox: emit the same Interactivity-API markup and state core's image
    // block uses, so WP's own lightbox overlay opens for our tiles. Per-tile directives are
    // added only to tiles that don't link elsewhere (href === ''), mirroring core's
    // linkDestination==='none' rule (see block_core_image_render_lightbox() in wp-includes).
    $native_lightbox = ($lightbox === 'native') && rgg_native_lightbox_supported();
    $lb_gallery_id = '';
    $lb_order = 0;
    $lb_total = count($render_ids);
    if ($native_lightbox) {
        // Native lightbox and a link are mutually exclusive (as in core): selecting the WP
        // lightbox means a tile click enlarges, never navigates. Drop the gallery-level link so
        // native fires regardless of the Link setting — notably the shortcode's default 'file'
        // link, which would otherwise defeat the (now default) native engine. Per-image custom
        // links still win, resolved per tile below.
        $link = 'none';
        $lb_gallery_id = uniqid();
        rgg_native_lightbox_boot();
    }

    ob_start();

    // Carry over the block wrapper's background color/gradient (Gutenberg only; the
    // shortcode passes nothing), which would otherwise be lost when we replace the markup.
    $container_class = 'rgg-container';
    if (!empty($container_atts['class'])) {
        $container_class .= ' ' . $container_atts['class'];
    }
    $container_style = !empty($container_atts['style']) ? ' style="' . esc_attr($container_atts['style']) . '"' : '';

    // Group the tiles for native-lightbox prev/next: core's overlay walks every image sharing
    // this galleryId (also stamped on each tile's state below), the same wiring core/gallery does.
    $gallery_lb_attr = '';
    if ($native_lightbox) {
        $gctx = wp_json_encode(array('galleryId' => $lb_gallery_id), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $gallery_lb_attr = ' data-wp-interactive="core/gallery" data-wp-context=\'' . $gctx . '\'';
    }

    echo '<div class="' . esc_attr($container_class) . '" data-rgg-id="' . $instance . '"' . $container_style . $gallery_lb_attr . '>';

    if ($lightbox == 'image-above') {
?>
        <div class="image-above-container">
            <?php
            foreach ($render_ids as $mid) {
            ?>
                <div class="slide">
                    <?php
                    // wp_get_attachment_image() carries srcset/sizes, so the browser picks a
                    // candidate by viewport instead of always pulling the (now 'full') original —
                    // mirroring the native engine. loading=false keeps it eager (slick positions
                    // every slide up front, so native lazy-loading would fight the carousel).
                    echo wp_get_attachment_image($mid, $linked_image_size, false, array('loading' => false));
                    ?>
                </div>
            <?php } ?>
        </div>
    <?php
    }
    ?>
    <div class="rgg-imagegrid<?php echo $crop_ratio > 0 ? ' rgg-crop' : '' ?> captions-<?php echo $captions ?> captions-effect-<?php echo $captions_effect ?> <?php echo $class ?>" data-rgg-id="<?php echo $instance ?>">
        <?php
        // Per-id counters so each occurrence of a repeated image picks its own caption/duotone/link slot.
        $caption_idx = array();
        $duotone_idx = array();
        $link_idx = array();
        foreach ($render_ids as $mid) {

            //$native_gallery_link_html = wp_get_attachment_link($mid, $size, true); //some lightbox plugins will filter the native gallery links. So we will copy some of the attributes to our own links.

            // Resolve this tile's link. A per-image Gutenberg custom link (an image linked on
            // its own via the toolbar) overrides the gallery-wide link; media/attachment/none
            // already match the gallery "Link to", so they ride on $link below.
            $tile_link = $link;
            $override_href = '';
            if (isset($link_overrides[$mid])) {
                $li = isset($link_idx[$mid]) ? $link_idx[$mid] : 0;
                $link_idx[$mid] = $li + 1;
                if (isset($link_overrides[$mid][$li])
                    && $link_overrides[$mid][$li]['destination'] === 'custom'
                    && $link_overrides[$mid][$li]['href'] !== '') {
                    $tile_link = 'custom';
                    $override_href = $link_overrides[$mid][$li]['href'];
                }
            }

            $href = '';
            $is_file_link = false;
            if ($tile_link == 'file') {
                $info = wp_get_attachment_image_src($mid, $linked_image_size);
                $href = $info[0];
                $is_file_link = true;
            } else if ($tile_link == 'post') {
                $href = get_the_permalink($mid);
            } else if ($tile_link == 'custom') {
                $href = $override_href;
            }
            // Prefer a caller-supplied caption (e.g. a block figcaption) over the attachment
            // caption, consuming one override per occurrence; a blank occurrence falls back.
            $title = get_post_field('post_excerpt', $mid);
            if (isset($caption_overrides[$mid])) {
                $ci = isset($caption_idx[$mid]) ? $caption_idx[$mid] : 0;
                $caption_idx[$mid] = $ci + 1;
                if (isset($caption_overrides[$mid][$ci]) && $caption_overrides[$mid][$ci] !== '') {
                    $title = $caption_overrides[$mid][$ci];
                }
            }
            $alt = get_post_meta($mid, '_wp_attachment_image_alt', true);
            // A caption may contain markup (block-editor figcaptions allow <strong> etc.).
            // The title attribute is rendered as a plain-text browser tooltip, so it gets the
            // tags stripped; the rendered-HTML caption lives in data-caption, which the
            // lightbox reads (and the overlay caption below echoes directly).
            $title_esc = htmlentities(wp_strip_all_tags($title), ENT_COMPAT, 'UTF-8');
            $caption_esc = htmlentities($title, ENT_COMPAT, 'UTF-8');
            $a_title = "title=\"$title_esc\" data-caption=\"$caption_esc\"";

            $img_info = wp_get_attachment_image_src($mid, $size);

            // A fixed gallery aspect ratio overrides the image's own ratio, so every tile crops to
            // the same shape (cover via .rgg-crop); 0 keeps the image's natural ratio.
            $tile_ratio = $crop_ratio > 0 ? $crop_ratio : $img_info[1] / $img_info[2];

            // The lightbox only has an image to show when the tile links to the file; for a
            // page link (attachment page or a per-image custom URL) skip the lightbox class so
            // it doesn't hijack the click, and let the anchor navigate.
            $rgg_lightbox = $is_file_link ? 'rgg-' . $lightbox : '';

            $href_attr = $href ? 'href="' . esc_url($href) . '"' : '';

            // Native lightbox: register this tile in core's image store (the overlay reads the
            // enlarged image from here, not from the tile) and add the directives core's view
            // module listens for. Only tiles that don't navigate elsewhere take part.
            $lb_class = '';
            $lb_attr = '';
            $native_img_attr = '';
            if ($native_lightbox && $href === '') {
                $uid = uniqid('rgg-lb-', true);
                $lb_meta = wp_get_attachment_metadata($mid);
                $lb_w = isset($lb_meta['width']) ? $lb_meta['width'] : 'none';
                $lb_h = isset($lb_meta['height']) ? $lb_meta['height'] : 'none';
                $lb_srcset = wp_get_attachment_image_srcset($mid, ($lb_w !== 'none' && $lb_h !== 'none') ? array($lb_w, $lb_h) : 'large');
                $lb_aria = $alt
                    ? sprintf(__('Enlarged image %1$s of %2$s: %3$s', 'responsive-gallery-grid'), $lb_order + 1, $lb_total, $alt)
                    : sprintf(__('Enlarged image %1$s of %2$s', 'responsive-gallery-grid'), $lb_order + 1, $lb_total);
                wp_interactivity_state('core/image', array(
                    'metadata' => array(
                        $uid => array(
                            'uploadedSrc'            => wp_get_attachment_url($mid),
                            'lightboxSrcset'         => $lb_srcset ? $lb_srcset : false,
                            // The overlay sizes the enlarged image via `.wp-lightbox-overlay
                            // .wp-block-image img { width/height: var(--wp--lightbox-image-*) }`,
                            // so the lightbox figure must carry wp-block-image or the image
                            // renders at full natural size.
                            'figureClassNames'       => 'wp-block-image',
                            'figureStyles'           => '',
                            'imgClassNames'          => '',
                            'imgStyles'              => '',
                            'targetWidth'            => $lb_w,
                            'targetHeight'           => $lb_h,
                            'scaleAttr'              => false,
                            'alt'                    => $alt,
                            'galleryId'              => $lb_gallery_id,
                            'customAriaLabel'        => $lb_aria,
                            'navigationButtonType'   => 'icon',
                            'triggerButtonAriaLabel' => sprintf(__('Enlarge %1$s of %2$s', 'responsive-gallery-grid'), $lb_order + 1, $lb_total),
                            'order'                  => $lb_order,
                        ),
                    ),
                ));
                $lb_ctx = wp_json_encode(array('imageId' => $uid), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                $lb_class = ' wp-lightbox-container';
                // initTriggerButton registers this tile as the lightbox's buttonRef. Without it,
                // core's hideLightbox throws on close (it focuses selectedImage.buttonRef before
                // clearing selectedImageId), so overlayOpened stays true and handleScroll keeps
                // snapping the page back to its saved position — i.e. the page can't be scrolled
                // after closing. Mirrors core's own trigger button.
                $lb_attr = ' data-wp-interactive="core/image" data-wp-context=\'' . $lb_ctx . '\' data-wp-key="' . esc_attr($uid) . '" data-wp-init="callbacks.initTriggerButton" data-wp-on--click="actions.showLightbox"';
                // Core's lightbox needs a real, loaded <img> per tile as its imageRef: setButtonStyles
                // registers the tile <img> as imageRef, and showLightbox bails unless imageRef.complete
                // is true. Every tile is already a real <img> (see $tile_img below); native tiles just
                // add core's init directives onto it and keep it eager-loaded — an off-screen lazy img
                // isn't .complete, so showLightbox would bail for below-the-fold galleries.
                $native_img_attr = ' data-wp-init="callbacks.setButtonStyles" data-wp-on--load="callbacks.setButtonStyles" data-wp-on-window--resize="callbacks.setButtonStyles"';
                $lb_order++;
            }

            // Per-image duotone (Gutenberg): mark the tile and point --rgg-duotone at the
            // block's SVG filter; style.css applies it to the image only. One slug per occurrence.
            $duotone_class = '';
            $duotone_style = '';
            if (isset($duotones[$mid])) {
                $i = isset($duotone_idx[$mid]) ? $duotone_idx[$mid] : 0;
                $duotone_idx[$mid] = $i + 1;
                if (isset($duotones[$mid][$i]) && $duotones[$mid][$i] !== '') {
                    $duotone_class = ' rgg-duotone';
                    $duotone_style = ' style="--rgg-duotone: url(\'#wp-duotone-' . esc_attr($duotones[$mid][$i]) . '\');"';
                }
            }

            // Every tile is a real <img> filling the anchor box (style.css), replacing the old
            // JS-painted CSS background-image. srcset/sizes let the browser pick a candidate per
            // viewport; alt carries the accessible name, so the anchor no longer needs aria-label.
            // Non-native tiles lazy-load; the native imageRef stays eager (see $native_img_attr).
            $tile_srcset = wp_get_attachment_image_srcset($mid, $size);
            $tile_sizes = wp_get_attachment_image_sizes($mid, $size);
            $tile_loading = ($native_lightbox && $href === '') ? 'eager' : 'lazy';
            $tile_img = '<img class="rgg-tile-img" src="' . esc_url($img_info[0]) . '"'
                . ($tile_srcset ? ' srcset="' . esc_attr($tile_srcset) . '"' : '')
                . ($tile_sizes ? ' sizes="' . esc_attr($tile_sizes) . '"' : '')
                . ' alt="' . esc_attr($alt) . '" loading="' . $tile_loading . '"'
                . $native_img_attr . ' />';

            ob_start();
        ?>
            <a <?php echo $href_attr ?> <?php echo $a_title ?><?php echo $lb_attr ?> class="<?php echo $rgg_lightbox ?> size-<?php echo $size ?> rgg-img<?php echo $duotone_class ?><?php echo $lb_class ?>" data-ratio="<?php echo $tile_ratio ?>"<?php echo $duotone_style ?>>
                <?php
                echo $tile_img;
                if (RGG_IS_PRO && ((substr($captions, 0, 7) == 'overlay' || $captions == 'custom') && !empty($caption_esc))) {
                    echo '<span class="rgg-caption-container"><span class="rgg-caption"><span class="rgg-inner-caption">' . $title . '</span></span></span>';
                }
                ?>
            </a>
        <?php

            $gallery_attachment_link_html = ob_get_clean();

            echo apply_filters('wp_get_attachment_link', $gallery_attachment_link_html, $mid, $size, $href, false, false); // allow other plugins to add additional attributes

        }
        ?>
    </div>
<?php

    echo '</div>'; //close .rgg-container

    return do_shortcode(ob_get_clean());
}

/**
 * Parse a native aspect-ratio value into a numeric width/height ratio. Accepts the core/gallery
 * values ('auto', '1', '4/3', '16/9', ...); returns 0 for 'auto'/empty/invalid, meaning "no fixed
 * ratio" so each tile keeps the image's own ratio.
 *
 * @param string $value Aspect-ratio string.
 * @return float Width/height ratio, or 0 when no fixed ratio applies.
 */
function rgg_aspect_ratio_to_float($value) {
    if ($value === '' || $value === 'auto') {
        return 0;
    }
    if (strpos($value, '/') !== false) {
        list($w, $h) = array_map('floatval', explode('/', $value, 2));
        return $h > 0 ? $w / $h : 0;
    }
    $num = floatval($value);
    return $num > 0 ? $num : 0;
}

/**
 * Whether this WordPress can drive core's native lightbox. Needs the Interactivity API (WP 6.5+)
 * and core's image-block overlay printer, which we reuse verbatim.
 *
 * @return bool
 */
function rgg_native_lightbox_supported() {
    return function_exists('wp_interactivity_state')
        && function_exists('wp_interactivity_config')
        && function_exists('wp_enqueue_script_module')
        && function_exists('block_core_image_print_lightbox_overlay');
}

/**
 * One-time setup for the native lightbox: enqueue core's image view module, seed its config
 * strings, and print core's shared overlay markup in the footer. Reuses core's own overlay
 * printer so the dialog stays in lockstep with whatever WP ships.
 */
function rgg_native_lightbox_boot() {
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    wp_enqueue_script_module('@wordpress/block-library/image/view');

    // The overlay is styled entirely by core's image-block stylesheet (.wp-lightbox-overlay … —
    // the scrim, the --wp--lightbox-image-* sizing, the close/nav button positions). WP enqueues
    // that only when an image/gallery BLOCK is on the page, so a classic [gallery] shortcode (no
    // block) would open the lightbox unstyled. Enqueue it ourselves: the per-block handle in
    // separate-assets mode, else the combined library. This runs at render time (the_content),
    // after wp_head, but core's print_late_styles() prints it in the footer next to the overlay.
    if (wp_style_is('wp-block-image', 'registered')) {
        wp_enqueue_style('wp-block-image');
    } else {
        wp_enqueue_style('wp-block-library');
    }

    wp_interactivity_config('core/image', array(
        'defaultAriaLabel' => __('Enlarged image', 'responsive-gallery-grid'),
        'closeButtonText'  => __('Close', 'responsive-gallery-grid'),
        'prevButtonText'   => _x('Previous', 'previous image in lightbox', 'responsive-gallery-grid'),
        'nextButtonText'   => _x('Next', 'next image in lightbox', 'responsive-gallery-grid'),
    ));
    add_action('wp_footer', 'block_core_image_print_lightbox_overlay');
}

add_filter('post_gallery', 'rgg_gallery_shortcode', 9, 3);
