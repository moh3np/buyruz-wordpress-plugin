<?php
/**
 * Property 6: Domain list add/remove correctness and mode isolation.
 *
 * For any valid domain and any initial state:
 * - After adding a domain to the active list, that domain appears in the list.
 * - After removing a domain, that domain no longer appears in the list.
 * - Operations on one mode's list do not affect the other mode's list.
 *
 * **Validates: Requirements 2.2, 2.3, 2.6**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ListOpsPropertyTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
        // Set up default state.
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
     * Simulate adding a domain via the AJAX handler.
     */
    private function addDomain( string $domain ): array {
        global $wp_test_json_response;
        $wp_test_json_response = null;
        $_POST['domain'] = $domain;
        try {
            BRZ_Firewall::ajax_add_domain();
        } catch ( WP_Ajax_Response_Exception $e ) {
            return $e->response;
        }
        return $wp_test_json_response ?? [ 'success' => false, 'data' => null ];
    }

    /**
     * Simulate removing a domain via the AJAX handler.
     */
    private function removeDomain( string $domain ): array {
        global $wp_test_json_response;
        $wp_test_json_response = null;
        $_POST['domain'] = $domain;
        try {
            BRZ_Firewall::ajax_remove_domain();
        } catch ( WP_Ajax_Response_Exception $e ) {
            return $e->response;
        }
        return $wp_test_json_response ?? [ 'success' => false, 'data' => null ];
    }

    /**
     * Simulate switching mode via the AJAX handler.
     */
    private function switchMode( string $mode ): array {
        global $wp_test_json_response;
        $wp_test_json_response = null;
        $_POST['mode'] = $mode;
        try {
            BRZ_Firewall::ajax_switch_mode();
        } catch ( WP_Ajax_Response_Exception $e ) {
            return $e->response;
        }
        return $wp_test_json_response ?? [ 'success' => false, 'data' => null ];
    }

    /**
     * Domains to test add/remove operations.
     */
    public static function domainProvider(): array {
        return [
            'bare_domain'      => [ 'example.com' ],
            'subdomain'        => [ 'api.example.com' ],
            'wildcard'         => [ '*.example.com' ],
            'hyphenated'       => [ 'my-site.org' ],
            'deep_subdomain'   => [ 'a.b.c.example.net' ],
            'short_domain'     => [ 'x.io' ],
        ];
    }

    #[DataProvider('domainProvider')]
    public function test_add_domain_makes_it_appear_in_list( string $domain ): void {
        $response = $this->addDomain( $domain );

        $this->assertTrue( $response['success'], "Adding '$domain' should succeed" );
        $this->assertContains( $domain, $response['data']['domains'], "Domain '$domain' should be in the list after adding" );

        // Also verify via get_settings.
        $settings = BRZ_Firewall::get_settings();
        $this->assertContains( $domain, $settings['blacklist'] );
    }

    #[DataProvider('domainProvider')]
    public function test_remove_domain_makes_it_disappear_from_list( string $domain ): void {
        // First add it.
        $this->addDomain( $domain );

        // Then remove it.
        $response = $this->removeDomain( $domain );

        $this->assertTrue( $response['success'], "Removing '$domain' should succeed" );
        $this->assertNotContains( $domain, $response['data']['domains'], "Domain '$domain' should not be in the list after removing" );

        // Also verify via get_settings.
        $settings = BRZ_Firewall::get_settings();
        $this->assertNotContains( $domain, $settings['blacklist'] );
    }

    /**
     * Property: adding a domain to blacklist does not affect whitelist.
     */
    public static function modeIsolationProvider(): array {
        return [
            'add_to_blacklist' => [ 'blacklist', 'evil.com' ],
            'add_wildcard_to_blacklist' => [ 'blacklist', '*.spam.org' ],
        ];
    }

    #[DataProvider('modeIsolationProvider')]
    public function test_operations_on_one_mode_do_not_affect_other( string $mode, string $domain ): void {
        // Set up initial state with some domains in both lists.
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => $mode,
                'blacklist'   => [ 'existing-black.com' ],
                'whitelist'   => [ 'existing-white.com' ],
            ],
        ];

        $other_mode = ( $mode === 'blacklist' ) ? 'whitelist' : 'blacklist';
        $settings_before = BRZ_Firewall::get_settings();
        $other_list_before = $settings_before[ $other_mode ];

        // Add domain to active mode.
        $this->addDomain( $domain );

        $settings_after = BRZ_Firewall::get_settings();
        $other_list_after = $settings_after[ $other_mode ];

        $this->assertSame(
            $other_list_before,
            $other_list_after,
            "Adding to '$mode' should not affect '$other_mode' list"
        );
    }

    /**
     * Property: switching mode preserves both lists independently.
     */
    public function test_mode_switch_preserves_both_lists(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => [ 'evil.com', '*.spam.org' ],
                'whitelist'   => [ 'good.com', '*.trusted.io' ],
            ],
        ];

        $before = BRZ_Firewall::get_settings();

        // Switch to whitelist.
        $this->switchMode( 'whitelist' );

        $after = BRZ_Firewall::get_settings();

        $this->assertSame( 'whitelist', $after['active_mode'] );
        $this->assertSame( $before['blacklist'], $after['blacklist'], 'Blacklist should be preserved after mode switch' );
        $this->assertSame( $before['whitelist'], $after['whitelist'], 'Whitelist should be preserved after mode switch' );
    }

    /**
     * Property: adding duplicate domain is rejected.
     */
    public function test_duplicate_domain_is_rejected(): void {
        $this->addDomain( 'example.com' );
        $response = $this->addDomain( 'example.com' );

        $this->assertFalse( $response['success'], 'Duplicate domain should be rejected' );
    }

    /**
     * Property: after add then remove, list returns to original state.
     */
    public function test_add_then_remove_returns_to_original(): void {
        $settings_before = BRZ_Firewall::get_settings();

        $this->addDomain( 'temp.com' );
        $this->removeDomain( 'temp.com' );

        $settings_after = BRZ_Firewall::get_settings();

        $this->assertSame(
            $settings_before['blacklist'],
            $settings_after['blacklist'],
            'Add then remove should return list to original state'
        );
    }

    /**
     * Property: multiple adds create a list with all domains.
     */
    public function test_multiple_adds_accumulate(): void {
        $domains = [ 'a.com', 'b.org', 'c.net', '*.d.io' ];

        foreach ( $domains as $domain ) {
            $this->addDomain( $domain );
        }

        $settings = BRZ_Firewall::get_settings();

        foreach ( $domains as $domain ) {
            $this->assertContains( $domain, $settings['blacklist'], "Domain '$domain' should be in list" );
        }
    }
}
