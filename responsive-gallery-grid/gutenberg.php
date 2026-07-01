<?php

/**
 * Gutenberg integration. The static core/gallery block bypasses the post_gallery
 * filter, so we hook render_block, pull the attachment ids out, and feed them to the
 * same renderer the shortcode uses (rgg_render_gallery() in gallery-shortcode.php).
 */

add_filter('render_block_core/gallery', 'rgg_render_gallery_block', 10, 2);

function rgg_render_gallery_block($block_content, $block) {

    global $rgg_instance_fallback, $rgg_options;

    // No ids => nothing to turn into a grid, so leave the native markup untouched.
    $ids = rgg_get_gallery_block_ids($block);
    if (empty($ids)) {
        return $block_content;
    }

    $attrs = (isset($block['attrs']) && is_array($block['attrs'])) ? $block['attrs'] : array();

    // Native "Randomize order" toggle (core/gallery randomOrder): shuffle the ids before they
    // become the explicit render order. Shared with the native block, so it survives the toggle.
    if (!empty($attrs['randomOrder'])) {
        shuffle($ids);
    }

    $attr = array('ids' => implode(',', $ids));

    // The gallery's "Link to" control is the master switch for the lightbox: RGG only shows a
    // lightbox when it is "Expand on click" (linkTo === 'lightbox'); for None / Media file /
    // Attachment page the images simply (don't) link, with NO lightbox — the Lightbox engine
    // field is ignored entirely.
    // An untouched gallery (no linkTo, or '') inherits the global "On click" default, the same
    // way rgg_type inherits the global 'type'. New galleries also carry linkTo baked in by the
    // block's attribute default (editor.js), so this mainly catches pre-existing galleries.
    $link_to = (isset($attrs['linkTo']) && $attrs['linkTo'] !== '')
        ? $attrs['linkTo']
        : $rgg_options['link_to'];
    if ($link_to === 'lightbox') {
        // "Expand on click": the Lightbox field picks the engine (per-gallery rgg_lightbox wins,
        // else the global setting). Native opens with no link; the file-based engines
        // (simplelightbox / image-above) open the file link.
        $engine = (isset($attrs['rgg_lightbox']) && $attrs['rgg_lightbox'] !== '')
            ? $attrs['rgg_lightbox']
            : $rgg_options['lightbox'];
        $attr['lightbox'] = $engine;
        $attr['link'] = ($engine === 'native') ? 'none' : 'file';
    } else {
        $attr['lightbox'] = 'disabled';
        $link_map = array('media' => 'file', 'attachment' => 'post', 'none' => 'none');
        if (isset($link_map[$link_to])) {
            $attr['link'] = $link_map[$link_to];
        }
    }

    // Native "Resolution" control (core/gallery sizeSlug); falls back to the block's own
    // default so the grid loads the same image size the native gallery would.
    $attr['size'] = !empty($attrs['sizeSlug']) ? $attrs['sizeSlug'] : 'large';

    // Native "Aspect ratio" control (core/gallery aspectRatio): a fixed ratio crops every tile to
    // that shape (the renderer overrides each tile's data-ratio and adds .rgg-crop). 'auto' or an
    // absent value keeps each image at its own ratio.
    if (!empty($attrs['aspectRatio']) && $attrs['aspectRatio'] !== 'auto') {
        $attr['aspectratio'] = $attrs['aspectRatio'];
    }

    // Per-gallery RGG options from the block sidebar (rgg_<slug> attrs); empty means
    // inherit the global setting. '' !== test so an explicit 0 (e.g. margin) is honored.
    foreach (array_keys(rgg_block_option_slugs()) as $slug) {
        // 'lightbox' is resolved above from the "Link to" control (it only applies under
        // "Expand on click"), so it must not be set generically here.
        if ($slug === 'lightbox') {
            continue;
        }
        $key = 'rgg_' . $slug;
        if (isset($attrs[$key]) && $attrs[$key] !== '') {
            $attr[$slug] = $attrs[$key];
        }
    }

    // Per-gallery Native/RGG toggle: 'native' forces the native gallery, 'rgg' forces the
    // grid, empty inherits the global 'type'. Separate from the generic sidebar options.
    if (isset($attrs['rgg_type']) && $attrs['rgg_type'] !== '') {
        $attr['type'] = $attrs['rgg_type'];
    }

    $instance = $rgg_instance_fallback;
    $rgg_instance_fallback++;

    // Image-block figcaptions override the attachment captions.
    $caption_overrides = rgg_get_gallery_block_captions($block);

    // Recompute the wrapper's background color/gradient so the rgg-container keeps it.
    $container_atts = rgg_get_block_color_attributes($block);

    // Carry over the block's "Additional CSS class(es)" (Advanced panel => className), which
    // WordPress would put on the native wrapper we replace with rgg-container.
    if (!empty($attrs['className'])) {
        $container_atts['class'] = trim($container_atts['class'] . ' ' . $attrs['className']);
    }

    // Per-image duotone filters, recovered from the already-rendered block content.
    $duotones = rgg_get_gallery_block_duotones($block_content);

    // Per-image links: an image linked on its own (toolbar link button) carries a custom
    // link the gallery-wide "Link to" doesn't, so resolve each tile's link individually.
    $link_overrides = rgg_get_gallery_block_links($block);

    $rendered = rgg_render_gallery($attr, $instance, $caption_overrides, $container_atts, $duotones, $link_overrides);

    // '' means native mode or no usable attachments; keep the original block output.
    return $rendered !== '' ? $rendered : $block_content;
}

