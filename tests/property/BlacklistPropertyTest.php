<?php
/**
 * Property 4: Blacklist mode filtering.
 *
 * For any domain list and any request URL, while in blacklist mode:
 * filter_request returns a WP_Error if and only if the request's host matches
 * at least one entry in the blacklist domain list.
 *
 * **Validates: Requirements 4.1, 4.2**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BlacklistPropertyTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
    }

    private function setBlacklistMode( array $domains ): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'blacklist',
                'blacklist'   => $domains,
                'whitelist'   => [],
            ],
        ];
    }

    /**
     * Generate test cases: domain lists + URLs where host MATCHES a blacklist entry.
     */
    public static function matchingRequestProvider(): array {
        return [
            'exact_match' => [
                [ 'evil.com', 'spam.org' ],
                'https://evil.com/path',
            ],
            'exact_match_second' => [
                [ 'good.com', 'spam.org' ],
                'http://spam.org/api/v1',
            ],
            'wildcard_match' => [
                [ '*.evil.com' ],
                'https://api.evil.com/data',
            ],
            'wildcard_deep_match' => [
                [ '*.example.org' ],
                'http://deep.sub.example.org/',
            ],
            'mixed_list_exact' => [
                [ '*.google.com', 'facebook.com', '*.twitter.com' ],
                'https://facebook.com/api',
            ],
            'mixed_list_wildcard' => [
                [ '*.google.com', 'facebook.com', '*.twitter.com' ],
                'https://api.google.com/search',
            ],
            'case_insensitive_match' => [
                [ 'example.com' ],
                'https://EXAMPLE.COM/path',
            ],
            'single_domain_list' => [
                [ 'blocked.io' ],
                'http://blocked.io',
            ],
            'port_in_url' => [
                [ 'evil.com' ],
                'http://evil.com:8080/path',
            ],
        ];
    }

    #[DataProvider('matchingRequestProvider')]
    public function test_blacklist_blocks_matching_requests( array $blacklist, string $url ): void {
        $this->setBlacklistMode( $blacklist );

        $result = BRZ_Firewall::filter_request( false, [], $url );

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            "Request to '$url' should be blocked with blacklist: " . implode( ', ', $blacklist )
        );
        $this->assertSame( 'brz_firewall_blocked', $result->get_error_code() );
    }

    /**
     * Generate test cases: domain lists + URLs where host does NOT match any blacklist entry.
     */
    public static function nonMatchingRequestProvider(): array {
        return [
            'no_match_exact' => [
                [ 'evil.com', 'spam.org' ],
                'https://good.com/path',
            ],
            'wildcard_no_match_bare' => [
                [ '*.evil.com' ],
                'https://evil.com/path',
            ],
            'wildcard_no_match_unrelated' => [
                [ '*.evil.com' ],
                'https://good.com/path',
            ],
            'empty_blacklist' => [
                [],
                'https://anything.com/path',
            ],
            'partial_match_not_blocked' => [
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
            'mixed_list_no_match' => [
                [ '*.google.com', 'facebook.com' ],
                'https://twitter.com/api',
            ],
        ];
    }

    #[DataProvider('nonMatchingRequestProvider')]
    public function test_blacklist_allows_non_matching_requests( array $blacklist, string $url ): void {
        $this->setBlacklistMode( $blacklist );

        $result = BRZ_Firewall::filter_request( false, [], $url );

        $this->assertFalse(
            $result,
            "Request to '$url' should be allowed with blacklist: " . implode( ', ', $blacklist )
        );
    }

    /**
     * Property: if $pre !== false, filter_request passes through regardless.
     */
    public function test_blacklist_respects_previous_short_circuit(): void {
        $this->setBlacklistMode( [ 'evil.com' ] );

        $pre_value = new WP_Error( 'other_plugin', 'Already handled' );
        $result    = BRZ_Firewall::filter_request( $pre_value, [], 'https://evil.com/path' );

        $this->assertSame( $pre_value, $result, 'Should pass through when $pre !== false' );
    }

    /**
     * Property: unparseable URLs are allowed through (fail open).
     */
    public function test_blacklist_allows_unparseable_urls(): void {
        $this->setBlacklistMode( [ 'evil.com' ] );

        $result = BRZ_Firewall::filter_request( false, [], '' );

        $this->assertFalse( $result, 'Empty URL should be allowed (fail open)' );
    }
}
