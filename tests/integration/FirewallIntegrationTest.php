<?php
/**
 * Integration tests for the HTTP Firewall module.
 *
 * Tests full flows: mode switching + domain management + request filtering.
 */

use PHPUnit\Framework\TestCase;

class FirewallIntegrationTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => [],
                'whitelist'   => [],
            ],
        ];
    }

    /**
     * Helper: call an AJAX handler and return the JSON response.
     */
    private function callAjax( callable $handler ): array {
        global $wp_test_json_response;
        $wp_test_json_response = null;
        try {
            $handler();
        } catch ( WP_Ajax_Response_Exception $e ) {
            return $e->response;
        }
        return $wp_test_json_response ?? [ 'success' => false, 'data' => null ];
    }

    private function addDomain( string $domain ): array {
        $_POST['domain'] = $domain;
        return $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );
    }

    private function removeDomain( string $domain ): array {
        $_POST['domain'] = $domain;
        return $this->callAjax( [ BRZ_Firewall::class, 'ajax_remove_domain' ] );
    }

    private function switchMode( string $mode ): array {
        $_POST['mode'] = $mode;
        return $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );
    }

    // ─── Full Flow: Blacklist Mode ─────────────────────────────────────────────

    public function test_blacklist_flow_add_domain_then_request_blocked(): void {
        // Step 1: Add a domain to blacklist.
        $response = $this->addDomain( 'evil.com' );
        $this->assertTrue( $response['success'] );

        // Step 2: Request to that domain should be blocked.
        $result = BRZ_Firewall::filter_request( false, [], 'https://evil.com/malware' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'brz_firewall_blocked', $result->get_error_code() );

        // Step 3: Request to a different domain should be allowed.
        $result2 = BRZ_Firewall::filter_request( false, [], 'https://good.com/api' );
        $this->assertFalse( $result2 );
    }

    public function test_blacklist_flow_add_wildcard_then_subdomain_blocked(): void {
        // Add wildcard.
        $response = $this->addDomain( '*.spam.org' );
        $this->assertTrue( $response['success'] );

        // Subdomain should be blocked.
        $result = BRZ_Firewall::filter_request( false, [], 'https://api.spam.org/data' );
        $this->assertInstanceOf( WP_Error::class, $result );

        // Bare domain should NOT be blocked (wildcard doesn't match bare).
        $result2 = BRZ_Firewall::filter_request( false, [], 'https://spam.org/data' );
        $this->assertFalse( $result2 );
    }

    // ─── Full Flow: Whitelist Mode ─────────────────────────────────────────────

    public function test_whitelist_flow_add_domain_then_unlisted_blocked(): void {
        // Step 1: Switch to whitelist mode.
        $response = $this->switchMode( 'whitelist' );
        $this->assertTrue( $response['success'] );

        // Step 2: Add allowed domain.
        $response = $this->addDomain( 'api.wordpress.org' );
        $this->assertTrue( $response['success'] );

        // Step 3: Request to allowed domain should pass.
        $result = BRZ_Firewall::filter_request( false, [], 'https://api.wordpress.org/plugins' );
        $this->assertFalse( $result );

        // Step 4: Request to unlisted domain should be blocked.
        $result2 = BRZ_Firewall::filter_request( false, [], 'https://evil.com/malware' );
        $this->assertInstanceOf( WP_Error::class, $result2 );
        $this->assertSame( 'brz_firewall_blocked', $result2->get_error_code() );
    }

    public function test_whitelist_flow_wildcard_allows_subdomains(): void {
        // Switch to whitelist.
        $this->switchMode( 'whitelist' );

        // Add wildcard.
        $this->addDomain( '*.googleapis.com' );

        // Subdomain should be allowed.
        $result = BRZ_Firewall::filter_request( false, [], 'https://fonts.googleapis.com/css' );
        $this->assertFalse( $result );

        // Bare domain should be blocked (wildcard doesn't match bare).
        $result2 = BRZ_Firewall::filter_request( false, [], 'https://googleapis.com/path' );
        $this->assertInstanceOf( WP_Error::class, $result2 );
    }

    // ─── Mode Switching Preserves Lists ────────────────────────────────────────

    public function test_mode_switching_preserves_both_lists_independently(): void {
        // Add domain to blacklist.
        $this->addDomain( 'evil.com' );

        // Switch to whitelist.
        $this->switchMode( 'whitelist' );

        // Add domain to whitelist.
        $this->addDomain( 'good.com' );

        // Switch back to blacklist.
        $this->switchMode( 'blacklist' );

        // Verify both lists preserved.
        $settings = BRZ_Firewall::get_settings();
        $this->assertContains( 'evil.com', $settings['blacklist'] );
        $this->assertContains( 'good.com', $settings['whitelist'] );
    }

    // ─── Domain Normalization in Full Flow ─────────────────────────────────────

    public function test_adding_domain_with_protocol_normalizes_and_blocks(): void {
        // Add domain with protocol noise.
        $response = $this->addDomain( 'https://EVIL.COM:443/path' );
        $this->assertTrue( $response['success'] );
        $this->assertSame( 'evil.com', $response['data']['domain'] );

        // Request should be blocked.
        $result = BRZ_Firewall::filter_request( false, [], 'http://evil.com/other-path' );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ─── Duplicate Rejection ───────────────────────────────────────────────────

    public function test_duplicate_domain_rejected_after_normalization(): void {
        // Add domain.
        $response = $this->addDomain( 'example.com' );
        $this->assertTrue( $response['success'] );

        // Try to add same domain with protocol (normalizes to same).
        $response2 = $this->addDomain( 'https://example.com' );
        $this->assertFalse( $response2['success'] );
    }

    // ─── Remove Then Unblocked ─────────────────────────────────────────────────

    public function test_remove_domain_then_request_allowed(): void {
        // Add and verify blocked.
        $this->addDomain( 'temp-block.com' );

        $result = BRZ_Firewall::filter_request( false, [], 'https://temp-block.com/path' );
        $this->assertInstanceOf( WP_Error::class, $result );

        // Remove.
        $this->removeDomain( 'temp-block.com' );

        // Now should be allowed.
        $result2 = BRZ_Firewall::filter_request( false, [], 'https://temp-block.com/path' );
        $this->assertFalse( $result2 );
    }
}