/**
 * Extract attachment ids from a core/gallery block, in display order. Handles the v2
 * format (nested core/image blocks) and legacy v1 (ids on the gallery block).
 *
 * @param array $block Parsed block array from the render_block filter.
 * @return int[] Ordered list of attachment ids.
 */
function rgg_get_gallery_block_ids($block) {

    $ids = array();

    // v2: nested core/image blocks.
    if (!empty($block['innerBlocks'])) {
        foreach ($block['innerBlocks'] as $inner) {
            if (isset($inner['attrs']['id'])) {
                $ids[] = (int) $inner['attrs']['id'];
            }
        }
    }

    // v1: ids stored directly on the gallery block.
    if (empty($ids) && !empty($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
        foreach ($block['attrs']['ids'] as $id) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_filter($ids));
}

/**
 * Extract per-image captions (read from each image block's <figcaption>), keyed by
 * attachment id. Holds one entry per occurrence in inner-block order so repeated images
 * keep their captions apart ('' = no figcaption, falls back to the attachment caption).
 *
 * @param array $block Parsed block array from the render_block filter.
 * @return array Map of attachment id => list of per-occurrence caption HTML ('' = none).
 */
function rgg_get_gallery_block_captions($block) {

    $captions = array();

    if (empty($block['innerBlocks'])) {
        return $captions;
    }

    foreach ($block['innerBlocks'] as $inner) {
        if (!isset($inner['attrs']['id'])) {
            continue;
        }
        $html = isset($inner['innerHTML']) ? $inner['innerHTML'] : '';
        $caption = '';
        if ($html !== '' && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/is', $html, $m)) {
            $caption = trim($m[1]);
        }
        $captions[(int) $inner['attrs']['id']][] = $caption;
    }

    return $captions;
}

/**
 * Extract per-image links from a core/gallery block, keyed by attachment id. Each inner
 * core/image block can be linked on its own (the toolbar link button sets a 'custom' link
 * the gallery-wide "Link to" doesn't), with the destination in attrs and the URL on the
 * rendered <a href>. One entry per occurrence in inner-block order, so a repeated image keeps
 * its links apart. Only 'custom' is consumed downstream; media/attachment/none already match
 * the gallery "Link to".
 *
 * @param array $block Parsed block array from the render_block filter.
 * @return array Map of attachment id => list of array('destination' => string, 'href' => string).
 */
function rgg_get_gallery_block_links($block) {

    $links = array();

    if (empty($block['innerBlocks'])) {
        return $links;
    }

    foreach ($block['innerBlocks'] as $inner) {
        if (!isset($inner['attrs']['id'])) {
            continue;
        }
        $destination = isset($inner['attrs']['linkDestination']) ? $inner['attrs']['linkDestination'] : '';
        $html = isset($inner['innerHTML']) ? $inner['innerHTML'] : '';
        $href = '';
        if ($html !== '' && preg_match('/<a\b[^>]*?\shref="([^"]*)"/i', $html, $m)) {
            $href = $m[1];
        }
        $links[(int) $inner['attrs']['id']][] = array(
            'destination' => $destination,
            'href'        => $href,
        );
    }

    return $links;
}

/**
 * Extract per-image duotone slugs from a rendered core/gallery block, keyed by attachment
 * id. Duotone is applied at render time (a wp-duotone-<slug> class on each <figure>) and
 * its SVG filter stays on the page, so we read the slugs back out of the rendered markup
 * and re-apply them to the grid tiles. One entry per occurrence in document order, so
 * repeated images keep their filters apart ('' = no duotone).
 *
 * @param string $block_content Rendered gallery markup (inner image figures included).
 * @return array Map of attachment id => list of per-occurrence duotone slugs ('' = none).
 */
function rgg_get_gallery_block_duotones($block_content) {

    $duotones = array();

    if (!is_string($block_content) || strpos($block_content, 'wp-block-image') === false) {
        return $duotones;
    }

    // Pair each image <figure> (which carries the wp-duotone-<slug> class) with its
    // following <img class="wp-image-<id>">; the tempered token stops at the first wp-image-.
    if (preg_match_all('/<figure\b[^>]*\bclass="([^"]*\bwp-block-image\b[^"]*)"[^>]*>(?:(?!wp-image-)[\s\S])*?wp-image-(\d+)/i', $block_content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $slug = '';
            // "unset" clears an inherited duotone and has no filter, so leave it blank.
            if (preg_match('/\bwp-duotone-([\w-]+)/', $m[1], $dm) && strpos($dm[1], 'unset') !== 0) {
                $slug = $dm[1];
            }
            $duotones[(int) $m[2]][] = $slug;
        }
    }

    return $duotones;
}

/**
 * Recompute the gallery block's color/background support classes + inline styles, so the
 * rgg-container (which replaces the native wrapper) keeps its background color/gradient.
 *
 * @param array $block Parsed block array from the render_block filter.
 * @return array array('class' => string, 'style' => string)
 */
function rgg_get_block_color_attributes($block) {

    $result = array('class' => '', 'style' => '');

    if (!function_exists('wp_apply_colors_support') || !class_exists('WP_Block_Type_Registry')) {
        return $result;
    }

    $name = isset($block['blockName']) ? $block['blockName'] : 'core/gallery';
    $block_type = WP_Block_Type_Registry::get_instance()->get_registered($name);
    if (!$block_type) {
        return $result;
    }

    $attrs = (isset($block['attrs']) && is_array($block['attrs'])) ? $block['attrs'] : array();
    $colors = wp_apply_colors_support($block_type, $attrs);

    if (!empty($colors['class'])) {
        $result['class'] = $colors['class'];
    }
    if (!empty($colors['style'])) {
        $result['style'] = $colors['style'];
    }

    return $result;
}

/**
 * Per-gallery RGG options in the block sidebar, mapping each option slug to a sidebar
 * section that mirrors the global settings screen (see rgg_get_admin_form_structure):
 * Gallery / Display / Animation / Captions. Pro-only slugs produce no control on the free
 * version. size/link are omitted because the native block already exposes them (mapped above).
 *
 * @return array slug => section key ('gallery'|'display'|'animation'|'captions').
 */
function rgg_block_option_slugs() {
    return array(
        // Gallery Settings (shown alongside the native-backed image size / link / order controls).
        // 'linked_image_size' is deliberately absent: native core's lightbox has no equivalent
        // (it always opens the full original), so it stays a classic-shortcode-only param.
        'lightbox'          => 'gallery',
        // Display Settings.
        'margin'            => 'display',
        'maxrowheight'      => 'display',
        'lastrowbehavior'   => 'display',    // Pro
        // Animation Settings.
        'scale'             => 'animation',
        'intime'            => 'animation',
        'outtime'           => 'animation',
        'effect'            => 'animation',
        // Captions.
        'captions'          => 'captions',
        'captions_effect'   => 'captions',   // Pro
        'captions_intime'   => 'captions',   // Pro
        'captions_outtime'  => 'captions',   // Pro
    );
}

/**
 * Block-editor option slugs that render as a number input, mapped to their step ('1' for
 * integers, 'any' for the decimal scale). All non-negative, so editor.js adds min="0".
 *
 * @return array slug => step.
 */
function rgg_block_numeric_fields() {
    return array(
        'margin'           => '1',
        'maxrowheight'     => '1',
        'scale'            => 'any',
        'intime'           => '1',
        'outtime'          => '1',
        'captions_intime'  => '1',
        'captions_outtime' => '1',
    );
}

/**
 * Enqueue js/editor.js (which adds the RGG InspectorControls panel to core/gallery) and
 * hand it the field definitions, reused from the settings screen, plus each global value.
 */
add_action('enqueue_block_editor_assets', 'rgg_enqueue_block_editor_assets');

function rgg_enqueue_block_editor_assets() {

    global $rgg_options, $rgg_input_fields;

    wp_enqueue_script(
        'rgg-editor',
        RGG_PLUGIN_DIR_URL . '/js/editor.js',
        array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-compose', 'wp-hooks'),
        RGG_VERSION,
        true
    );

    rgg_setup_input_fields(); // populates $rgg_input_fields (+ Pro fields via filter)

    $editor_fields = array();
    $numeric_fields = rgg_block_numeric_fields();

    foreach (rgg_block_option_slugs() as $slug => $group) {

        // Field not defined for this tier (e.g. a Pro option on the free plugin).
        if (!isset($rgg_input_fields[$slug])) {
            continue;
        }

        $field  = $rgg_input_fields[$slug];
        $global = isset($rgg_options[$slug]) ? $rgg_options[$slug] : '';

        $entry = array(
            'type'  => $field['input_type'],
            'label' => $field['label'],
            'group' => $group,
            // The raw global value, so the editor can resolve the effective setting when a
            // gallery inherits it (selects only expose the global as a label otherwise).
            'global' => (string) $global,
        );

        if ($field['input_type'] === 'select') {
            $options = isset($field['options']) ? $field['options'] : array();

            // First choice = inherit the global setting, labelled with its value.
            $global_label = isset($options[$global]) ? $options[$global] : $global;
            $choices = array(array('value' => '', 'label' => sprintf('Default (%s)', $global_label)));
            foreach ($options as $value => $text) {
                $choices[] = array('value' => (string) $value, 'label' => $text);
            }
            $entry['options'] = $choices;
        } else {
            // Inherited global value shown as a placeholder hint.
            $entry['placeholder'] = (string) $global;

            // Numeric options render as a number input (with step/min) instead of free text.
            if (isset($numeric_fields[$slug])) {
                $entry['type'] = 'number';
                $entry['step'] = $numeric_fields[$slug];
                $entry['min']  = '0';
            }
        }

        $editor_fields[$slug] = $entry;
    }

    wp_localize_script('rgg-editor', 'rggEditor', array(
        'fields'       => $editor_fields,
        'nativeFields' => rgg_block_native_fields(),
        // The global 'type' setting, so the editor can resolve a gallery that inherits it
        // (rgg_type === '') the same way the front-end renderer does.
        'globalType'   => isset($rgg_options['type']) ? (string) $rgg_options['type'] : RGG_DEFAULT_TYPE,
        // Seeds core/gallery's linkTo attribute default (editor.js) so a new gallery starts on
        // the global "On click" behavior — Enlarge on click by default, i.e. the WP lightbox.
        'defaultLinkTo' => isset($rgg_options['link_to']) ? (string) $rgg_options['link_to'] : RGG_DEFAULT_LINK_TO,
        'isPro'        => RGG_IS_PRO,
    ));
}

/**
 * Controls in the RGG panel that bind directly to a native core/gallery attribute (rather
 * than an rgg_<slug> attribute), so the value is shared with the native block and persists
 * when the gallery is toggled Native <-> RGG. The render side reads these same attributes
 * (see rgg_render_gallery_block): sizeSlug -> size, linkTo -> link, randomOrder -> shuffle.
 *
 * @return array native attribute name => control definition.
 */
function rgg_block_native_fields() {

    $native_fields = array();

    // Resolution: the native "Resolution" control (core/gallery sizeSlug, default 'large').
    // Mirror core's dropdown, the image_size_names_choose list, so the choices match the native
    // control exactly (incl. any custom size a theme exposes through that filter).
    $size_choices = apply_filters('image_size_names_choose', array(
        'thumbnail' => __('Thumbnail'),
        'medium'    => __('Medium'),
        'large'     => __('Large'),
        'full'      => __('Full Size'),
    ));
    $choices = array();
    foreach ($size_choices as $value => $text) {
        $choices[] = array('value' => (string) $value, 'label' => $text);
    }
    $native_fields['sizeSlug'] = array(
        'type'    => 'select',
        'label'   => 'Resolution',
        'options' => $choices,
        'default' => 'large',
    );

    // Aspect ratio: the native "Aspect ratio" control (core/gallery aspectRatio, default
    // 'auto'). Values mirror core's dimensions.aspectRatios.default (theme.json); 'auto' = the
    // image's own ratio. Bound to the native attribute, so the crop is shared with the block.
    $native_fields['aspectRatio'] = array(
        'type'    => 'select',
        'label'   => 'Aspect ratio',
        'options' => array(
            array('value' => 'auto', 'label' => 'Original'),
            array('value' => '1',    'label' => 'Square - 1:1'),
            array('value' => '4/3',  'label' => 'Standard - 4:3'),
            array('value' => '3/4',  'label' => 'Portrait - 3:4'),
            array('value' => '3/2',  'label' => 'Classic - 3:2'),
            array('value' => '2/3',  'label' => 'Classic Portrait - 2:3'),
            array('value' => '16/9', 'label' => 'Wide - 16:9'),
            array('value' => '9/16', 'label' => 'Tall - 9:16'),
        ),
        'default' => 'auto',
    );

    // No "Link to" control here: core's own gallery toolbar already offers the full set
    // (None / Media File / Attachment Page / Enlarge on click -> linkTo 'lightbox'), which the
    // renderer honors. A second, Enlarge-less select in the sidebar was redundant and confusing,
    // so the toolbar is the single source of truth; the global "On click" setting seeds its
    // default for new galleries (see RGG_DEFAULT_LINK_TO / editor.js).

    // Gallery order: the native "Randomize order" toggle (core/gallery randomOrder).
    $native_fields['randomOrder'] = array(
        'type'  => 'toggle',
        'label' => 'Randomize order',
    );

    return $native_fields;
}

/**
 * Load the grid-preview CSS into the editor canvas iframe. enqueue_block_assets is the
 * hook that reaches the iframe; it also runs on the front end, hence the is_admin() guard.
 */
add_action('enqueue_block_assets', 'rgg_enqueue_block_grid_preview');

function rgg_enqueue_block_grid_preview() {
    if (!is_admin()) {
        return;
    }
    wp_enqueue_style(
        'rgg-editor-grid',
        RGG_PLUGIN_DIR_URL . '/css/editor-grid.css',
        array(),
        RGG_VERSION
    );
}
