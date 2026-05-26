<?php
/**
 * Unit tests for BRZ_Firewall_Validator.
 *
 * Tests normalization, validation, and matching methods with specific examples.
 */

use PHPUnit\Framework\TestCase;

class FirewallValidatorTest extends TestCase {

    // ─── Normalization Tests ───────────────────────────────────────────────────

    public function test_normalize_strips_http_protocol(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'http://example.com' ) );
    }

    public function test_normalize_strips_https_protocol(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'https://example.com' ) );
    }

    public function test_normalize_strips_protocol_case_insensitive(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'HTTPS://EXAMPLE.COM' ) );
    }

    public function test_normalize_strips_port(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'example.com:8080' ) );
    }

    public function test_normalize_strips_port_443(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'https://example.com:443' ) );
    }

    public function test_normalize_strips_path(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'example.com/path/to/resource' ) );
    }

    public function test_normalize_strips_trailing_slash(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'example.com/' ) );
    }

    public function test_normalize_strips_all_noise(): void {
        $this->assertSame( 'api.example.com', BRZ_Firewall_Validator::normalize( 'https://API.EXAMPLE.COM:9090/v1/data/' ) );
    }

    public function test_normalize_lowercases(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'EXAMPLE.COM' ) );
    }

    public function test_normalize_preserves_wildcard(): void {
        $this->assertSame( '*.example.com', BRZ_Firewall_Validator::normalize( 'https://*.example.com:443/path' ) );
    }

    public function test_normalize_empty_string(): void {
        $this->assertSame( '', BRZ_Firewall_Validator::normalize( '' ) );
    }

    public function test_normalize_whitespace_only(): void {
        $this->assertSame( '', BRZ_Firewall_Validator::normalize( '   ' ) );
    }

    public function test_normalize_strips_trailing_dots(): void {
        $this->assertSame( 'example.com', BRZ_Firewall_Validator::normalize( 'example.com.' ) );
    }

    // ─── Validation Tests ──────────────────────────────────────────────────────

    public function test_validate_accepts_bare_domain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::validate( 'example.com' ) );
    }

    public function test_validate_accepts_subdomain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::validate( 'api.example.com' ) );
    }

    public function test_validate_accepts_wildcard(): void {
        $this->assertTrue( BRZ_Firewall_Validator::validate( '*.example.com' ) );
    }

    public function test_validate_accepts_localhost(): void {
        $this->assertTrue( BRZ_Firewall_Validator::validate( 'localhost' ) );
    }

    public function test_validate_accepts_hyphenated_domain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::validate( 'my-site.example.com' ) );
    }

    public function test_validate_rejects_empty_string(): void {
        $result = BRZ_Firewall_Validator::validate( '' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_spaces(): void {
        $result = BRZ_Firewall_Validator::validate( 'example .com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_special_characters(): void {
        $result = BRZ_Firewall_Validator::validate( 'example@.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_underscore(): void {
        $result = BRZ_Firewall_Validator::validate( 'my_domain.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_wildcard_in_middle(): void {
        $result = BRZ_Firewall_Validator::validate( 'example.*.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_wildcard_without_dot(): void {
        $result = BRZ_Firewall_Validator::validate( '*example.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_double_wildcard(): void {
        $result = BRZ_Firewall_Validator::validate( '*.*.example.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_no_dot_non_localhost(): void {
        $result = BRZ_Firewall_Validator::validate( 'examplecom' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_double_dot(): void {
        $result = BRZ_Firewall_Validator::validate( 'example..com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_validate_rejects_label_too_long(): void {
        $long_label = str_repeat( 'a', 64 );
        $result = BRZ_Firewall_Validator::validate( $long_label . '.com' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ─── Matching Tests ────────────────────────────────────────────────────────

    public function test_matches_exact_domain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::matches( 'example.com', 'example.com' ) );
    }

    public function test_matches_exact_domain_case_insensitive(): void {
        $this->assertTrue( BRZ_Firewall_Validator::matches( 'EXAMPLE.COM', 'example.com' ) );
    }

    public function test_matches_wildcard_subdomain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::matches( 'api.example.com', '*.example.com' ) );
    }

    public function test_matches_wildcard_deep_subdomain(): void {
        $this->assertTrue( BRZ_Firewall_Validator::matches( 'cdn.api.example.com', '*.example.com' ) );
    }

    public function test_matches_wildcard_does_not_match_bare_domain(): void {
        $this->assertFalse( BRZ_Firewall_Validator::matches( 'example.com', '*.example.com' ) );
    }

    public function test_matches_different_domains_do_not_match(): void {
        $this->assertFalse( BRZ_Firewall_Validator::matches( 'other.com', 'example.com' ) );
    }

    public function test_matches_empty_host_returns_false(): void {
        $this->assertFalse( BRZ_Firewall_Validator::matches( '', 'example.com' ) );
    }

    public function test_matches_empty_entry_returns_false(): void {
        $this->assertFalse( BRZ_Firewall_Validator::matches( 'example.com', '' ) );
    }

    public function test_matches_wildcard_case_insensitive(): void {
        $this->assertTrue( BRZ_Firewall_Validator::matches( 'API.EXAMPLE.COM', '*.example.com' ) );
    }

    public function test_matches_partial_suffix_does_not_match(): void {
        $this->assertFalse( BRZ_Firewall_Validator::matches( 'notexample.com', '*.example.com' ) );
    }
}
