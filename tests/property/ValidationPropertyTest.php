<?php
/**
 * Property 2: Validation accepts valid formats and rejects invalid inputs.
 *
 * For any string, the validator accepts it if and only if it matches one of the three
 * valid formats (bare domain, wildcard *.domain, subdomain) using only allowed characters.
 * For any string containing characters outside this set, validation rejects it.
 *
 * **Validates: Requirements 3.1, 3.3, 3.5**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationPropertyTest extends TestCase {

    /**
     * Generate valid domain strings that should be accepted.
     */
    public static function validDomainProvider(): array {
        return [
            // Bare domains
            [ 'example.com' ],
            [ 'my-site.org' ],
            [ 'a.b.c.d.example.net' ],
            [ 'test123.io' ],
            [ 'sub-domain.co.uk' ],
            [ 'x.y' ],
            [ '123.456.com' ],
            [ 'a-b-c.example.com' ],
            [ 'my.long.subdomain.chain.example.org' ],
            [ 'localhost' ],
            // Wildcard domains
            [ '*.example.com' ],
            [ '*.my-site.org' ],
            [ '*.sub.domain.co.uk' ],
            [ '*.a.b' ],
            [ '*.test-123.io' ],
            [ '*.cdn.example.net' ],
            // Subdomains
            [ 'api.example.com' ],
            [ 'cdn.static.example.com' ],
            [ 'mail.google.com' ],
            [ 'deep.nested.sub.domain.org' ],
            [ 'a1.b2.c3.example.com' ],
            // Edge valid cases
            [ 'a.co' ],
            [ '0.0' ],
            [ '1-2-3.example.com' ],
            [ 'xn--nxasmq6b.com' ], // punycode-like
        ];
    }

    #[DataProvider('validDomainProvider')]
    public function test_validation_accepts_valid_domains( string $domain ): void {
        $result = BRZ_Firewall_Validator::validate( $domain );
        $this->assertTrue( $result, "validate('$domain') should return true" );
    }

    /**
     * Generate invalid strings that should be rejected.
     */
    public static function invalidDomainProvider(): array {
        return [
            // Empty
            'empty_string'          => [ '' ],
            // Spaces
            'contains_space'        => [ 'example .com' ],
            'leading_space'         => [ ' example.com' ],
            'trailing_space'        => [ 'example.com ' ],
            'only_spaces'           => [ '   ' ],
            // Special characters
            'contains_at'           => [ 'user@example.com' ],
            'contains_hash'         => [ 'example#.com' ],
            'contains_excl'         => [ 'example!.com' ],
            'contains_dollar'       => [ 'example$.com' ],
            'contains_percent'      => [ 'example%.com' ],
            'contains_ampersand'    => [ 'example&.com' ],
            'contains_equals'       => [ 'example=.com' ],
            'contains_plus'         => [ 'example+.com' ],
            'contains_bracket'      => [ 'example[.com' ],
            'contains_underscore'   => [ 'example_domain.com' ],
            'contains_tilde'        => [ '~example.com' ],
            'contains_pipe'         => [ 'example|.com' ],
            'contains_backslash'    => [ 'example\\.com' ],
            'contains_comma'        => [ 'example,.com' ],
            // Bad wildcard formats
            'wildcard_middle'       => [ 'example.*.com' ],
            'wildcard_end'          => [ 'example.*' ],
            'wildcard_no_dot'       => [ '*example.com' ],
            'double_wildcard'       => [ '*.*.example.com' ],
            'wildcard_alone'        => [ '*' ],
            'wildcard_dot_only'     => [ '*.' ],
            // Missing dot (not localhost)
            'no_dot'                => [ 'examplecom' ],
            'single_label'          => [ 'justadomain' ],
            // Empty labels
            'double_dot'            => [ 'example..com' ],
            'leading_dot'           => [ '.example.com' ],
            // Label too long (>63 chars)
            'long_label'            => [ str_repeat( 'a', 64 ) . '.com' ],
        ];
    }

    #[DataProvider('invalidDomainProvider')]
    public function test_validation_rejects_invalid_domains( string $domain ): void {
        $result = BRZ_Firewall_Validator::validate( $domain );
        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            "validate('$domain') should return WP_Error"
        );
    }

    /**
     * Property: validation result is deterministic.
     * Calling validate twice on the same input gives the same result type.
     */
    public static function determinismProvider(): array {
        return [
            [ 'example.com' ],
            [ '*.test.org' ],
            [ 'invalid domain' ],
            [ '' ],
            [ 'good-domain.io' ],
            [ 'bad@domain.com' ],
        ];
    }

    #[DataProvider('determinismProvider')]
    public function test_validation_is_deterministic( string $domain ): void {
        $result1 = BRZ_Firewall_Validator::validate( $domain );
        $result2 = BRZ_Firewall_Validator::validate( $domain );

        $this->assertSame(
            is_wp_error( $result1 ),
            is_wp_error( $result2 ),
            "validate('$domain') should be deterministic"
        );
    }
}
