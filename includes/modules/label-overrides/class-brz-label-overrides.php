<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Label Overrides module.
 *
 * Allows admins to replace gettext-translated theme strings from the Buyruz
 * Settings panel. Hooks into WordPress `gettext` and `gettext_with_context`
 * filters on frontend requests and substitutes matching strings with
 * admin-defined replacements stored in brz_options.
 */
class BRZ_Label_Overrides {

    /** @var array Resolved entry definitions. */
    private static array $entries = [];

    /** @var array text_domain::msgid → replacement lookup cache. */
    private static array $lookup_cache = [];

    /** @var bool Whether the module has been initialized. */
    private static bool $initialized = false;

    /**
     * Bootstrap the module.
     *
     * Resolves entries, builds the lookup cache, and conditionally attaches
     * hooks based on context (admin vs frontend).
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        self::$entries = self::resolve_entries();

        // Admin context: register AJAX handler and deactivation hooks.
        if ( is_admin() ) {
            add_action( 'wp_ajax_brz_save_label_overrides', array( __CLASS__, 'ajax_save' ) );

            // Hook into module toggle to trigger cleanup when this module is turned off.
            add_action( 'admin_post_brz_toggle_module', array( __CLASS__, 'maybe_deactivate' ), 5 );
            add_action( 'wp_ajax_brz_toggle_module', array( __CLASS__, 'maybe_deactivate' ), 5 );
            return;
        }

        // Frontend context (non-admin, non-REST): attach gettext filters if needed.
        $is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
        if ( ! $is_rest ) {
            self::$lookup_cache = self::build_lookup_cache();

            if ( ! empty( self::$lookup_cache ) ) {
                add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 20, 3 );
                add_filter( 'gettext_with_context', array( __CLASS__, 'filter_gettext_with_context' ), 20, 4 );
            }
        }
    }

    /**
     * Resolve the list of overridable label entries.
     *
     * Defines default entries, applies the filter, validates, and deduplicates.
     *
     * @return array Validated entry definitions.
     */
    private static function resolve_entries(): array {
        $entries = [
            [
                'id'          => 'bakala-stock-with-wait',
                'msgid'       => 'This Product is in Stock. You Must Wait for Deliver',
                'text_domain' => 'bakala',
                'description' => 'پیام موجودی با انتظار ارسال',
            ],
            [
                'id'          => 'bakala-stock-ready',
                'msgid'       => 'This Product is in Stock & Ready to Deliver',
                'text_domain' => 'bakala',
                'description' => 'پیام موجودی آماده ارسال',
            ],
        ];

        /** @var array $entries Filterable list of override entry definitions. */
        $entries = apply_filters( 'brz/label_overrides/entries', $entries );

        if ( ! is_array( $entries ) ) {
            return [];
        }

        $validated = [];
        $seen_ids  = [];

        foreach ( $entries as $entry ) {
            // Must be an array with all required fields.
            if ( ! is_array( $entry ) ) {
                continue;
            }

            if (
                empty( $entry['id'] ) ||
                empty( $entry['msgid'] ) ||
                empty( $entry['text_domain'] ) ||
                empty( $entry['description'] )
            ) {
                continue;
            }

            // Validate id format: lowercase alphanumeric, hyphens, underscores, max 64 chars.
            if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $entry['id'] ) ) {
                continue;
            }

            // Discard duplicates (keep first occurrence).
            if ( isset( $seen_ids[ $entry['id'] ] ) ) {
                continue;
            }

