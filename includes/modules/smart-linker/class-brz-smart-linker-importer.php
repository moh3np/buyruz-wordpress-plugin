<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Smart Linker Importer - Parse and validate AI response.
 *
 * Handles JSON import from AI and stores in pending_links table.
 */
class BRZ_Smart_Linker_Importer {

    /**
     * Import links from AI JSON response.
     *
     * @param string $json_string Raw JSON string from user input
     * @return array|WP_Error Result with counts or error
     */
    public static function import_from_json( $json_string ) {
        // Clean up JSON (remove markdown code blocks if present)
        $json_string = trim( $json_string );
        $json_string = preg_replace( '/^```json\s*/i', '', $json_string );
        $json_string = preg_replace( '/\s*```$/i', '', $json_string );

        // Parse JSON
        $links = json_decode( $json_string, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_parse_error', 'خطا در پارس JSON: ' . json_last_error_msg() );
        }

        if ( ! is_array( $links ) ) {
            return new WP_Error( 'invalid_format', 'فرمت JSON نامعتبر است. باید یک آرایه باشد.' );
        }

        // Validate each link
        $valid_links = array();
        $errors = array();

        foreach ( $links as $index => $link ) {
            $validation = self::validate_link( $link, $index );

            if ( is_wp_error( $validation ) ) {
                $errors[] = $validation->get_error_message();
                continue;
            }

            $valid_links[] = $validation;
        }

        if ( empty( $valid_links ) ) {
            return new WP_Error( 'no_valid_links', 'هیچ لینک معتبری یافت نشد.', array( 'errors' => $errors ) );
        }

        // Generate batch ID
        $batch_id = 'import_' . current_time( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );

        // Insert into database
        $inserted = BRZ_Smart_Linker_DB::insert_pending_links( $valid_links, $batch_id );

        return array(
            'success'  => true,
            'imported' => $inserted,
            'total'    => count( $links ),
            'valid'    => count( $valid_links ),
            'errors'   => $errors,
            'batch_id' => $batch_id,
        );
    }

    /**
     * Validate a single link item.
     *
     * @param array $link
     * @param int   $index
     * @return array|WP_Error
     */
    private static function validate_link( $link, $index ) {
        $required_fields = array( 'source_id', 'keyword', 'target_id', 'target_url' );

        foreach ( $required_fields as $field ) {
            if ( empty( $link[ $field ] ) ) {
                return new WP_Error( 
                    'missing_field', 
                    sprintf( 'لینک %d: فیلد %s الزامی است.', $index + 1, $field ) 
                );
            }
        }

        // Validate URL format
        if ( ! filter_var( $link['target_url'], FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 
                'invalid_url', 
                sprintf( 'لینک %d: URL نامعتبر: %s', $index + 1, $link['target_url'] ) 
            );
        }

        // Validate priority
        $allowed_priorities = array( 'high', 'medium', 'low' );
        $priority = isset( $link['priority'] ) ? strtolower( $link['priority'] ) : 'medium';
        if ( ! in_array( $priority, $allowed_priorities, true ) ) {
            $priority = 'medium';
        }

        // Return validated link
        return array(
            'source_site'  => isset( $link['source_site'] ) ? $link['source_site'] : 'local',
            'source_id'    => absint( $link['source_id'] ),
            'source_type'  => isset( $link['source_type'] ) ? $link['source_type'] : 'post',
            'keyword'      => sanitize_text_field( $link['keyword'] ),
            'target_site'  => isset( $link['target_site'] ) ? $link['target_site'] : 'local',
            'target_id'    => absint( $link['target_id'] ),
            'target_url'   => esc_url_raw( $link['target_url'] ),
            'priority'     => $priority,
            'reason'       => isset( $link['reason'] ) ? sanitize_text_field( $link['reason'] ) : '',
        );
    }

