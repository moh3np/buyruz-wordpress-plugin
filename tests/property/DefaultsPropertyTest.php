<?php
/**
 * Property 7: Defensive defaults for corrupted data.
 *
 * For any corrupted or missing settings value (non-string mode, non-array domain list,
 * null, random types), get_settings() always returns a valid structure with
 * active_mode = 'blacklist' and both domain lists as arrays.
 *
 * **Validates: Requirements 1.5, 9.4**
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DefaultsPropertyTest extends TestCase {

    protected function setUp(): void {
        brz_test_reset_state();
    }

    /**
     * Generate corrupted settings scenarios.
     */
    public static function corruptedSettingsProvider(): array {
        return [
            // Missing brz_options entirely.
            'no_options' => [
                null,
            ],
            // brz_options exists but no firewall key.
            'no_firewall_key' => [
                [ 'modules' => [ 'http_fw' => 1 ] ],
            ],
            // firewall key is not an array.
            'firewall_is_string' => [
                [ 'firewall' => 'invalid' ],
            ],
            'firewall_is_int' => [
                [ 'firewall' => 42 ],
            ],
            'firewall_is_bool' => [
                [ 'firewall' => true ],
            ],
            'firewall_is_null' => [
                [ 'firewall' => null ],
            ],
            // active_mode is corrupted.
            'mode_is_int' => [
                [ 'firewall' => [ 'active_mode' => 123, 'blacklist' => [], 'whitelist' => [] ] ],
            ],
            'mode_is_invalid_string' => [
                [ 'firewall' => [ 'active_mode' => 'greylist', 'blacklist' => [], 'whitelist' => [] ] ],
            ],
            'mode_is_null' => [
                [ 'firewall' => [ 'active_mode' => null, 'blacklist' => [], 'whitelist' => [] ] ],
            ],
            'mode_is_empty' => [
                [ 'firewall' => [ 'active_mode' => '', 'blacklist' => [], 'whitelist' => [] ] ],
            ],
            'mode_is_array' => [
                [ 'firewall' => [ 'active_mode' => [ 'blacklist' ], 'blacklist' => [], 'whitelist' => [] ] ],
            ],
            // Domain lists are corrupted.
            'blacklist_is_string' => [
                [ 'firewall' => [ 'active_mode' => 'blacklist', 'blacklist' => 'not-array', 'whitelist' => [] ] ],
            ],
            'whitelist_is_int' => [
                [ 'firewall' => [ 'active_mode' => 'whitelist', 'blacklist' => [], 'whitelist' => 999 ] ],
            ],
            'blacklist_is_null' => [
                [ 'firewall' => [ 'active_mode' => 'blacklist', 'blacklist' => null, 'whitelist' => [] ] ],
            ],
            'whitelist_is_null' => [
                [ 'firewall' => [ 'active_mode' => 'whitelist', 'blacklist' => [], 'whitelist' => null ] ],
            ],
            'both_lists_corrupted' => [
                [ 'firewall' => [ 'active_mode' => 'blacklist', 'blacklist' => 'bad', 'whitelist' => false ] ],
            ],
            // Missing keys within firewall.
            'missing_mode' => [
                [ 'firewall' => [ 'blacklist' => [ 'a.com' ], 'whitelist' => [] ] ],
            ],
            'missing_blacklist' => [
                [ 'firewall' => [ 'active_mode' => 'blacklist', 'whitelist' => [] ] ],
            ],
            'missing_whitelist' => [
                [ 'firewall' => [ 'active_mode' => 'whitelist', 'blacklist' => [] ] ],
            ],
            'empty_firewall_array' => [
                [ 'firewall' => [] ],
            ],
            // Completely random types.
            'brz_options_is_string' => [
                'just a string',
            ],
            'brz_options_is_int' => [
                12345,
            ],
            'brz_options_is_bool' => [
                false,
            ],
        ];
    }

    #[DataProvider('corruptedSettingsProvider')]
    public function test_get_settings_returns_valid_structure_for_corrupted_data( $corrupted_options ): void {
        global $wp_options;

        if ( $corrupted_options === null ) {
            // Don't set brz_options at all.
            unset( $wp_options['brz_options'] );
        } else {
            $wp_options['brz_options'] = $corrupted_options;
        }

        $settings = BRZ_Firewall::get_settings();

        // Must always return an array.
        $this->assertIsArray( $settings );

        // Must have active_mode key with valid value.
        $this->assertArrayHasKey( 'active_mode', $settings );
        $this->assertContains( $settings['active_mode'], [ 'blacklist', 'whitelist' ] );

        // Must have blacklist key as array.
        $this->assertArrayHasKey( 'blacklist', $settings );
        $this->assertIsArray( $settings['blacklist'] );

        // Must have whitelist key as array.
        $this->assertArrayHasKey( 'whitelist', $settings );
        $this->assertIsArray( $settings['whitelist'] );
    }

    /**
     * Property: when mode is corrupted, defaults to 'blacklist'.
     */
    public static function corruptedModeProvider(): array {
        return [
            'int_mode'     => [ 123 ],
            'float_mode'   => [ 3.14 ],
            'null_mode'    => [ null ],
            'empty_mode'   => [ '' ],
            'invalid_mode' => [ 'greylist' ],
            'array_mode'   => [ [ 'blacklist' ] ],
            'bool_mode'    => [ true ],
        ];
    }

    #[DataProvider('corruptedModeProvider')]
    public function test_corrupted_mode_defaults_to_blacklist( $corrupted_mode ): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => $corrupted_mode,
                'blacklist'   => [ 'preserved.com' ],
                'whitelist'   => [ 'also-preserved.com' ],
            ],
        ];

        $settings = BRZ_Firewall::get_settings();

        $this->assertSame( 'blacklist', $settings['active_mode'], 'Corrupted mode should default to blacklist' );
    }

    /**
     * Property: valid settings are preserved correctly.
     */
    public function test_valid_settings_are_preserved(): void {
        global $wp_options;
        $wp_options['brz_options'] = [
            'firewall' => [
                'active_mode' => 'whitelist',
                'blacklist'   => [ 'evil.com', '*.spam.org' ],
                'whitelist'   => [ 'good.com', '*.trusted.io' ],
            ],
        ];

        $settings = BRZ_Firewall::get_settings();

        $this->assertSame( 'whitelist', $settings['active_mode'] );
        $this->assertSame( [ 'evil.com', '*.spam.org' ], $settings['blacklist'] );
        $this->assertSame( [ 'good.com', '*.trusted.io' ], $settings['whitelist'] );
    }
}
