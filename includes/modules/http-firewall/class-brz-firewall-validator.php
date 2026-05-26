<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Domain validation and normalization logic for the HTTP Firewall module.
 * Pure static utility — separated for testability.
 */
class BRZ_Firewall_Validator {

    /**
     * Normalize a raw domain input: strip protocol, port, path, trailing slashes, lowercase.
     *
     * @param string $input Raw domain string from user input.
     * @return string Normalized domain or empty string if input is empty.
     */
    public static function normalize( string $input ): string {
        $input = trim( $input );
        if ( '' === $input ) {
            return '';
        }

        // Strip protocol prefix.
        $input = preg_replace( '#^https?://#i', '', $input );

        // Strip path segments (everything after first /).
        $slash_pos = strpos( $input, '/' );
        if ( false !== $slash_pos ) {
            $input = substr( $input, 0, $slash_pos );
        }

        // Strip port number.
        // Handle wildcard domains like *.example.com:8080
        $input = preg_replace( '#:\d+$#', '', $input );

        // Strip trailing dots.
        $input = rtrim( $input, '.' );

        // Lowercase.
        $input = strtolower( $input );

        return $input;
    }

    /**
     * Validate a normalized domain string.
     *
     * @param string $domain Normalized domain to validate.
     * @return true|\WP_Error True if valid, WP_Error with message if invalid.
     */
    public static function validate( string $domain ) {
        if ( '' === $domain ) {
            return new \WP_Error( 'brz_firewall_invalid', 'دامنه نمی‌تواند خالی باشد' );
        }

        if ( preg_match( '/\s/', $domain ) ) {
            return new \WP_Error( 'brz_firewall_invalid', 'دامنه نباید شامل فاصله باشد' );
        }

        // Check for wildcard format.
        if ( strpos( $domain, '*' ) !== false ) {
            // Wildcard must be exactly at the start as *.
            if ( ! preg_match( '/^\*\./', $domain ) ) {
                return new \WP_Error( 'brz_firewall_invalid', 'علامت * فقط به‌صورت *. در ابتدای دامنه مجاز است' );
            }
            // Only one asterisk allowed.
            if ( substr_count( $domain, '*' ) > 1 ) {
                return new \WP_Error( 'brz_firewall_invalid', 'فقط یک علامت * در ابتدای دامنه مجاز است' );
            }
            // Validate the rest after *.
            $rest = substr( $domain, 2 );
            return self::validate_domain_part( $rest );
        }

        return self::validate_domain_part( $domain );
    }

    /**
     * Validate a domain part (without wildcard prefix).
     *
     * @param string $domain Domain string to validate.
     * @return true|\WP_Error
     */
    private static function validate_domain_part( string $domain ) {
        // Allowed characters: a-z, 0-9, hyphen, dot.
        if ( ! preg_match( '/^[a-z0-9\-.]+$/', $domain ) ) {
            return new \WP_Error( 'brz_firewall_invalid', 'دامنه شامل کاراکترهای غیرمجاز است' );
        }

        // Must have at least one dot (except localhost).
        if ( 'localhost' !== $domain && strpos( $domain, '.' ) === false ) {
            return new \WP_Error( 'brz_firewall_invalid', 'دامنه باید حداقل یک نقطه داشته باشد' );
        }

        // Check label lengths (each part between dots must be 1-63 chars).
        $labels = explode( '.', $domain );
        foreach ( $labels as $label ) {
            if ( '' === $label ) {
                return new \WP_Error( 'brz_firewall_invalid', 'دامنه شامل برچسب خالی است' );
            }
            if ( strlen( $label ) > 63 ) {
                return new \WP_Error( 'brz_firewall_invalid', 'هر بخش دامنه حداکثر ۶۳ کاراکتر می‌تواند باشد' );
            }
        }

        return true;
    }

    /**
     * Check if a host matches a domain entry (supports wildcards).
     * Case-insensitive. Wildcard *.example.com matches subdomains only, not example.com itself.
     *
     * @param string $host    The request host to check.
     * @param string $domain_entry The domain entry from the list.
     * @return bool
     */
    public static function matches( string $host, string $domain_entry ): bool {
        $host  = strtolower( trim( $host ) );
        $entry = strtolower( trim( $domain_entry ) );

        if ( '' === $host || '' === $entry ) {
            return false;
        }

        if ( strpos( $entry, '*.' ) === 0 ) {
            // Wildcard match: *.example.com matches sub.example.com but NOT example.com.
            $suffix = substr( $entry, 2 ); // Remove "*."
            return str_ends_with( $host, '.' . $suffix );
        }

        // Exact match.
        return $host === $entry;
    }
}
