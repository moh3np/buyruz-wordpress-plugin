<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * AI Schema Manager module.
 *
 * Provides a UI-driven mechanism for injecting custom Schema.org PropertyValue
 * entries and itemCondition into Rank Math Pro's Product schema output on
 * single product pages.
 */
class BRZ_AI_Schema {

    /**
     * Bootstrap the module.
     *
     * Registers hooks based on context (admin vs frontend).
     * Admin: registers AJAX handler.
     * Frontend: conditionally registers Rank Math filter on product pages.
     */
    public static function init(): void {
        if ( is_admin() ) {
            add_action( 'wp_ajax_brz_save_ai_schema', array( __CLASS__, 'ajax_save' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
            return;
        }

        // Frontend: register filter directly to avoid race conditions with wp hook.
        // The is_product() check is performed dynamically inside the callback.
        add_filter(
            'rank_math/snippet/rich_snippet_product_entity',
            array( 'BRZ_AI_Schema', 'inject_schema' ),
            20
        );
    }

    /**
     * Enqueue admin assets for the AI Schema module page.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public static function enqueue_admin_assets( $hook_suffix ): void {
        // Only load on our module page.
        if ( ! isset( $_GET['page'] ) || 'buyruz-module-ai_schema' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        wp_enqueue_script( 'jquery-ui-sortable' );
    }

    /**
     * Render the admin settings page.
     *
     * Outputs HTML + inline JS inside the existing BRZ shell.
     * The page renders inside the Buyruz shell (provided by BRZ_Settings::render_module_settings()).
     */
    public static function render_admin_page(): void {
        $properties     = self::get_properties();
        $item_condition = self::get_item_condition();

        // Fetch WooCommerce Global Attributes
        $wc_attributes = array();
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $taxonomies = wc_get_attribute_taxonomies();
            if ( ! empty( $taxonomies ) ) {
                foreach ( $taxonomies as $tax ) {
                    $taxonomy_name = wc_attribute_taxonomy_name( $tax->attribute_name );
                    $wc_attributes[ $taxonomy_name ] = $tax->attribute_label;
                }
            }
        }

        // Fetch Buyruz Product Specs
        $brz_specs = array();
        if ( class_exists( 'BRZ_Product_Specs' ) ) {
            $fields = BRZ_Product_Specs::get_fields();
            if ( ! empty( $fields ) ) {
                foreach ( $fields as $field ) {
                    $brz_specs[ 'spec_' . $field['key'] ] = $field['label'];
                }
            }
        }

        $enabled_attrs = self::get_enabled_attributes();
        ?>
        <style>
            .brz-ai-schema-row {
                display: flex;
                align-items: center;
                gap: var(--md-space-sm);
                padding: var(--md-space-sm) var(--md-space-md);
                margin-bottom: var(--md-space-xs);
                background: var(--md-surface, #fff);
                border: 1px solid var(--md-outline-variant, #e0e0e0);
                border-radius: 8px;
                transition: box-shadow 0.2s;
            }
            .brz-ai-schema-row:hover {
                box-shadow: var(--md-elevation-1, 0 1px 3px rgba(0,0,0,.12));
            }
            .brz-ai-schema-handle {
                cursor: grab;
                color: var(--md-on-surface-variant, #666);
                font-size: 18px;
                padding: var(--md-space-xs);
                user-select: none;
                flex-shrink: 0;
            }
            .brz-ai-schema-handle:active {
                cursor: grabbing;
            }
            .brz-ai-schema-row input[type="text"] {
                flex: 1;
                padding: var(--md-space-xs) var(--md-space-sm);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 14px;
                min-width: 0;
            }
            .brz-ai-schema-row input[type="text"]:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-ai-schema-row input.brz-field-error {
                border-color: #d32f2f;
                box-shadow: 0 0 0 2px rgba(211,47,47,.12);
            }
            .brz-ai-schema-delete {
                background: none;
                border: none;
                color: var(--md-error, #d32f2f);
                cursor: pointer;
                font-size: 18px;
                padding: var(--md-space-xs);
                border-radius: 4px;
                flex-shrink: 0;
                transition: background 0.15s;
            }
            .brz-ai-schema-delete:hover {
                background: rgba(211,47,47,.08);
            }
            .brz-ai-schema-placeholder {
                border: 2px dashed var(--brz-brand, #1a73e8);
                border-radius: 8px;
                background: rgba(26,115,232,.04);
                margin-bottom: var(--md-space-xs);
                height: 48px;
            }
            .brz-ai-schema-empty {
                text-align: center;
                color: var(--md-on-surface-variant, #666);
                padding: var(--md-space-xl) var(--md-space-md);
                font-size: 14px;
            }
            .brz-ai-schema-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 8px;
                margin-top: var(--md-space-sm);
                margin-bottom: var(--md-space-md);
            }
            .brz-ai-schema-checkbox-label {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                padding: 6px var(--md-space-xs);
                border-radius: 6px;
                transition: background 0.15s;
            }
            .brz-ai-schema-checkbox-label:hover {
                background: rgba(0, 0, 0, 0.04);
            }
            .brz-ai-schema-checkbox-label input[type="checkbox"] {
                cursor: pointer;
            }
        </style>

        <div class="brz-single-column" dir="rtl">
            <form id="brz-ai-schema-form">
                <?php wp_nonce_field( 'brz_ai_schema_save', '_wpnonce' ); ?>

                <!-- PropertyValue Card -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>ویژگی‌های دستی PropertyValue</h3>
                    </div>
                    <div class="brz-card__body">
                        <div id="brz-ai-schema-list">
                            <?php if ( ! empty( $properties ) ) : ?>
                                <?php foreach ( $properties as $prop ) : ?>
                                    <div class="brz-ai-schema-row">
                                        <span class="brz-ai-schema-handle" aria-hidden="true">☰</span>
                                        <input type="text" data-field="name" value="<?php echo esc_attr( $prop['name'] ); ?>" placeholder="نام ویژگی" maxlength="200" />
                                        <input type="text" data-field="value" value="<?php echo esc_attr( $prop['value'] ); ?>" placeholder="مقدار ویژگی" maxlength="200" />
                                        <button type="button" class="brz-ai-schema-delete" title="حذف">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="brz-ai-schema-empty">هنوز ویژگی‌ای اضافه نشده است. برای شروع روی «افزودن ویژگی» کلیک کنید.</div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:var(--md-space-md);">
                            <button type="button" id="brz-ai-schema-add" class="brz-button brz-button--secondary">افزودن ویژگی</button>
                        </div>
                    </div>
                </div>

                <!-- Auto Attributes Card -->
                <div class="brz-card" style="margin-top:var(--md-space-lg);">
                    <div class="brz-card__header">
                        <h3>ویژگی‌های خودکار محصول (Schema.org PropertyValue)</h3>
                    </div>
                    <div class="brz-card__body">
                        <p class="description" style="margin-bottom:var(--md-space-md);color:var(--md-on-surface-variant,#666);">
                            ویژگی‌های تیک‌خورده به‌صورت خودکار از اطلاعات محصول استخراج شده و به بخش <code>additionalProperty</code> اسکیمای گوگل ارسال می‌شوند. توصیه می‌شود تنها موارد با ارزش بالا جهت سئو تیک بخورند تا چگالی کدهای ساختاریافته بهینه بماند.
                        </p>

                        <?php if ( ! empty( $wc_attributes ) ) : ?>
                            <h4 style="margin-top:0;margin-bottom:var(--md-space-sm);border-bottom:1px solid var(--md-outline-variant,#e0e0e0);padding-bottom:var(--md-space-xs);color:var(--brz-brand,#1a73e8);">ویژگی‌های سراسری ووکامرس</h4>
                            <div class="brz-ai-schema-grid">
                                <?php foreach ( $wc_attributes as $tax_name => $label ) : 
                                    $checked = in_array( $tax_name, $enabled_attrs, true );
                                    ?>
                                    <label class="brz-ai-schema-checkbox-label">
                                        <input type="checkbox" class="brz-ai-schema-attr-checkbox" value="<?php echo esc_attr( $tax_name ); ?>" <?php checked( $checked ); ?> />
                                        <span><?php echo esc_html( $label . ' (' . $tax_name . ')' ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $brz_specs ) ) : ?>
                            <h4 style="margin-top:var(--md-space-lg);margin-bottom:var(--md-space-sm);border-bottom:1px solid var(--md-outline-variant,#e0e0e0);padding-bottom:var(--md-space-xs);color:var(--brz-brand,#1a73e8);">مشخصات فنی اختصاصی بایروز</h4>
                            <div class="brz-ai-schema-grid">
                                <?php foreach ( $brz_specs as $spec_key => $label ) : 
                                    $checked = in_array( $spec_key, $enabled_attrs, true );
                                    ?>
                                    <label class="brz-ai-schema-checkbox-label">
                                        <input type="checkbox" class="brz-ai-schema-attr-checkbox" value="<?php echo esc_attr( $spec_key ); ?>" <?php checked( $checked ); ?> />
                                        <span><?php echo esc_html( $label ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- itemCondition Card -->
                <div class="brz-card" style="margin-top:var(--md-space-lg);">
                    <div class="brz-card__header">
                        <h3>وضعیت محصول (itemCondition)</h3>
                    </div>
                    <div class="brz-card__body">
                        <label style="display:flex;align-items:center;gap:var(--md-space-sm);cursor:pointer;">
                            <input type="checkbox" id="brz-ai-schema-condition" value="1" <?php checked( $item_condition ); ?> />
                            <span>فعال‌سازی itemCondition: NewCondition</span>
                        </label>
                        <p class="description" style="margin-top:var(--md-space-sm);color:var(--md-on-surface-variant,#666);">
                            با فعال‌سازی، مقدار <code>https://schema.org/NewCondition</code> به بخش offers اسکیمای محصول اضافه می‌شود.
                        </p>
                    </div>
                </div>

                <!-- Save Bar -->
                <div class="brz-save-bar" style="margin-top:var(--md-space-lg);">
                    <button type="button" id="brz-ai-schema-save" class="brz-button brz-button--primary">ذخیره تغییرات</button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($){
            'use strict';

            var MAX_ENTRIES = 50;
            var $list = $('#brz-ai-schema-list');
            var $addBtn = $('#brz-ai-schema-add');

            // Initialize jQuery UI Sortable.
            $list.sortable({
                handle: '.brz-ai-schema-handle',
                axis: 'y',
                placeholder: 'brz-ai-schema-placeholder'
            });

            // Update Add button disabled state based on entry count.
            function updateAddButton() {
                $addBtn.prop('disabled', $list.children('.brz-ai-schema-row').length >= MAX_ENTRIES);
            }

            // Build HTML for a new row.
            function buildRowHtml(name, value) {
                return '<div class="brz-ai-schema-row">' +
                    '<span class="brz-ai-schema-handle" aria-hidden="true">☰</span>' +
                    '<input type="text" data-field="name" value="' + escAttr(name) + '" placeholder="نام ویژگی" maxlength="200" />' +
                    '<input type="text" data-field="value" value="' + escAttr(value) + '" placeholder="مقدار ویژگی" maxlength="200" />' +
                    '<button type="button" class="brz-ai-schema-delete" title="حذف">✕</button>' +
                '</div>';
            }

            // Simple HTML attribute escaping.
            function escAttr(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML.replace(/"/g, '&quot;');
            }

            // Show snackbar notification.
            function showSnackbar(message, type) {
                var snackbar = document.getElementById('brz-snackbar');
                if (snackbar) {
                    snackbar.textContent = message;
                    snackbar.classList.add('is-visible', 'is-' + type);
                    setTimeout(function(){
                        snackbar.classList.remove('is-visible', 'is-success', 'is-error');
                    }, 3000);
                }
            }

            // Add Entry.
            $addBtn.on('click', function() {
                if ($list.children('.brz-ai-schema-row').length >= MAX_ENTRIES) {
                    $(this).prop('disabled', true);
                    return;
                }
                // Remove empty state message if present.
                $list.find('.brz-ai-schema-empty').remove();
                $list.append(buildRowHtml('', ''));
                $list.sortable('refresh');
                updateAddButton();
            });

            // Delete Entry (delegated).
            $list.on('click', '.brz-ai-schema-delete', function() {
                $(this).closest('.brz-ai-schema-row').remove();
                updateAddButton();
                // Show empty state if list is empty.
                if ($list.children('.brz-ai-schema-row').length === 0) {
                    $list.append('<div class="brz-ai-schema-empty">هنوز ویژگی‌ای اضافه نشده است. برای شروع روی «افزودن ویژگی» کلیک کنید.</div>');
                }
            });

            // Clear validation error on focus.
            $list.on('focus', 'input.brz-field-error', function() {
                $(this).removeClass('brz-field-error');
            });

            // Save via AJAX.
            $('#brz-ai-schema-save').on('click', function() {
                var $btn = $(this);
                var hasError = false;

                // Validate: highlight empty fields.
                $list.find('.brz-ai-schema-row input[type="text"]').each(function() {
                    if ($.trim($(this).val()) === '') {
                        $(this).addClass('brz-field-error');
                        hasError = true;
                    }
                });

                if (hasError) {
                    return;
                }

                // setBusy pattern: disable and show loading.
                $btn.prop('disabled', true).text('در حال ذخیره…');

                var properties = [];
                $list.find('.brz-ai-schema-row').each(function() {
                    properties.push({
                        name: $(this).find('[data-field="name"]').val(),
                        value: $(this).find('[data-field="value"]').val()
                    });
                });

                // Collect auto attributes checkboxes
                var enabled_attributes = [];
                $('.brz-ai-schema-attr-checkbox:checked').each(function() {
                    enabled_attributes.push($(this).val());
                });

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'brz_save_ai_schema',
                        _wpnonce: $('#_wpnonce').val(),
                        properties: properties,
                        item_condition: $('#brz-ai-schema-condition').is(':checked') ? 1 : 0,
                        enabled_attributes: enabled_attributes
                    },
                    success: function(res) {
                        if (res.success) {
                            showSnackbar(res.data.message || 'ذخیره شد.', 'success');
                        } else {
                            showSnackbar(res.data.message || 'خطا در ذخیره‌سازی.', 'error');
                        }
                    },
                    error: function() {
                        showSnackbar('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('ذخیره تغییرات');
                    }
                });
            });

            // Initial state check.
            updateAddButton();
        });
        </script>
        <?php
    }

    /**
     * Handle wp_ajax_brz_save_ai_schema action.
     *
     * Checks capability, verifies nonce, sanitizes input, persists, responds JSON.
     */
    public static function ajax_save(): void {
        // Capability check MUST come before nonce verification (Requirement 8.6).
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        // Verify nonce.
        if ( ! check_ajax_referer( 'brz_ai_schema_save', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست امنیتی نامعتبر است.' ), 403 );
        }

        // Read and sanitize properties.
        $raw_properties = isset( $_POST['properties'] ) && is_array( $_POST['properties'] ) ? $_POST['properties'] : array();
        $properties     = self::sanitize_properties( $raw_properties );

        // Read item_condition toggle (cast to 0 or 1).
        $item_condition = isset( $_POST['item_condition'] ) ? absint( $_POST['item_condition'] ) : 0;
        $item_condition = $item_condition ? 1 : 0;

        // Read and sanitize enabled auto-attributes.
        $raw_attrs     = isset( $_POST['enabled_attributes'] ) && is_array( $_POST['enabled_attributes'] ) ? $_POST['enabled_attributes'] : array();
        $enabled_attrs = array();
        foreach ( $raw_attrs as $attr ) {
            $enabled_attrs[] = sanitize_key( $attr );
        }

        // Get current options and update AI Schema keys.
        $opts = get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        $opts['ai_schema_properties']         = $properties;
        $opts['ai_schema_item_condition']     = $item_condition;
        $opts['ai_schema_enabled_attributes'] = $enabled_attrs;

        // Persist with autoload disabled (consistent with existing plugin pattern).
        update_option( BRZ_OPTION, $opts, false );

        wp_send_json_success( array( 'message' => 'تغییرات ذخیره شد.' ) );
    }

    /**
     * Filter callback for rank_math/snippet/rich_snippet_product_entity.
     *
     * Appends PropertyValues and optionally sets itemCondition on the entity.
     *
     * @param array $entity Product schema entity from Rank Math.
     * @return array Modified entity.
     */
    public static function inject_schema( $entity ) {
        $properties     = self::get_properties();
        $item_condition = self::get_item_condition();
        $enabled_attrs  = self::get_enabled_attributes();
        $auto_properties = array();

        // Log the call
        $debug_log = array(
            'timestamp'      => date( 'Y-m-d H:i:s' ),
            'is_product'     => function_exists( 'is_product' ) ? ( is_product() ? 1 : 0 ) : -1,
            'queried_id'     => get_queried_object_id(),
            'the_id'         => get_the_ID(),
            'enabled_attrs'  => $enabled_attrs,
            'properties'     => $properties,
            'item_condition' => $item_condition,
        );

        // Dynamically fetch values of WooCommerce attributes and Buyruz specs for the product
        if ( ! empty( $enabled_attrs ) && function_exists( 'is_product' ) && is_product() ) {
            $product_id = get_queried_object_id();
            if ( ! $product_id ) {
                $product_id = get_the_ID();
            }
            $product    = wc_get_product( $product_id );
            if ( $product ) {
                foreach ( $enabled_attrs as $attr_key ) {
                    if ( strpos( $attr_key, 'pa_' ) === 0 ) {
                        // WooCommerce taxonomy attribute
                        $val = $product->get_attribute( $attr_key );
                        if ( $val !== '' ) {
                            $label = wc_attribute_label( $attr_key );
                            $auto_properties[] = array(
                                'name'  => $label,
                                'value' => $val,
                            );
                        }
                    } elseif ( strpos( $attr_key, 'spec_' ) === 0 ) {
                        // Buyruz custom spec
                        $spec_key = substr( $attr_key, 5 ); // remove 'spec_'
                        $val = self::get_spec_display_value( $product, $spec_key );
                        if ( $val !== '' ) {
                            $label = self::get_spec_label( $spec_key );
                            $auto_properties[] = array(
                                'name'  => $label,
                                'value' => $val,
                            );
                        }
                    }
                }
            }
        }

        $debug_log['auto_properties'] = $auto_properties;
        $debug_log['entity_keys_before'] = array_keys( $entity );

        // If no properties to inject and item_condition is disabled, return unmodified.
        if ( empty( $properties ) && empty( $auto_properties ) && ! $item_condition ) {
            $debug_log['status'] = 'empty_return';
            if ( function_exists( 'wp_upload_dir' ) ) {
                $upload_dir = wp_upload_dir();
                file_put_contents( $upload_dir['basedir'] . '/ai-schema-debug.json', json_encode( $debug_log ) );
            }
            return $entity;
        }

        // Append PropertyValue entries to additionalProperty.
        $all_to_inject = array();
        if ( ! empty( $properties ) ) {
            foreach ( $properties as $p ) {
                $all_to_inject[] = array(
                    '@type' => 'PropertyValue',
                    'name'  => $p['name'],
                    'value' => $p['value'],
                );
            }
        }
        if ( ! empty( $auto_properties ) ) {
            foreach ( $auto_properties as $ap ) {
                $all_to_inject[] = array(
                    '@type' => 'PropertyValue',
                    'name'  => $ap['name'],
                    'value' => $ap['value'],
                );
            }
        }

        if ( ! empty( $all_to_inject ) ) {
            // If additionalProperty already exists and is an array, merge/append.
            if ( isset( $entity['additionalProperty'] ) && is_array( $entity['additionalProperty'] ) ) {
                foreach ( $all_to_inject as $prop ) {
                    $entity['additionalProperty'][] = $prop;
                }
            } else {
                // Initialize as new array with entries.
                $entity['additionalProperty'] = $all_to_inject;
            }
        }

        // If item_condition enabled and offers exists, set itemCondition.
        if ( $item_condition && isset( $entity['offers'] ) ) {
            $entity['offers']['itemCondition'] = 'https://schema.org/NewCondition';
        }

        $debug_log['status'] = 'success';
        $debug_log['entity_additionalProperty'] = isset($entity['additionalProperty']) ? $entity['additionalProperty'] : 'not_set';
        if ( function_exists( 'wp_upload_dir' ) ) {
            $upload_dir = wp_upload_dir();
            file_put_contents( $upload_dir['basedir'] . '/ai-schema-debug.json', json_encode( $debug_log ) );
        }

        return $entity;
    }

    /**
     * Read ai_schema_properties from brz_options.
     *
     * Uses BRZ_Settings::get() for cached option access.
     *
     * @return array Indexed array of property entries, or empty array if missing/invalid.
     */
    private static function get_properties(): array {
        $properties = BRZ_Settings::get( 'ai_schema_properties', array() );

        if ( ! is_array( $properties ) ) {
            return array();
        }

        return $properties;
    }

    /**
     * Read ai_schema_item_condition from brz_options.
     *
     * Uses BRZ_Settings::get() for cached option access.
     *
     * @return bool True if item condition is enabled, false if missing or non-numeric.
     */
    private static function get_item_condition(): bool {
        $value = BRZ_Settings::get( 'ai_schema_item_condition', 0 );

        if ( ! is_numeric( $value ) ) {
            return false;
        }

        return (bool) (int) $value;
    }

    /**
     * Sanitize and filter an array of PropertyValue entries.
     *
     * Applies sanitize_text_field() to name and value, enforces max 200 characters,
     * and excludes entries where either field is empty after sanitization.
     *
     * @param array $raw Raw array of property entries.
     * @return array Clean indexed array of valid entries.
     */
    private static function sanitize_properties( array $raw ): array {
        $clean = array();
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $name  = isset( $entry['name'] )  ? sanitize_text_field( $entry['name'] )  : '';
            $value = isset( $entry['value'] ) ? sanitize_text_field( $entry['value'] ) : '';

            // Enforce max 200 characters.
            $name  = mb_substr( $name, 0, 200 );
            $value = mb_substr( $value, 0, 200 );

            // Exclude entries with empty name or value.
            if ( '' === $name || '' === $value ) {
                continue;
            }

            $clean[] = array(
                'name'  => $name,
                'value' => $value,
            );
        }
        return array_values( $clean );
    }

    /**
     * Get default enabled high-SEO-value WooCommerce attributes and Buyruz specs.
     *
     * @return array Whitelist of keys.
     */
    private static function get_default_enabled_attributes(): array {
        return array(
            'pa_game-style',
            'pa_game-mechanics',
            'pa_theme',
            'pa_game-language',
            'pa_designer',
            'pa_target-audience',
            'spec_manual_age',
            'spec_players',
            'spec_time',
            'spec_best_players',
            'spec_difficulty',
            'spec_is_expandable',
            'spec_is_campaign',
            'spec_needs_coop',
            'spec_is_adult',
        );
    }

    /**
     * Get enabled attribute/spec keys from option, falling back to defaults.
     *
     * @return array List of enabled keys.
     */
    public static function get_enabled_attributes(): array {
        $val = BRZ_Settings::get( 'ai_schema_enabled_attributes', null );
        if ( is_null( $val ) ) {
            return self::get_default_enabled_attributes();
        }
        return is_array( $val ) ? $val : array();
    }

    /**
     * Simple digit translation helper to convert English digits to Persian.
     *
     * @param mixed $str String to convert.
     * @return string Converted string.
     */
    private static function to_persian_digits( $str ): string {
        $persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
        $english = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
        return str_replace( $english, $persian, (string) $str );
    }

    /**
     * Dynamically retrieve a specification's label from BRZ_Product_Specs config.
     *
     * @param string $spec_key The specification key (e.g. 'manual_age').
     * @return string The resolved label.
     */
    private static function get_spec_label( string $spec_key ): string {
        if ( class_exists( 'BRZ_Product_Specs' ) ) {
            $fields = BRZ_Product_Specs::get_fields();
            foreach ( $fields as $field ) {
                if ( $field['key'] === $spec_key ) {
                    // Trim prefixes commonly used in WooCommerce specifications view.
                    $label = trim( str_replace( array( 'حداقل', 'حداکثر' ), '', $field['label'] ) );
                    return ! empty( $label ) ? $label : $field['label'];
                }
            }
        }
        return $spec_key;
    }

    /**
     * Get the clean text display value of a Buyruz product spec for a product.
     *
     * @param WC_Product $product WooCommerce product.
     * @param string $spec_key Specification key.
     * @return string Plain text representation.
     */
    private static function get_spec_display_value( $product, string $spec_key ): string {
        if ( ! class_exists( 'BRZ_Product_Specs' ) ) {
            return '';
        }

        $fields = BRZ_Product_Specs::get_fields();
        $target_field = null;
        foreach ( $fields as $field ) {
            if ( $field['key'] === $spec_key ) {
                $target_field = $field;
                break;
            }
        }

        if ( ! $target_field ) {
            return '';
        }

        $type       = $target_field['type'];
        $prefix     = isset( $target_field['prefix'] ) ? $target_field['prefix'] : '';
        $suffix     = isset( $target_field['suffix'] ) ? $target_field['suffix'] : '';
        $product_id = $product->get_id();

        if ( 'boolean' === $type ) {
            $val = get_post_meta( $product_id, '_brz_spec_' . $spec_key, true );
            if ( $val === '' ) {
                return '';
            }
            return ( $val === '1' ) ? 'بله' : 'خیر';
        } elseif ( 'range' === $type ) {
            $keys = BRZ_Product_Specs::get_range_meta_keys( $spec_key );
            $min  = get_post_meta( $product_id, $keys[0], true );
            $max  = get_post_meta( $product_id, $keys[1], true );

            if ( $min === '' && $max === '' ) {
                return '';
            }

            $raw_options = isset( $target_field['options'] ) ? str_replace( '؛', ';', (string) $target_field['options'] ) : '';
            $formats     = array_map( 'trim', explode( ';', $raw_options ) );

            $def_both = '{min} تا {max}' . ( $suffix ? ' ' . $suffix : '' );
            $def_min  = ( $prefix ? $prefix . ' ' : '' ) . '{min}' . ( $suffix ? ' ' . $suffix : '' );
            $def_max  = 'تا {max}' . ( $suffix ? ' ' . $suffix : '' );

            $fmt_both = isset( $formats[0] ) && '' !== $formats[0] ? $formats[0] : $def_both;
            $fmt_min  = isset( $formats[1] ) && '' !== $formats[1] ? $formats[1] : $def_min;
            $fmt_max  = isset( $formats[2] ) && '' !== $formats[2] ? $formats[2] : $def_max;

            if ( $min !== '' && $max !== '' ) {
                if ( $min === $max ) {
                    $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
                } else {
                    $range_str = str_replace(
                        array( '{min}', '{max}' ),
                        array( self::to_persian_digits( $min ), self::to_persian_digits( $max ) ),
                        $fmt_both
                    );
                }
            } elseif ( $min !== '' ) {
                $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
            } else {
                $range_str = str_replace( '{max}', self::to_persian_digits( $max ), $fmt_max );
            }

            return $range_str;
        } elseif ( 'array' === $type ) {
            $val = get_post_meta( $product_id, '_brz_spec_' . $spec_key, true );
            if ( empty( $val ) ) {
                return '';
            }
            $decoded = json_decode( $val, true );
            if ( ! is_array( $decoded ) ) {
                $decoded = maybe_unserialize( $val );
            }
            if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                return '';
            }
            $persian_values = array_map( array( __CLASS__, 'to_persian_digits' ), $decoded );
            $suffix_display = $suffix;
            if ( ! empty( $suffix_display ) && ! in_array( substr( $suffix_display, 0, 1 ), array( ' ', '؛', ';', '<' ), true ) ) {
                $suffix_display = ' ' . $suffix_display;
            }
            return $prefix . implode( '، ', $persian_values ) . $suffix_display;
        } elseif ( 'integer' === $type || 'decimal' === $type ) {
            $val = get_post_meta( $product_id, '_brz_spec_' . $spec_key, true );
            if ( $val === '' ) {
                return '';
            }
            $suffix_display = $suffix;
            if ( ! empty( $suffix_display ) && ! in_array( substr( $suffix_display, 0, 1 ), array( ' ', '؛', ';', '<' ), true ) ) {
                $suffix_display = ' ' . $suffix_display;
            }
            return $prefix . self::to_persian_digits( $val ) . $suffix_display;
        } elseif ( 'string' === $type || 'text' === $type ) {
            $val = get_post_meta( $product_id, '_brz_spec_' . $spec_key, true );
            if ( $val === '' ) {
                return '';
            }
            return $prefix . $val . $suffix;
        }

        return '';
    }
}
