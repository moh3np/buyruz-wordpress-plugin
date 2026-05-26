<?php
/**
 * Unit tests for BRZ_Firewall AJAX handlers.
 */

use PHPUnit\Framework\TestCase;

class FirewallAjaxTest extends TestCase {

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

    // ─── Mode Switch Tests ─────────────────────────────────────────────────────

    public function test_switch_mode_to_whitelist(): void {
        $_POST['mode'] = 'whitelist';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'whitelist', $response['data']['mode'] );
    }

    public function test_switch_mode_to_blacklist(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['active_mode'] = 'whitelist';
        $_POST['mode'] = 'blacklist';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'blacklist', $response['data']['mode'] );
    }

    public function test_switch_mode_rejects_invalid_mode(): void {
        $_POST['mode'] = 'greylist';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'نامعتبر', $response['data']['message'] );
    }

    public function test_switch_mode_rejects_empty_mode(): void {
        $_POST['mode'] = '';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $this->assertFalse( $response['success'] );
    }

    public function test_switch_mode_persists_to_database(): void {
        global $wp_options;
        $_POST['mode'] = 'whitelist';
        $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $settings = $wp_options['brz_options']['firewall'];
        $this->assertSame( 'whitelist', $settings['active_mode'] );
    }

    // ─── Add Domain Tests ──────────────────────────────────────────────────────

    public function test_add_domain_normalizes_input(): void {
        $_POST['domain'] = 'HTTP://EXAMPLE.COM:8080/path';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertTrue( $response['success'] );
        $this->assertSame( 'example.com', $response['data']['domain'] );
    }

    public function test_add_domain_validates_input(): void {
        $_POST['domain'] = 'invalid domain with spaces';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertFalse( $response['success'] );
        $this->assertArrayHasKey( 'message', $response['data'] );
    }

    public function test_add_domain_rejects_empty(): void {
        $_POST['domain'] = '';
        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'خالی', $response['data']['message'] );
    }

    public function test_add_domain_rejects_duplicate(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['blacklist'] = [ 'example.com' ];
        $_POST['domain'] = 'example.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'قبلاً', $response['data']['message'] );
    }

    public function test_add_domain_persists_to_list(): void {
        global $wp_options;
        $_POST['domain'] = 'new-domain.com';
        $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $settings = $wp_options['brz_options']['firewall'];
        $this->assertContains( 'new-domain.com', $settings['blacklist'] );
    }

    public function test_add_domain_returns_updated_list(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['blacklist'] = [ 'existing.com' ];
        $_POST['domain'] = 'new.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertTrue( $response['success'] );
        $this->assertContains( 'existing.com', $response['data']['domains'] );
        $this->assertContains( 'new.com', $response['data']['domains'] );
    }

    // ─── Remove Domain Tests ───────────────────────────────────────────────────

    public function test_remove_domain_removes_from_list(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['blacklist'] = [ 'evil.com', 'spam.org' ];
        $_POST['domain'] = 'evil.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_remove_domain' ] );

        $this->assertTrue( $response['success'] );
        $this->assertNotContains( 'evil.com', $response['data']['domains'] );
        $this->assertContains( 'spam.org', $response['data']['domains'] );
    }

    public function test_remove_domain_persists_change(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['blacklist'] = [ 'evil.com' ];
        $_POST['domain'] = 'evil.com';

        $this->callAjax( [ BRZ_Firewall::class, 'ajax_remove_domain' ] );

        $settings = $wp_options['brz_options']['firewall'];
        $this->assertNotContains( 'evil.com', $settings['blacklist'] );
    }

    public function test_remove_nonexistent_domain_succeeds(): void {
        global $wp_options;
        $wp_options['brz_options']['firewall']['blacklist'] = [ 'other.com' ];
        $_POST['domain'] = 'nonexistent.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_remove_domain' ] );

        $this->assertTrue( $response['success'] );
        $this->assertContains( 'other.com', $response['data']['domains'] );
    }

    // ─── Capability/Nonce Tests ────────────────────────────────────────────────

    public function test_switch_mode_rejects_unauthorized_user(): void {
        global $wp_test_current_user_can;
        $wp_test_current_user_can = false;
        $_POST['mode'] = 'whitelist';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_switch_mode' ] );

        $this->assertFalse( $response['success'] );
    }

    public function test_add_domain_rejects_unauthorized_user(): void {
        global $wp_test_current_user_can;
        $wp_test_current_user_can = false;
        $_POST['domain'] = 'example.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_add_domain' ] );

        $this->assertFalse( $response['success'] );
    }

    public function test_remove_domain_rejects_unauthorized_user(): void {
        global $wp_test_current_user_can;
        $wp_test_current_user_can = false;
        $_POST['domain'] = 'example.com';

        $response = $this->callAjax( [ BRZ_Firewall::class, 'ajax_remove_domain' ] );

        $this->assertFalse( $response['success'] );
    }
}