            $seen_ids[ $entry['id'] ] = true;
            $validated[] = $entry;
        }

        return $validated;
    }

    /**
     * Build a composite key for O(1) lookup.
     *
     * @param string $domain Text domain.
     * @param string $msgid  Original message ID.
     * @return string Composite key in format "domain::msgid".
     */
    private static function make_key( string $domain, string $msgid ): string {
        return $domain . '::' . $msgid;
    }

    /**
     * Render the admin settings page for label overrides.
     */
    public static function render_admin_page(): void {
        $entries = self::$entries;
        $stored  = self::get_stored_overrides();
        ?>
        <div class="brz-single-column" dir="rtl">
            <form id="brz-label-overrides-form">
                <?php wp_nonce_field( 'brz_label_overrides_nonce', 'brz_label_overrides_nonce' ); ?>

                <?php if ( empty( $entries ) ) : ?>
                    <div class="brz-card">
                        <div class="brz-card__body">
                            <p style="text-align:center;color:var(--md-on-surface-variant);">هیچ برچسبی برای ویرایش تعریف نشده است.</p>
                        </div>
                    </div>
                <?php else : ?>

                    <?php foreach ( $entries as $entry ) :
                        $id          = esc_attr( $entry['id'] );
                        $msgid       = $entry['msgid'];
                        $desc        = esc_html( $entry['description'] );
                        $domain      = esc_html( $entry['text_domain'] );
                        $value       = isset( $stored[ $entry['id'] ] ) ? esc_textarea( $stored[ $entry['id'] ] ) : '';
                        $display_msgid = mb_strlen( $msgid ) > 120 ? mb_substr( $msgid, 0, 120 ) . '…' : $msgid;
                    ?>
                    <div class="brz-card">
                        <div class="brz-card__header">
                            <h3><?php echo $desc; ?></h3>
                            <span class="brz-badge"><?php echo $domain; ?></span>
                        </div>
                        <div class="brz-card__body">
                            <p class="description" style="margin-bottom:12px;">
                                <strong>متن اصلی:</strong>
                                <code style="display:block;margin-top:6px;padding:8px;background:#f0f0f1;border-radius:4px;font-size:13px;word-break:break-word;"><?php echo esc_html( $display_msgid ); ?></code>
                            </p>
                            <label for="brz-override-<?php echo $id; ?>"><strong>متن جایگزین:</strong></label>
                            <textarea
                                id="brz-override-<?php echo $id; ?>"
                                name="overrides[<?php echo $id; ?>]"
                                data-entry-id="<?php echo $id; ?>"
                                rows="4"
                                maxlength="5000"
                                class="large-text"
                                placeholder="متن اصلی تم نمایش داده می‌شود"
                                style="margin-top:8px;"
                            ><?php echo $value; ?></textarea>
                            <p class="description" style="margin-top:8px;color:#666;">
                                توجه: اگر تم از توابع escape مانند <code>esc_html_e()</code> استفاده کند، تگ‌های HTML در متن جایگزین رندر نخواهند شد.
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="brz-save-bar">
                        <button type="button" id="brz-label-overrides-save" class="brz-button brz-button--primary">ذخیره تغییرات</button>
                    </div>

                <?php endif; ?>
            </form>

            <script>
            (function($){
                'use strict';
                $('#brz-label-overrides-save').on('click', function(e){
                    e.preventDefault();
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('در حال ذخیره…');

                    var formData = {
                        action: 'brz_save_label_overrides',
                        brz_label_overrides_nonce: $('#brz_label_overrides_nonce').val(),
                        overrides: {}
                    };

                    $('#brz-label-overrides-form textarea[data-entry-id]').each(function(){
                        formData.overrides[$(this).data('entry-id')] = $(this).val();
                    });

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        success: function(res){
                            var snackbar = document.getElementById('brz-snackbar');
                            if (res.success) {
                                if (snackbar) {
                                    snackbar.textContent = res.data.message || 'ذخیره شد.';
                                    snackbar.classList.add('is-visible', 'is-success');
                                }
                            } else {
                                if (snackbar) {
                                    snackbar.textContent = res.data.message || 'خطا در ذخیره‌سازی.';
                                    snackbar.classList.add('is-visible', 'is-error');
                                }
                            }
                            setTimeout(function(){
                                if(snackbar) snackbar.classList.remove('is-visible','is-success','is-error');
                            }, 4000);
                        },
                        error: function(){
                            var snackbar = document.getElementById('brz-snackbar');
                            if (snackbar) {
                                snackbar.textContent = 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.';
                                snackbar.classList.add('is-visible', 'is-error');
                            }
                            setTimeout(function(){
                                if(snackbar) snackbar.classList.remove('is-visible','is-error');
                            }, 4000);
                        },
                        complete: function(){
                            $btn.prop('disabled', false).text('ذخیره تغییرات');
                        }
                    });
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }

    /**
     * Handle AJAX save request for label overrides.
     */
    public static function ajax_save(): void {
        // Check capability.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        // Verify nonce.
        if ( ! check_ajax_referer( 'brz_label_overrides_nonce', 'brz_label_overrides_nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست امنیتی نامعتبر است.' ), 403 );
        }

        // Get submitted overrides.
        $raw_overrides = isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ? $_POST['overrides'] : array();

        // Build valid entry IDs set.
        $valid_ids = array();
        foreach ( self::$entries as $entry ) {
            $valid_ids[ $entry['id'] ] = true;
        }

        // Sanitize and filter.
        $sanitized = array();
        foreach ( $raw_overrides as $id => $value ) {
            $id = sanitize_key( $id );

            // Discard unknown identifiers.
            if ( ! isset( $valid_ids[ $id ] ) ) {
                continue;
            }

            // Ensure string.
            $value = is_string( $value ) ? $value : '';

            // Truncate to 2000 characters.
            if ( mb_strlen( $value ) > 2000 ) {
                $value = mb_substr( $value, 0, 2000 );
            }

            // Sanitize with wp_kses_post (allows safe HTML).
            $sanitized[ $id ] = wp_kses_post( $value );
        }

        // Ensure all known entries have a value (empty string if not submitted).
        foreach ( $valid_ids as $id => $_ ) {
            if ( ! isset( $sanitized[ $id ] ) ) {
                $sanitized[ $id ] = '';
            }
        }

        // Persist to database.
        $opts = get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        $opts['label_overrides'] = $sanitized;

        $updated = update_option( BRZ_OPTION, $opts, false );

        // update_option returns false if value unchanged OR on failure.
        // Check if the stored value matches to differentiate.
        $verify = get_option( BRZ_OPTION, array() );
        if ( isset( $verify['label_overrides'] ) && $verify['label_overrides'] === $sanitized ) {
            wp_send_json_success( array( 'message' => 'تغییرات ذخیره شد.' ) );
        }

        if ( ! $updated ) {
            wp_send_json_error( array( 'message' => 'خطا در ذخیره‌سازی. لطفاً دوباره تلاش کنید.' ) );
        }

        wp_send_json_success( array( 'message' => 'تغییرات ذخیره شد.' ) );
    }

    /**
     * Filter gettext translations on the frontend.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text (msgid).
     * @param string $domain      Text domain.
     * @return string Replacement or original translation.
     */
    public static function filter_gettext( string $translation, string $text, string $domain ): string {
        $key = self::make_key( $domain, $text );
        return isset( self::$lookup_cache[ $key ] ) ? self::$lookup_cache[ $key ] : $translation;
    }

    /**
     * Filter gettext_with_context translations on the frontend.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text (msgid).
     * @param string $context     Translation context.
     * @param string $domain      Text domain.
     * @return string Replacement or original translation.
     */
    public static function filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
        $key = self::make_key( $domain, $text );
        return isset( self::$lookup_cache[ $key ] ) ? self::$lookup_cache[ $key ] : $translation;
    }

    /**
     * Cleanup on module deactivation.
     *
     * Removes stored overrides from brz_options, unhooks gettext filters,
     * and clears internal state.
     */
    public static function deactivate(): void {
        // Remove stored overrides from brz_options.
        $opts = get_option( BRZ_OPTION, array() );
        if ( is_array( $opts ) && isset( $opts['label_overrides'] ) ) {
            unset( $opts['label_overrides'] );
            update_option( BRZ_OPTION, $opts, false );
        }

        // Unhook gettext filters.
        remove_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 20 );
        remove_filter( 'gettext_with_context', array( __CLASS__, 'filter_gettext_with_context' ), 20 );

        // Clear internal state.
        self::$lookup_cache = [];
        self::$entries      = [];
        self::$initialized  = false;
    }

    /**
     * Check if the module is being toggled off and trigger deactivation cleanup.
     *
     * Hooked early on admin_post_brz_toggle_module and wp_ajax_brz_toggle_module
     * so cleanup runs before the toggle handler updates module state.
     */
    public static function maybe_deactivate(): void {
        if ( ! isset( $_POST['module'] ) || 'label_overrides' !== $_POST['module'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if ( ! isset( $_POST['state'] ) || '0' !== (string) $_POST['state'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        // Module is being turned off — clean up.
        self::deactivate();
    }

    /**
     * Build the in-memory lookup cache from stored overrides.
     *
     * @return array Associative array of text_domain::msgid => replacement.
     */
    private static function build_lookup_cache(): array {
        $stored = self::get_stored_overrides();
        $cache  = [];

        foreach ( self::$entries as $entry ) {
            $id = $entry['id'];
            if ( isset( $stored[ $id ] ) && '' !== $stored[ $id ] ) {
                $key = self::make_key( $entry['text_domain'], $entry['msgid'] );
                $cache[ $key ] = $stored[ $id ];
            }
        }

        return $cache;
    }

    /**
     * Get stored override values from brz_options.
     *
     * @return array Associative array of entry_id => replacement_text.
     */
    private static function get_stored_overrides(): array {
        $opts = get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) || ! isset( $opts['label_overrides'] ) || ! is_array( $opts['label_overrides'] ) ) {
            return [];
        }
        return $opts['label_overrides'];
    }
}
