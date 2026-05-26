<?php
/**
 * Unit tests for BRZ_Firewall filter and settings logic.
 */

use PHPUnit\Framework\TestCase;

class FirewallFilterTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
    }

    // ─── filter_request in Blacklist Mode ──────────────────────────────────────

    public function test_filter_blocks_matching_domain_in_blacklist_mode(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => [ 'evil.com', '*.spam.org' ],
                'whitelist'   => [],
            ],
        ];

        $result = BRZ_Firewall::filter_request( false, [], 'https://evil.com/path' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'brz_firewall_blocked', $result->get_error_code() );
        $this->assertStringContainsString( 'evil.com', $result->get_error_message() );
    }

    public function test_filter_allows_non_matching_domain_in_blacklist_mode(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => [ 'evil.com' ],
                'whitelist'   => [],
            ],
        ];

        $result = BRZ_Firewall::filter_request( false, [], 'https://good.com/path' );

        $this->assertFalse( $result );
    }

    // ─── filter_request in Whitelist Mode ──────────────────────────────────────

    public function test_filter_blocks_non_matching_domain_in_whitelist_mode(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'whitelist',
                'blacklist'   => [],
                'whitelist'   => [ 'allowed.com' ],
            ],
        ];

        $result = BRZ_Firewall::filter_request( false, [], 'https://blocked.com/path' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'brz_firewall_blocked', $result->get_error_code() );
        $this->assertStringContainsString( 'blocked.com', $result->get_error_message() );
    }

    public function test_filter_allows_matching_domain_in_whitelist_mode(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'whitelist',
                'blacklist'   => [],
                'whitelist'   => [ 'allowed.com', '*.trusted.io' ],
            ],
        ];

        $result = BRZ_Firewall::filter_request( false, [], 'https://allowed.com/api' );
        $this->assertFalse( $result );

        $result2 = BRZ_Firewall::filter_request( false, [], 'https://api.trusted.io/data' );
        $this->assertFalse( $result2 );
    }

    // ─── get_settings() defaults ───────────────────────────────────────────────

    public function test_get_settings_returns_defaults_when_no_options(): void {
        // No brz_options set at all.
        $settings = BRZ_Firewall::get_settings();

        $this->assertSame( 'blacklist', $settings['active_mode'] );
        $this->assertSame( [], $settings['blacklist'] );
        $this->assertSame( [], $settings['whitelist'] );
    }

    public function test_get_settings_returns_defaults_when_firewall_key_missing(): void {
        global $wp_options;
        $wp_options['brz_options'] = [ 'modules' => [] ];

        $settings = BRZ_Firewall::get_settings();

        $this->assertSame( 'blacklist', $settings['active_mode'] );
        $this->assertSame( [], $settings['blacklist'] );
        $this->assertSame( [], $settings['whitelist'] );
    }

    public function test_get_settings_returns_defaults_when_data_corrupted(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => 'not-an-array',
        ];

        $settings = BRZ_Firewall::get_settings();

        $this->assertSame( 'blacklist', $settings['active_mode'] );
        $this->assertSame( [], $settings['blacklist'] );
        $this->assertSame( [], $settings['whitelist'] );
    }

    // ─── Short-circuit behavior ────────────────────────────────────────────────

    public function test_filter_respects_previous_short_circuit(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => [ 'evil.com' ],
                'whitelist'   => [],
            ],
        ];

        $pre = new WP_Error( 'other', 'Already handled' );
        $result = BRZ_Firewall::filter_request( $pre, [], 'https://evil.com/path' );

        $this->assertSame( $pre, $result );
    }

    public function test_filter_allows_when_url_has_no_host(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'whitelist',
                'blacklist'   => [],
                'whitelist'   => [ 'allowed.com' ],
            ],
        ];

        // Relative path — no host.
        $result = BRZ_Firewall::filter_request( false, [], '/relative/path' );

        $this->assertFalse( $result, 'Should fail open when host cannot be determined' );
    }

    // ─── Hook registration ─────────────────────────────────────────────────────

    public function test_init_registers_pre_http_request_filter_at_priority_5(): void {
        global $wp_test_filters;
        $wp_test_filters = [];

        BRZ_Firewall::init();

        $this->assertArrayHasKey( 'pre_http_request', $wp_test_filters );

        $found = false;
        foreach ( $wp_test_filters['pre_http_request'] as $filter ) {
            if ( $filter['priority'] === 5 ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'pre_http_request filter should be registered at priority 5' );
    }
}
