<?php
/**
 * Property 5: Whitelist mode filtering.
 *
 * For any domain list and any request URL, while in whitelist mode:
 * filter_request returns a WP_Error if and only if the request's host does NOT
 * match any entry in the whitelist domain list.
 *
 * **Validates: Requirements 5.1, 5.2**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class WhitelistPropertyTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
    }

    private function setWhitelistMode( array $domains ): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'whitelist',
                'blacklist'   => [],
                'whitelist'   => $domains,
            ],
        ];
    }

    /**
     * Generate test cases: URLs whose host IS in the whitelist (should be allowed).
     */
    public static function allowedRequestProvider(): array {
        return [
            'exact_match' => [
                [ 'api.wordpress.org', 'downloads.wordpress.org' ],
                'https://api.wordpress.org/plugins/info',
            ],
            'exact_match_second' => [
                [ 'api.wordpress.org', 'downloads.wordpress.org' ],
                'https://downloads.wordpress.org/plugin/akismet.zip',
            ],
            'wildcard_match' => [
                [ '*.googleapis.com' ],
                'https://fonts.googleapis.com/css',
            ],
            'wildcard_deep_match' => [
                [ '*.googleapis.com' ],
                'https://storage.api.googleapis.com/bucket',
            ],
            'mixed_list' => [
                [ 'api.wordpress.org', '*.googleapis.com', 'github.com' ],
                'https://github.com/repo',
            ],
            'case_insensitive' => [
                [ 'example.com' ],
                'https://EXAMPLE.COM/path',
            ],
            'single_entry' => [
                [ 'trusted.io' ],
                'http://trusted.io/api',
            ],
        ];
    }

    #[DataProvider('allowedRequestProvider')]
    public function test_whitelist_allows_matching_requests( array $whitelist, string $url ): void {
        $this->setWhitelistMode( $whitelist );

        $result = BRZ_Firewall::filter_request( false, [], $url );

        $this->assertFalse(
            $result,
            "Request to '$url' should be allowed with whitelist: " . implode( ', ', $whitelist )
        );
    }

    /**
     * Generate test cases: URLs whose host is NOT in the whitelist (should be blocked).
     */
    public static function blockedRequestProvider(): array {
        return [
            'not_in_list' => [
                [ 'api.wordpress.org', 'downloads.wordpress.org' ],
                'https://evil.com/malware',
            ],
            'wildcard_bare_not_matched' => [
                [ '*.googleapis.com' ],
                'https://googleapis.com/path',
            ],
            'empty_whitelist' => [
                [],
                'https://anything.com/path',
            ],
            'partial_match_not_allowed' => [
                [ 'example.com' ],
                'https://notexample.com/path',
            ],
            'subdomain_not_in_exact' => [
                [ 'example.com' ],
                'https://sub.example.com/path',
            ],
            'different_tld' => [
                [ 'example.com' ],
                'https://example.org/path',
            ],
            'unrelated_domain' => [
                [ '*.google.com', 'facebook.com' ],
                'https://twitter.com/api',
            ],
            'similar_but_different' => [
                [ 'api.example.com' ],
                'https://api2.example.com/path',
            ],
        ];
    }

    #[DataProvider('blockedRequestProvider')]
    public function test_whitelist_blocks_non_matching_requests( array $whitelist, string $url ): void {
        $this->setWhitelistMode( $whitelist );

        $result = BRZ_Firewall::filter_request( false, [], $url );

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            "Request to '$url' should be blocked with whitelist: " . implode( ', ', $whitelist )
        );
        $this->assertSame( 'brz_firewall_blocked', $result->get_error_code() );
    }

    /**
     * Property: if $pre !== false, filter_request passes through regardless.
     */
    public function test_whitelist_respects_previous_short_circuit(): void {
        $this->setWhitelistMode( [ 'allowed.com' ] );

        $pre_value = new WP_Error( 'other_plugin', 'Already handled' );
        $result    = BRZ_Firewall::filter_request( $pre_value, [], 'https://blocked.com/path' );

        $this->assertSame( $pre_value, $result, 'Should pass through when $pre !== false' );
    }

    /**
     * Property: unparseable URLs are allowed through (fail open).
     */
    public function test_whitelist_allows_unparseable_urls(): void {
        $this->setWhitelistMode( [ 'allowed.com' ] );

        $result = BRZ_Firewall::filter_request( false, [], '' );

        $this->assertFalse( $result, 'Empty URL should be allowed (fail open)' );
    }
}
