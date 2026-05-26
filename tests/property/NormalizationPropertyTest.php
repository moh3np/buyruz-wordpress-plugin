<?php
/**
 * Property 1: Normalization strips noise and preserves domain.
 *
 * For any valid domain string, wrapping it with any combination of protocol prefix,
 * port number, trailing slash, or path segments, then normalizing should produce
 * the original clean lowercase domain string.
 *
 * **Validates: Requirements 3.4**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NormalizationPropertyTest extends TestCase {

    /**
     * Generate test cases: valid domains wrapped with various noise.
     */
    public static function wrappedDomainProvider(): array {
        $domains = [
            'example.com',
            'api.example.com',
            'sub.deep.example.org',
            '*.example.com',
            'my-site.co.uk',
            'cdn.api.service.io',
            'localhost',
            'a.b.c.d.example.net',
            'test-domain.example.com',
            '*.my-service.io',
        ];

        $protocols = [ '', 'http://', 'https://', 'HTTP://', 'HTTPS://', 'Http://' ];
        $ports     = [ '', ':80', ':443', ':8080', ':3000', ':9999', ':1' ];
        $paths     = [ '', '/', '/path', '/path/to/resource', '/a/b/c/d' ];
        $trailing  = [ '', '/' ];

        $cases = [];
        $count = 0;

        foreach ( $domains as $domain ) {
            foreach ( $protocols as $proto ) {
                foreach ( $ports as $port ) {
                    foreach ( $paths as $path ) {
                        $wrapped  = $proto . $domain . $port . $path;
                        $expected = strtolower( $domain );
                        $cases[ "case_{$count}" ] = [ $wrapped, $expected ];
                        $count++;

                        // Stop at ~60 cases to keep test fast but thorough.
                        if ( $count >= 60 ) {
                            break 4;
                        }
                    }
                }
            }
        }

        // Add extra edge cases.
        $cases['trailing_dot']         = [ 'http://example.com.', 'example.com' ];
        $cases['multiple_trailing_dots'] = [ 'example.com...', 'example.com' ];
        $cases['uppercase_domain']     = [ 'HTTPS://EXAMPLE.COM:443/path', 'example.com' ];
        $cases['mixed_case']           = [ 'http://Api.Example.COM:8080/', 'api.example.com' ];
        $cases['wildcard_with_port']   = [ 'https://*.example.com:9090/api', '*.example.com' ];
        $cases['just_domain']          = [ 'example.com', 'example.com' ];
        $cases['empty_string']         = [ '', '' ];
        $cases['spaces_only']          = [ '   ', '' ];
        $cases['domain_with_spaces']   = [ '  example.com  ', 'example.com' ];

        return $cases;
    }

    #[DataProvider('wrappedDomainProvider')]
    public function test_normalization_strips_noise_and_preserves_domain( string $input, string $expected ): void {
        $result = BRZ_Firewall_Validator::normalize( $input );
        $this->assertSame( $expected, $result, "normalize('$input') should produce '$expected', got '$result'" );
    }

    /**
     * Additional property: normalization is idempotent.
     * normalize(normalize(x)) === normalize(x)
     */
    public static function idempotentProvider(): array {
        return [
            [ 'http://example.com:8080/path' ],
            [ 'HTTPS://API.EXAMPLE.COM/' ],
            [ '*.my-domain.org' ],
            [ 'example.com' ],
            [ 'http://sub.domain.co.uk:443/a/b' ],
            [ '' ],
            [ '   spaces.com   ' ],
        ];
    }

    #[DataProvider('idempotentProvider')]
    public function test_normalization_is_idempotent( string $input ): void {
        $once  = BRZ_Firewall_Validator::normalize( $input );
        $twice = BRZ_Firewall_Validator::normalize( $once );
        $this->assertSame( $once, $twice, "normalize should be idempotent for input: '$input'" );
    }
}