    /**
     * Get pending links with source content preview.
     *
     * @param string $status
     * @param int    $limit
     * @return array
     */
    public static function get_links_with_preview( $status = 'pending', $limit = 50 ) {
        $links = BRZ_Smart_Linker_DB::get_pending_links( $status, $limit );

        foreach ( $links as &$link ) {
            // Get source content preview
            $post = get_post( $link['source_id'] );
            $link['source_title'] = $post ? get_the_title( $post ) : '(حذف شده)';
            $link['source_url'] = $post ? get_permalink( $post ) : '';
            $link['source_edit_url'] = $post ? get_edit_post_link( $post->ID, 'raw' ) : '';

            // Find keyword context in content
            if ( $post && ! empty( $link['keyword'] ) ) {
                $content = wp_strip_all_tags( $post->post_content ?? '' );
                $pos = mb_stripos( $content, $link['keyword'], 0, 'UTF-8' );

                if ( false !== $pos ) {
                    $start = max( 0, $pos - 50 );
                    $length = mb_strlen( $link['keyword'], 'UTF-8' ) + 100;
                    $context = mb_substr( $content, $start, $length, 'UTF-8' );

                    // Highlight keyword
                    $link['context'] = preg_replace(
                        '/(' . preg_quote( $link['keyword'], '/' ) . ')/iu',
                        '<mark>$1</mark>',
                        esc_html( $context )
                    );

                    if ( $start > 0 ) {
                        $link['context'] = '...' . $link['context'];
                    }
                    $link['context'] .= '...';
                } else {
                    $link['context'] = '<em>(کلمه کلیدی در محتوا یافت نشد)</em>';
                }
            } else {
                $link['context'] = '';
            }

            // Get target info
            $link['target_title'] = $link['target_url']; // Fallback
            
            // Try to get local target title
            if ( 'local' === $link['target_site'] ) {
                $target_post = get_post( $link['target_id'] );
                if ( $target_post ) {
                    $link['target_title'] = get_the_title( $target_post );
                }
            }
        }

        return $links;
    }

    /**
     * AJAX handler for import.
     */
    public static function ajax_import() {
        check_ajax_referer( 'brz_smart_linker_import' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $json_input = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';

        if ( empty( $json_input ) ) {
            wp_send_json_error( array( 'message' => 'JSON خالی است' ) );
        }

        $result = self::import_from_json( $json_input );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'errors'  => $result->get_error_data(),
            ) );
        }

        wp_send_json_success( array(
            'message'  => sprintf( 
                '%d لینک از %d لینک با موفقیت وارد شد.', 
                $result['imported'], 
                $result['total'] 
            ),
            'imported' => $result['imported'],
            'batch_id' => $result['batch_id'],
            'errors'   => $result['errors'],
        ) );
    }

    /**
     * AJAX handler for approving/rejecting links.
     */
    public static function ajax_update_status() {
        check_ajax_referer( 'brz_smart_linker_review' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';

        if ( empty( $ids ) || ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
            wp_send_json_error( array( 'message' => 'پارامترهای نامعتبر' ) );
        }

        $updated = BRZ_Smart_Linker_DB::update_pending_status( $ids, $status );

        wp_send_json_success( array(
            'message' => sprintf( '%d لینک به‌روزرسانی شد.', $updated ),
            'updated' => $updated,
        ) );
    }

    /**
     * AJAX handler for applying approved links.
     */
    public static function ajax_apply_links() {
        check_ajax_referer( 'brz_smart_linker_apply' );

        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $links = BRZ_Smart_Linker_DB::get_pending_links( 'approved', 100 );

        if ( empty( $links ) ) {
            wp_send_json_error( array( 'message' => 'هیچ لینک تأیید‌شده‌ای وجود ندارد.' ) );
        }

        $settings = BRZ_Smart_Linker::get_settings();
        $applied_count = 0;
        $applied_ids = array();

        // Group links by source_id
        $grouped = array();
        foreach ( $links as $link ) {
            $key = $link['source_site'] . '_' . $link['source_id'];
            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = array();
            }
            $grouped[ $key ][] = $link;
        }

        // Apply links for each source
        foreach ( $grouped as $key => $source_links ) {
            $parts = explode( '_', $key );
            $site = $parts[0];
            $post_id = (int) $parts[1];

            if ( 'local' !== $site ) {
                // Remote application - would need to call peer API
                // For now, skip remote links
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            // Convert to injector format
            $inject_links = array();
            foreach ( $source_links as $sl ) {
                $inject_links[] = array(
                    'keyword'    => $sl['keyword'],
                    'target_url' => $sl['target_url'],
                    'post_id'    => $post_id,
                );
            }

            $injector = new BRZ_Smart_Linker_Link_Injector(
                $post_id,
                $post->post_content,
                $post->post_type,
                $settings
            );

            $result = $injector->inject( $inject_links );

            if ( $result['changed'] ) {
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $result['content'],
                ) );

                foreach ( $source_links as $sl ) {
                    $applied_ids[] = (int) $sl['id'];
                }
                $applied_count += count( $source_links );
            }
        }

        // Mark as applied
        if ( ! empty( $applied_ids ) ) {
            BRZ_Smart_Linker_DB::update_pending_status( $applied_ids, 'applied' );
        }

        wp_send_json_success( array(
            'message' => sprintf( '%d لینک با موفقیت اعمال شد.', $applied_count ),
            'applied' => $applied_count,
        ) );
    }
}
