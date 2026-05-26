<?php
/**
 * Property 3: Domain matching correctness.
 *
 * For any domain entry and host string (both case-insensitive):
 * - If the entry is an exact domain, matches() returns true iff host equals entry.
 * - If the entry is a wildcard *.X, matches() returns true iff host ends with .X.
 * - *.example.com does NOT match example.com itself.
 *
 * **Validates: Requirements 6.1, 6.2, 6.3, 6.4**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MatchingPropertyTest extends TestCase {

    /**
     * Exact domain matching: matches iff equal (case-insensitive).
     */
    public static function exactMatchProvider(): array {
        $domains = [
            'example.com',
            'api.example.com',
            'my-site.org',
            'cdn.static.example.net',
            'localhost',
            'a.b.c.d.e.com',
        ];

        $cases = [];

        // Same domain should match.
        foreach ( $domains as $domain ) {
            $cases[ "exact_match_{$domain}" ] = [ $domain, $domain, true ];
        }

        // Case variations should match.
        $cases['upper_host']   = [ 'EXAMPLE.COM', 'example.com', true ];
        $cases['upper_entry']  = [ 'example.com', 'EXAMPLE.COM', true ];
        $cases['mixed_case']   = [ 'Api.Example.COM', 'api.example.com', true ];
        $cases['both_upper']   = [ 'MY-SITE.ORG', 'MY-SITE.ORG', true ];

        // Different domains should NOT match.
        $cases['different_1']  = [ 'example.com', 'example.org', false ];
        $cases['different_2']  = [ 'api.example.com', 'example.com', false ];
        $cases['different_3']  = [ 'example.com', 'api.example.com', false ];
        $cases['different_4']  = [ 'my-site.org', 'my-site.com', false ];
        $cases['prefix_match'] = [ 'notexample.com', 'example.com', false ];
        $cases['suffix_match'] = [ 'example.com.evil', 'example.com', false ];

        return $cases;
    }

    #[DataProvider('exactMatchProvider')]
    public function test_exact_domain_matching( string $host, string $entry, bool $expected ): void {
        $result = BRZ_Firewall_Validator::matches( $host, $entry );
        $this->assertSame(
            $expected,
            $result,
            "matches('$host', '$entry') should be " . ( $expected ? 'true' : 'false' )
        );
    }

    /**
     * Wildcard matching: *.X matches subdomains of X but NOT X itself.
     */
    public static function wildcardMatchProvider(): array {
        return [
            // Should match: host is a subdomain of the wildcard base.
            'sub_matches'           => [ 'api.example.com', '*.example.com', true ],
            'deep_sub_matches'      => [ 'cdn.api.example.com', '*.example.com', true ],
            'very_deep_matches'     => [ 'a.b.c.example.com', '*.example.com', true ],
            'single_sub'            => [ 'www.google.com', '*.google.com', true ],
            'sub_with_hyphen'       => [ 'my-api.example.com', '*.example.com', true ],
            'numeric_sub'           => [ '123.example.com', '*.example.com', true ],
            'case_insensitive_1'    => [ 'API.EXAMPLE.COM', '*.example.com', true ],
            'case_insensitive_2'    => [ 'api.example.com', '*.EXAMPLE.COM', true ],

            // Should NOT match: host IS the base domain (not a subdomain).
            'bare_no_match'         => [ 'example.com', '*.example.com', false ],
            'bare_upper_no_match'   => [ 'EXAMPLE.COM', '*.example.com', false ],

            // Should NOT match: host is unrelated.
            'unrelated_1'           => [ 'other.com', '*.example.com', false ],
            'unrelated_2'           => [ 'example.org', '*.example.com', false ],
            'partial_suffix'        => [ 'notexample.com', '*.example.com', false ],
            'evil_suffix'           => [ 'evil-example.com', '*.example.com', false ],

            // Edge cases.
            'empty_host'            => [ '', '*.example.com', false ],
            'empty_entry'           => [ 'api.example.com', '', false ],
            'both_empty'            => [ '', '', false ],
        ];
    }

    #[DataProvider('wildcardMatchProvider')]
    public function test_wildcard_domain_matching( string $host, string $entry, bool $expected ): void {
        $result = BRZ_Firewall_Validator::matches( $host, $entry );
        $this->assertSame(
            $expected,
            $result,
            "matches('$host', '$entry') should be " . ( $expected ? 'true' : 'false' )
        );
    }

    /**
     * Property: matches() is case-insensitive for any input combination.
     */
    public static function caseInsensitiveProvider(): array {
        $pairs = [
            [ 'example.com', 'example.com' ],
            [ 'Example.Com', 'example.com' ],
            [ 'EXAMPLE.COM', 'example.com' ],
            [ 'api.example.com', '*.example.com' ],
            [ 'API.EXAMPLE.COM', '*.example.com' ],
            [ 'Api.Example.Com', '*.EXAMPLE.COM' ],
        ];

        $cases = [];
        foreach ( $pairs as $i => $pair ) {
            $cases[ "case_pair_{$i}" ] = $pair;
        }
        return $cases;
    }

    #[DataProvider('caseInsensitiveProvider')]
    public function test_matching_is_case_insensitive( string $host, string $entry ): void {
        $lower_result = BRZ_Firewall_Validator::matches( strtolower( $host ), strtolower( $entry ) );
        $mixed_result = BRZ_Firewall_Validator::matches( $host, $entry );
        $this->assertSame(
            $lower_result,
            $mixed_result,
            "matches() should be case-insensitive for host='$host', entry='$entry'"
        );
    }

    /**
     * Property: matches() is commutative for exact entries only.
     * (Not for wildcards — *.example.com matching api.example.com is not the same as
     * api.example.com matching *.example.com)
     */
    public static function symmetryProvider(): array {
        return [
            [ 'example.com', 'example.com', true ],
            [ 'api.example.com', 'api.example.com', true ],
            [ 'example.com', 'other.com', true ],
        ];
    }

    #[DataProvider('symmetryProvider')]
    public function test_exact_matching_is_symmetric( string $a, string $b, bool $symmetric ): void {
        if ( $symmetric ) {
            $this->assertSame(
                BRZ_Firewall_Validator::matches( $a, $b ),
                BRZ_Firewall_Validator::matches( $b, $a ),
                "Exact matching should be symmetric for '$a' and '$b'"
            );
        }
    }
}
