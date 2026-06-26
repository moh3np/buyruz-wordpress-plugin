<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Product Guarantee Tab module.
 *
 * Adds a custom WooCommerce product tab displaying accordion-based guarantee,
 * shipping, and support information on all product pages. Content is managed
 * via the admin settings page and rendered using the existing faq.css/faq.js assets.
 */
class BRZ_Product_Guarantee_Tab {

    /**
     * Bootstrap the module.
     *
     * Registers hooks based on context (admin vs frontend).
     * Admin: registers AJAX handler and admin assets enqueue.
     * Frontend: registers woocommerce_product_tabs filter.
     */
    public static function init(): void {
        if ( is_admin() ) {
            add_action( 'wp_ajax_brz_guarantee_tab_save', array( __CLASS__, 'ajax_save' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
            return;
        }

        // Frontend: register tab filter.
        add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_tab' ), 25 );
        add_action( 'wp_head', array( __CLASS__, 'print_tab_icon_css' ) );
    }

    /**
     * Render the admin settings page.
     *
     * Outputs HTML + inline JS inside the existing BRZ shell.
     * Three cards: Tab Title, Accordion Items, Accordion Settings.
     */
    public static function render_admin_page(): void {
        $items       = self::get_items();
        $title       = self::get_title();
        $description = self::get_description();
        $settings    = self::get_accordion_settings();
        ?>
        <style>
            .brz-gt-row {
                display: flex;
                flex-direction: column;
                gap: var(--md-space-sm, 8px);
                padding: var(--md-space-md, 16px);
                margin-bottom: var(--md-space-md, 16px);
                background: var(--md-surface, #fff);
                border: 1px solid var(--md-outline-variant, #e0e0e0);
                border-radius: 8px;
                transition: box-shadow 0.2s;
                position: relative;
            }
            .brz-gt-row:nth-child(even) {
                background: #f8f9fa;
            }
            .brz-gt-row:hover {
                box-shadow: var(--md-elevation-1, 0 2px 6px rgba(0,0,0,.1));
                border-color: var(--brz-brand, #1a73e8);
            }
            .brz-gt-row-header {
                display: flex;
                align-items: center;
                gap: var(--md-space-sm, 8px);
            }
            .brz-gt-handle {
                cursor: grab;
                color: var(--md-on-surface-variant, #666);
                font-size: 18px;
                padding: var(--md-space-xs, 4px);
                user-select: none;
                flex-shrink: 0;
            }
            .brz-gt-handle:active {
                cursor: grabbing;
            }
            .brz-gt-row input[data-field="title"] {
                flex: 1;
                padding: var(--md-space-xs, 4px) var(--md-space-sm, 8px);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                min-width: 0;
            }
            .brz-gt-row input[data-field="title"]:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-gt-row textarea {
                width: 100%;
                padding: var(--md-space-xs, 4px) var(--md-space-sm, 8px);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 14px;
                resize: vertical;
                min-height: 60px;
            }
            .brz-gt-row textarea:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-gt-row input.brz-field-error,
            .brz-gt-row textarea.brz-field-error {
                border-color: #d32f2f;
                box-shadow: 0 0 0 2px rgba(211,47,47,.12);
            }
            .brz-gt-delete {
                background: none;
                border: none;
                color: var(--md-error, #d32f2f);
                cursor: pointer;
                font-size: 18px;
                padding: var(--md-space-xs, 4px);
                border-radius: 4px;
                flex-shrink: 0;
                transition: background 0.15s;
            }
            .brz-gt-delete:hover {
                background: rgba(211,47,47,.08);
            }
            .brz-gt-placeholder {
                border: 2px dashed var(--brz-brand, #1a73e8);
                border-radius: 8px;
                background: rgba(26,115,232,.04);
                margin-bottom: var(--md-space-xs, 4px);
                height: 48px;
            }
            .brz-gt-empty {
                text-align: center;
                color: var(--md-on-surface-variant, #666);
                padding: var(--md-space-xl, 32px) var(--md-space-md, 16px);
                font-size: 14px;
            }
            .brz-gt-settings-row {
                display: flex;
                align-items: center;
                gap: var(--md-space-sm, 8px);
                margin-bottom: var(--md-space-md, 16px);
            }
            .brz-gt-settings-row label {
                min-width: 160px;
                font-size: 14px;
                color: var(--md-on-surface, #333);
            }
            .brz-gt-settings-row input[type="number"],
            .brz-gt-settings-row select {
                padding: var(--md-space-xs, 4px) var(--md-space-sm, 8px);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 14px;
                width: 120px;
            }
            .brz-gt-settings-row input[type="number"]:focus,
            .brz-gt-settings-row select:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-gt-link-row {
                display: flex;
                align-items: center;
                gap: var(--md-space-xs, 4px);
                width: 100%;
                direction: ltr;
            }
            .brz-gt-link-row::before {
                content: "🔗";
                flex-shrink: 0;
                font-size: 14px;
            }
            .brz-gt-link-row input[type="url"] {
                flex: 1;
                padding: var(--md-space-xs, 4px) var(--md-space-sm, 8px);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 13px;
                min-width: 0;
                direction: ltr;
            }
            .brz-gt-link-row input[type="text"] {
                flex: 1;
                padding: var(--md-space-xs, 4px) var(--md-space-sm, 8px);
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 13px;
                min-width: 0;
                direction: rtl;
            }
            .brz-gt-link-row input:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
        </style>

        <div class="brz-single-column" dir="rtl">
            <form id="brz-guarantee-tab-form">
                <?php wp_nonce_field( 'brz_guarantee_tab_save', '_wpnonce' ); ?>

                <!-- Card 1: Tab Title -->
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>عنوان تب</h3>
                    </div>
                    <div class="brz-card__body">
                        <input type="text" id="brz-gt-title" value="<?php echo esc_attr( $title ); ?>" maxlength="100" style="width:100%;padding:var(--md-space-xs) var(--md-space-sm);border:1px solid var(--md-outline-variant,#ccc);border-radius:6px;font-size:14px;" />
                        <p class="description" style="margin-top:var(--md-space-sm);color:var(--md-on-surface-variant,#666);">
                            عنوان تبی که در صفحه محصول نمایش داده می‌شود. پیش‌فرض: ضمانت و ارسال
                        </p>
                    </div>
                </div>

                <!-- Card 1.5: Tab Description -->
                <div class="brz-card" style="margin-top:var(--md-space-lg);">
                    <div class="brz-card__header">
                        <h3>توضیحات تب</h3>
                    </div>
                    <div class="brz-card__body">
                        <input type="text" id="brz-gt-description" value="<?php echo esc_attr( $description ); ?>" maxlength="200" style="width:100%;padding:var(--md-space-xs) var(--md-space-sm);border:1px solid var(--md-outline-variant,#ccc);border-radius:6px;font-size:14px;" />
                        <p class="description" style="margin-top:var(--md-space-sm);color:var(--md-on-surface-variant,#666);">
                            توضیحی که زیر عنوان تب در صفحه محصول نمایش داده می‌شود. خالی بگذارید اگر نیازی ندارید.
                        </p>
                    </div>
                </div>

                <!-- Card 2: Accordion Items -->
                <div class="brz-card" style="margin-top:var(--md-space-lg);">
                    <div class="brz-card__header">
                        <h3>آیتم‌های آکاردئون</h3>
                    </div>
                    <div class="brz-card__body">
                        <div id="brz-gt-list">
                            <?php if ( ! empty( $items ) ) : ?>
                                <?php foreach ( $items as $item ) : ?>
                                    <div class="brz-gt-row">
                                        <div class="brz-gt-row-header">
                                            <span class="brz-gt-handle" aria-hidden="true">☰</span>
                                            <input type="text" data-field="title" value="<?php echo esc_attr( $item['title'] ); ?>" placeholder="عنوان آیتم" maxlength="200" />
                                            <button type="button" class="brz-gt-delete" title="حذف آیتم">✕</button>
                                        </div>
                                        <textarea data-field="content" placeholder="محتوای آیتم (هر خط = یک سطر در فرانت‌اند)" maxlength="2000"><?php echo esc_textarea( $item['content'] ); ?></textarea>
                                        <div class="brz-gt-link-row">
                                            <input type="text" data-field="link_text" value="<?php echo esc_attr( $item['link_text'] ?? '' ); ?>" placeholder="متن نمایشی (مثال: مطالعه قوانین مرجوعی)" maxlength="100" />
                                            <input type="url" data-field="link_url" value="<?php echo esc_attr( $item['link_url'] ?? '' ); ?>" placeholder="آدرس لینک (اختیاری)" />
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="brz-gt-empty">هنوز آیتمی اضافه نشده است. برای شروع روی «افزودن آیتم» کلیک کنید.</div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:var(--md-space-md);">
                            <button type="button" id="brz-gt-add" class="brz-button brz-button--secondary">افزودن آیتم</button>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Accordion Settings -->
                <div class="brz-card" style="margin-top:var(--md-space-lg);">
                    <div class="brz-card__header">
                        <h3>تنظیمات ظاهری آکاردئون</h3>
                    </div>
                    <div class="brz-card__body">
                        <div class="brz-gt-settings-row">
                            <label for="brz-gt-border-radius">شعاع گوشه‌ها (px)</label>
                            <input type="number" id="brz-gt-border-radius" value="<?php echo esc_attr( $settings['border_radius'] ); ?>" min="0" />
                        </div>
                        <div class="brz-gt-settings-row">
                            <label for="brz-gt-fz-question">اندازه فونت عنوان (px)</label>
                            <input type="number" id="brz-gt-fz-question" value="<?php echo esc_attr( $settings['font_size_question'] ); ?>" min="8" />
                        </div>
                        <div class="brz-gt-settings-row">
                            <label for="brz-gt-fz-answer">اندازه فونت پاسخ (px)</label>
                            <input type="number" id="brz-gt-fz-answer" value="<?php echo esc_attr( $settings['font_size_answer'] ); ?>" min="8" />
                        </div>
                        <div class="brz-gt-settings-row">
                            <label for="brz-gt-icon-type">نوع آیکون</label>
                            <select id="brz-gt-icon-type">
                                <option value="chevron" <?php selected( $settings['icon_type'], 'chevron' ); ?>>شورون</option>
                                <option value="plus-minus" <?php selected( $settings['icon_type'], 'plus-minus' ); ?>>بعلاوه/منها</option>
                                <option value="arrow" <?php selected( $settings['icon_type'], 'arrow' ); ?>>فلش</option>
                            </select>
                        </div>
                        <div class="brz-gt-settings-row">
                            <label for="brz-gt-spacing">فاصله بین آیتم‌ها (px)</label>
                            <input type="number" id="brz-gt-spacing" value="<?php echo esc_attr( $settings['spacing'] ); ?>" min="0" />
                        </div>
                    </div>
                </div>

                <!-- Save Bar -->
                <div class="brz-save-bar" style="margin-top:var(--md-space-lg);">
                    <button type="button" id="brz-gt-save" class="brz-button brz-button--primary">ذخیره تغییرات</button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($){
            'use strict';

            var MAX_ITEMS = 20;
            var $list = $('#brz-gt-list');
            var $addBtn = $('#brz-gt-add');

            // 1. Sortable init.
            $list.sortable({
                handle: '.brz-gt-handle',
                axis: 'y',
                placeholder: 'brz-gt-placeholder'
            });

            // 8. Update add button state.
            function updateAddButton() {
                $addBtn.prop('disabled', $list.children('.brz-gt-row').length >= MAX_ITEMS);
            }

            // 9. Empty state toggle.
            function toggleEmptyState() {
                if ($list.children('.brz-gt-row').length === 0) {
                    if ($list.find('.brz-gt-empty').length === 0) {
                        $list.append('<div class="brz-gt-empty">هنوز آیتمی اضافه نشده است. برای شروع روی «افزودن آیتم» کلیک کنید.</div>');
                    }
                } else {
                    $list.find('.brz-gt-empty').remove();
                }
            }

            // Build HTML for a new row.
            function buildRowHtml(title, content, linkUrl, linkText) {
                linkUrl = linkUrl || '';
                linkText = linkText || '';
                return '<div class="brz-gt-row">' +
                    '<div class="brz-gt-row-header">' +
                        '<span class="brz-gt-handle" aria-hidden="true">☰</span>' +
                        '<input type="text" data-field="title" value="' + escAttr(title) + '" placeholder="عنوان آیتم" maxlength="200" />' +
                        '<button type="button" class="brz-gt-delete" title="حذف آیتم">✕</button>' +
                    '</div>' +
                    '<textarea data-field="content" placeholder="محتوای آیتم (هر خط = یک سطر در فرانت‌اند)" maxlength="2000">' + escAttr(content) + '</textarea>' +
                    '<div class="brz-gt-link-row">' +
                        '<input type="text" data-field="link_text" value="' + escAttr(linkText) + '" placeholder="متن نمایشی (مثال: مطالعه قوانین مرجوعی)" maxlength="100" />' +
                        '<input type="url" data-field="link_url" value="' + escAttr(linkUrl) + '" placeholder="آدرس لینک (اختیاری)" />' +
                    '</div>' +
                '</div>';
            }

            // Simple HTML attribute escaping.
            function escAttr(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML.replace(/"/g, '&quot;');
            }

            // 6. Snackbar notifications.
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

            // 2. Add item handler.
            $addBtn.on('click', function() {
                if ($list.children('.brz-gt-row').length >= MAX_ITEMS) {
                    $(this).prop('disabled', true);
                    return;
                }
                $list.find('.brz-gt-empty').remove();
                $list.append(buildRowHtml('', ''));
                $list.sortable('refresh');
                updateAddButton();
            });

            // 3. Delete item handler (delegated) with confirmation.
            $list.on('click', '.brz-gt-delete', function() {
                if (!confirm('آیا از حذف این آیتم اطمینان دارید؟')) {
                    return;
                }
                $(this).closest('.brz-gt-row').remove();
                updateAddButton();
                toggleEmptyState();
            });

            // 5. Validation: clear error on focus.
            $list.on('focus', 'input.brz-field-error', function() {
                $(this).removeClass('brz-field-error');
            });

            // 4. Save via AJAX.
            $('#brz-gt-save').on('click', function() {
                var $btn = $(this);
                var hasError = false;

                // 5. Validation: empty title → red border.
                $list.find('.brz-gt-row input[data-field="title"]').each(function() {
                    if ($.trim($(this).val()) === '') {
                        $(this).addClass('brz-field-error');
                        hasError = true;
                    }
                });

                if (hasError) {
                    showSnackbar('لطفاً عنوان تمامی آیتم‌ها را وارد کنید.', 'error');
                    return;
                }

                // 7. setBusy pattern on save button.
                $btn.prop('disabled', true).text('در حال ذخیره…');

                // Collect items as array of {title, content, link_url, link_text} from DOM.
                var items = [];
                $list.find('.brz-gt-row').each(function() {
                    items.push({
                        title: $(this).find('[data-field="title"]').val(),
                        content: $(this).find('[data-field="content"]').val(),
                        link_url: $(this).find('[data-field="link_url"]').val(),
                        link_text: $(this).find('[data-field="link_text"]').val()
                    });
                });

                // Collect tab_title.
                var tab_title = $('#brz-gt-title').val();

                // Collect tab_description.
                var tab_description = $('#brz-gt-description').val();

                // Collect all accordion_* settings.
                var accordion_border_radius = $('#brz-gt-border-radius').val();
                var accordion_font_size_question = $('#brz-gt-fz-question').val();
                var accordion_font_size_answer = $('#brz-gt-fz-answer').val();
                var accordion_icon_type = $('#brz-gt-icon-type').val();
                var accordion_spacing = $('#brz-gt-spacing').val();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'brz_guarantee_tab_save',
                        _wpnonce: $('#_wpnonce').val(),
                        items: items,
                        tab_title: tab_title,
                        tab_description: tab_description,
                        accordion_border_radius: accordion_border_radius,
                        accordion_font_size_question: accordion_font_size_question,
                        accordion_font_size_answer: accordion_font_size_answer,
                        accordion_icon_type: accordion_icon_type,
                        accordion_spacing: accordion_spacing
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
            toggleEmptyState();
        });
        </script>
        <?php
    }

    /**
     * Enqueue admin assets for the Product Guarantee Tab module page.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public static function enqueue_admin_assets( $hook_suffix ): void {
        // Only load on our module page.
        if ( ! isset( $_GET['page'] ) || 'buyruz-module-product_guarantee_tab' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        wp_enqueue_script( 'jquery-ui-sortable' );
    }

    /**
     * Handle wp_ajax_brz_guarantee_tab_save action.
     *
     * Checks capability, verifies nonce, sanitizes input, persists, responds JSON.
     */
    public static function ajax_save(): void {
        // Capability check MUST come before nonce verification (Requirement 9.6).
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        // Verify nonce.
        if ( ! check_ajax_referer( 'brz_guarantee_tab_save', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست امنیتی نامعتبر است.' ), 403 );
        }

        // Read and sanitize accordion items.
        $raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
        $items     = self::sanitize_items( $raw_items );

        // Sanitize tab title: sanitize_text_field, max 100 chars, default if empty.
        $tab_title = isset( $_POST['tab_title'] ) ? sanitize_text_field( $_POST['tab_title'] ) : '';
        $tab_title = mb_substr( $tab_title, 0, 100 );
        if ( '' === $tab_title ) {
            $tab_title = 'ضمانت و ارسال';
        }

        // Sanitize tab description: sanitize_text_field, max 200 chars, allow empty.
        $tab_description = isset( $_POST['tab_description'] ) ? sanitize_text_field( $_POST['tab_description'] ) : '';
        $tab_description = mb_substr( $tab_description, 0, 200 );

        // Sanitize accordion settings.
        $accordion_border_radius      = isset( $_POST['accordion_border_radius'] )      ? absint( $_POST['accordion_border_radius'] )      : 12;
        $accordion_font_size_question = isset( $_POST['accordion_font_size_question'] ) ? absint( $_POST['accordion_font_size_question'] ) : 15;
        $accordion_font_size_answer   = isset( $_POST['accordion_font_size_answer'] )   ? absint( $_POST['accordion_font_size_answer'] )   : 14;
        $accordion_spacing            = isset( $_POST['accordion_spacing'] )            ? absint( $_POST['accordion_spacing'] )            : 10;

        // Sanitize icon_type with allowlist.
        $allowed_icons     = array( 'chevron', 'plus-minus', 'arrow' );
        $accordion_icon_type = isset( $_POST['accordion_icon_type'] ) ? sanitize_key( $_POST['accordion_icon_type'] ) : 'chevron';
        if ( ! in_array( $accordion_icon_type, $allowed_icons, true ) ) {
            $accordion_icon_type = 'chevron';
        }

        // Get current options and update guarantee tab keys.
        $opts = get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }

        $opts['guarantee_tab_items']          = $items;
        $opts['guarantee_tab_title']          = $tab_title;
        $opts['guarantee_tab_description']    = $tab_description;
        $opts['accordion_border_radius']      = $accordion_border_radius;
        $opts['accordion_font_size_question'] = $accordion_font_size_question;
        $opts['accordion_font_size_answer']   = $accordion_font_size_answer;
        $opts['accordion_icon_type']          = $accordion_icon_type;
        $opts['accordion_spacing']            = $accordion_spacing;

        // Persist with autoload disabled (consistent with existing plugin pattern).
        update_option( BRZ_OPTION, $opts, false );

        wp_send_json_success( array( 'message' => 'تغییرات ذخیره شد.' ) );
    }

    /**
     * Filter callback for woocommerce_product_tabs.
     *
     * Adds 'guarantee' tab at priority 25 if items exist.
     *
     * @param array $tabs Existing WooCommerce product tabs.
     * @return array Modified tabs array.
     */
    public static function add_tab( array $tabs ): array {
        $items = self::get_items();

        if ( empty( $items ) ) {
            return $tabs;
        }

        $tabs['guarantee'] = array(
            'title'    => self::get_title(),
            'callback' => array( __CLASS__, 'render_tab_content' ),
            'priority' => 25,
        );

        return $tabs;
    }

    /**
     * Print inline CSS for the guarantee tab icon.
     *
     * Uses the bakala icon font to match the theme's other product tab icons.
     */
    public static function print_tab_icon_css(): void {
        if ( ! is_product() ) {
            return;
        }
        ?>
        <style id="brz-guarantee-tab-icon">
        .woocommerce div.product .woocommerce-tabs ul.tabs li.guarantee_tab a::before {
            height: 18px;
            content: "\E0EB";
            font-size: 28px;
            font-family: bakala;
            width: 40px;
            text-align: right;
            font-weight: normal;
        }
        </style>
        <?php
    }

    /**
     * Tab content callback. Outputs accordion HTML with rank-math class structure.
     *
     * Renders items server-side using the same markup consumed by faq.css/faq.js.
     */
    public static function render_tab_content(): void {
        $items       = self::get_items();
        $title       = self::get_title();
        $description = self::get_description();

        // Safety check: if no items, output nothing.
        if ( empty( $items ) ) {
            return;
        }

        // Tab heading + description (matching bakala theme structure).
        echo '<h2 class="title">' . esc_html( $title ) . '</h2>';
        if ( '' !== $description ) {
            echo '<p class="brz-tab-description" style="margin-bottom:20px;color:#4d4d4d;font-size:14px;">' . esc_html( $description ) . '</p>';
        }

        echo '<div class="rank-math-block">';
        echo '<ul class="rank-math-list">';

        foreach ( $items as $item ) {
            echo '<li class="rank-math-list-item">';
            echo '<h3 class="rank-math-question">' . esc_html( $item['title'] ) . '</h3>';
            echo '<div class="rank-math-answer">';
            echo nl2br( esc_html( $item['content'] ) );

            // CTA link at the end of answer content.
            $link_url  = $item['link_url'] ?? '';
            $link_text = $item['link_text'] ?? '';
            if ( '' !== $link_url && '' !== $link_text ) {
                echo '<p class="brz-accordion-cta" style="margin-top:12px;"><a href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener noreferrer">🔗 ' . esc_html( $link_text ) . '</a></p>';
            }

            echo '</div>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Read guarantee_tab_items from brz_options.
     *
     * Uses BRZ_Settings::get() for cached option access.
     *
     * @return array Indexed array of item entries, or empty array if missing/invalid.
     */
    private static function get_items(): array {
        $items = BRZ_Settings::get( 'guarantee_tab_items', array() );

        if ( ! is_array( $items ) ) {
            return array();
        }

        return $items;
    }

    /**
     * Read guarantee_tab_title from brz_options.
     *
     * Uses BRZ_Settings::get() for cached option access.
     *
     * @return string The tab title, or default if missing/empty.
     */
    private static function get_title(): string {
        $title = BRZ_Settings::get( 'guarantee_tab_title', '' );

        if ( ! is_string( $title ) || '' === $title ) {
            return 'ضمانت و ارسال';
        }

        return $title;
    }

    /**
     * Read guarantee_tab_description from brz_options.
     *
     * @return string The tab description, or empty string if missing.
     */
    private static function get_description(): string {
        $desc = BRZ_Settings::get( 'guarantee_tab_description', '' );

        if ( ! is_string( $desc ) ) {
            return '';
        }

        return $desc;
    }

    /**
     * Read accordion_* keys from brz_options.
     *
     * Returns array with defaults applied for missing keys.
     *
     * @return array Associative array of accordion settings with defaults.
     */
    private static function get_accordion_settings(): array {
        $border_radius      = BRZ_Settings::get( 'accordion_border_radius', 12 );
        $font_size_question = BRZ_Settings::get( 'accordion_font_size_question', 15 );
        $font_size_answer   = BRZ_Settings::get( 'accordion_font_size_answer', 14 );
        $icon_type          = BRZ_Settings::get( 'accordion_icon_type', 'chevron' );
        $spacing            = BRZ_Settings::get( 'accordion_spacing', 10 );

        return array(
            'border_radius'      => absint( $border_radius ) > 0 ? absint( $border_radius ) : 12,
            'font_size_question' => absint( $font_size_question ) > 0 ? absint( $font_size_question ) : 15,
            'font_size_answer'   => absint( $font_size_answer ) > 0 ? absint( $font_size_answer ) : 14,
            'icon_type'          => in_array( $icon_type, array( 'chevron', 'plus-minus', 'arrow' ), true ) ? $icon_type : 'chevron',
            'spacing'            => absint( $spacing ) > 0 ? absint( $spacing ) : 10,
        );
    }

    /**
     * Sanitize and filter an array of Accordion_Item entries.
     *
     * Applies sanitize_text_field() to title (max 200 chars),
     * sanitize_content() to content (converts <br> to newlines, strips HTML, max 2000 chars),
     * and excludes entries where title is empty after sanitization.
     *
     * @param array $raw Raw array of item entries.
     * @return array Clean indexed array of valid entries.
     */
    private static function sanitize_items( array $raw ): array {
        $clean = array();
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $title     = isset( $entry['title'] )     ? sanitize_text_field( $entry['title'] )     : '';
            $content   = isset( $entry['content'] )   ? self::sanitize_content( $entry['content'] ) : '';
            $link_url  = isset( $entry['link_url'] )  ? esc_url_raw( $entry['link_url'] )          : '';
            $link_text = isset( $entry['link_text'] ) ? sanitize_text_field( $entry['link_text'] ) : '';

            // Enforce max lengths.
            $title     = mb_substr( $title, 0, 200 );
            $content   = mb_substr( $content, 0, 2000 );
            $link_text = mb_substr( $link_text, 0, 100 );

            // Exclude entries with empty title.
            if ( '' === $title ) {
                continue;
            }

            $clean[] = array(
                'title'     => $title,
                'content'   => $content,
                'link_url'  => $link_url,
                'link_text' => $link_text,
            );
        }
        return array_values( $clean );
    }

    /**
     * Sanitize accordion item content.
     *
     * Converts <br> tags to newlines, strips all HTML, and normalizes whitespace.
     * This allows admin to simply press Enter in textarea — nl2br() is applied at render time.
     * Backward compatible: existing content with <br> tags is converted to newlines on next save.
     *
     * @param string $raw Raw content string.
     * @return string Sanitized plaintext with preserved newlines.
     */
    private static function sanitize_content( string $raw ): string {
        // Convert <br>, <br/>, <br /> to newline for backward compatibility.
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $raw );

        // Strip all remaining HTML tags.
        $text = wp_strip_all_tags( $text );

        // Normalize multiple consecutive newlines to max 2.
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );

        // Trim leading/trailing whitespace per line, but preserve newlines.
        $lines = explode( "\n", $text );
        $lines = array_map( 'trim', $lines );
        $text  = implode( "\n", $lines );

        return trim( $text );
    }
}
