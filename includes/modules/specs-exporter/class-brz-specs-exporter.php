<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * BRZ_Specs_Exporter Module
 * Exports meta features, WooCommerce attributes, and WooCommerce attribute terms as a single JSON.
 */
class BRZ_Specs_Exporter {

    public static function init(): void {
        // No specific runtime hooks required, this is primarily an admin configuration exporter.
    }

    /**
     * Get the complete JSON payload of specifications and attributes.
     */
    public static function get_export_data(): array {
        // 1. Fetch meta features
        $meta_features = get_option( 'brz_product_specs_fields', array() );
        if ( ! is_array( $meta_features ) ) {
            $meta_features = array();
        }

        // Clean fields formatting
        $cleaned_meta = array();
        foreach ( $meta_features as $field ) {
            if ( empty( $field['key'] ) ) {
                continue;
            }
            $cleaned_meta[] = array(
                'key'     => $field['key'],
                'label'   => isset( $field['label'] ) ? $field['label'] : '',
                'type'    => isset( $field['type'] ) ? $field['type'] : 'boolean',
                'prefix'  => isset( $field['prefix'] ) ? $field['prefix'] : '',
                'suffix'  => isset( $field['suffix'] ) ? $field['suffix'] : '',
                'options' => isset( $field['options'] ) ? $field['options'] : '',
            );
        }

        // 2. Fetch WooCommerce attributes
        $attributes = array();
        $attribute_terms = array();

        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $taxonomies = wc_get_attribute_taxonomies();
            if ( is_array( $taxonomies ) ) {
                foreach ( $taxonomies as $tax ) {
                    $attributes[] = array(
                        'id'   => (int) $tax->attribute_id,
                        'name' => $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name,
                        'slug' => $tax->attribute_name,
                        'type' => $tax->attribute_type,
                    );

                    // Fetch terms for this taxonomy
                    $taxonomy = 'pa_' . $tax->attribute_name;
                    $terms = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    ) );

                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        foreach ( $terms as $term ) {
                            $attribute_terms[] = array(
                                'attribute_id' => (int) $tax->attribute_id,
                                'id'           => (int) $term->term_id,
                                'name'         => $term->name,
                                'slug'         => $term->slug,
                            );
                        }
                    }
                }
            }
        }

        return array(
            'meta_features'   => $cleaned_meta,
            'attributes'      => $attributes,
            'attribute_terms' => $attribute_terms,
        );
    }

    /**
     * Render the admin page for the exporter module.
     */
    public static function render_admin_page(): void {
        $export_data = self::get_export_data();
        $json_payload = wp_json_encode( $export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        
        $meta_count  = count( $export_data['meta_features'] );
        $attr_count  = count( $export_data['attributes'] );
        $terms_count = count( $export_data['attribute_terms'] );
        ?>
        <style>
            .brz-export-container {
                max-width: 100%;
                margin-top: 15px;
            }
            .brz-export-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            .brz-export-stat-card {
                background: var(--md-surface, #fff);
                border: 1px solid var(--md-outline-variant, #e0e0e0);
                border-radius: 8px;
                padding: 15px;
                text-align: center;
            }
            .brz-export-stat-card h4 {
                margin: 0 0 8px 0;
                font-size: 14px;
                color: var(--md-on-surface-variant, #666);
            }
            .brz-export-stat-card .brz-stat-value {
                font-size: 24px;
                font-weight: bold;
                color: var(--brz-brand, #1a73e8);
            }
            .brz-export-textarea {
                width: 100%;
                height: 380px;
                font-family: monospace;
                font-size: 13px;
                direction: ltr;
                text-align: left;
                padding: 12px;
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 8px;
                background-color: #fafafa;
                resize: vertical;
                box-sizing: border-box;
            }
            .brz-export-textarea:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-export-actions {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }
        </style>
        
        <div class="brz-export-container" dir="rtl">
            <div class="brz-export-stats">
                <div class="brz-export-stat-card">
                    <h4>ویژگی‌های متایی (بایروز)</h4>
                    <div class="brz-stat-value"><?php echo esc_html( $meta_count ); ?></div>
                </div>
                <div class="brz-export-stat-card">
                    <h4>مشخصه‌های ووکامرس</h4>
                    <div class="brz-stat-value"><?php echo esc_html( $attr_count ); ?></div>
                </div>
                <div class="brz-export-stat-card">
                    <h4>گزینه‌های مشخصه‌ها (ترم‌ها)</h4>
                    <div class="brz-stat-value"><?php echo esc_html( $terms_count ); ?></div>
                </div>
            </div>

            <div class="brz-card">
                <div class="brz-card__header">
                    <h3>خروجی JSON ساختار ویژگی‌ها</h3>
                    <p>کدهای خروجی زیر را کپی کرده و در پل ارتباطی گوگل شیت (بخش جایگزینی ساختار) پیست کنید تا تغییرات در شیت اعمال شود.</p>
                </div>
                <div class="brz-card__body">
                    <textarea id="brz-export-payload" class="brz-export-textarea" readonly><?php echo esc_textarea( $json_payload ); ?></textarea>
                    
                    <div class="brz-export-actions">
                        <button type="button" id="brz-btn-copy-payload" class="brz-button brz-button--primary">📋 کپی کدهای خروجی (JSON)</button>
                        <button type="button" id="brz-btn-download-payload" class="brz-button brz-button--outlined">💾 دانلود فایل JSON</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function showSnackbar(message) {
                var $snackbar = $('#brz-snackbar');
                if (!$snackbar.length) {
                    $snackbar = $('<div id="brz-snackbar" class="brz-snackbar" aria-live="polite"></div>');
                    $('body').append($snackbar);
                }
                $snackbar.text(message).addClass('show');
                setTimeout(function() {
                    $snackbar.removeClass('show');
                }, 3000);
            }

            $('#brz-btn-copy-payload').on('click', function() {
                var $textarea = $('#brz-export-payload');
                $textarea.select();
                
                try {
                    var successful = document.execCommand('copy');
                    if (successful) {
                        showSnackbar('کدهای خروجی با موفقیت کپی شدند.');
                    } else {
                        fallbackCopyText($textarea.val());
                    }
                } catch (err) {
                    fallbackCopyText($textarea.val());
                }
            });

            function fallbackCopyText(text) {
                navigator.clipboard.writeText(text).then(function() {
                    showSnackbar('کدهای خروجی با موفقیت کپی شدند.');
                }, function() {
                    showSnackbar('خطا در کپی خودکار. لطفا متن را دستی انتخاب و کپی کنید.');
                });
            }

            $('#brz-btn-download-payload').on('click', function() {
                var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent($('#brz-export-payload').val());
                var downloadAnchor = document.createElement('a');
                downloadAnchor.setAttribute("href",     dataStr);
                downloadAnchor.setAttribute("download", "buyruz-structure-export.json");
                document.body.appendChild(downloadAnchor);
                downloadAnchor.click();
                downloadAnchor.remove();
                showSnackbar('دانلود فایل JSON آغاز شد.');
            });
        });
        </script>
        <?php
    }
}
