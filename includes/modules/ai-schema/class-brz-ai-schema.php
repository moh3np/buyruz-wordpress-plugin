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

        // Frontend: only hook on single product pages.
        // Use wp hook (fires after query is parsed) to safely call is_product().
        add_action( 'wp', function() {
            if ( function_exists( 'is_product' ) && is_product() ) {
                add_filter(
                    'rank_math/snippet/rich_snippet_product_entity',
                    array( 'BRZ_AI_Schema', 'inject_schema' ),
                    20
                );
            }
        } );
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
        </style>

        <div class="brz-single-column" dir="rtl">
            <form id="brz-ai-schema-form">
                <?php wp_nonce_field( 'brz_ai_schema_save', '_wpnonce' ); ?>

                <!-- PropertyValue Card -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>ویژگی‌های PropertyValue</h3>
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

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'brz_save_ai_schema',
                        _wpnonce: $('#_wpnonce').val(),
                        properties: properties,
                        item_condition: $('#brz-ai-schema-condition').is(':checked') ? 1 : 0
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

        // Get current options and update AI Schema keys.
        $opts = get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        $opts['ai_schema_properties']     = $properties;
        $opts['ai_schema_item_condition'] = $item_condition;

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

        // If no properties to inject and item_condition is disabled, return unmodified.
        if ( empty( $properties ) && ! $item_condition ) {
            return $entity;
        }

        // Append PropertyValue entries to additionalProperty.
        if ( ! empty( $properties ) ) {
            // If additionalProperty already exists and is an array, merge/append.
            if ( isset( $entity['additionalProperty'] ) && is_array( $entity['additionalProperty'] ) ) {
                foreach ( $properties as $p ) {
                    $entity['additionalProperty'][] = array(
                        '@type' => 'PropertyValue',
                        'name'  => $p['name'],
                        'value' => $p['value'],
                    );
                }
            } else {
                // Initialize as new array with module's entries.
                $entity['additionalProperty'] = array();
                foreach ( $properties as $p ) {
                    $entity['additionalProperty'][] = array(
                        '@type' => 'PropertyValue',
                        'name'  => $p['name'],
                        'value' => $p['value'],
                    );
                }
            }
        }

        // If item_condition enabled and offers exists, set itemCondition.
        if ( $item_condition && isset( $entity['offers'] ) ) {
            $entity['offers']['itemCondition'] = 'https://schema.org/NewCondition';
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
}
