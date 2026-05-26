<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Transfer_Codec {
    const MAGIC_NUMBER = 'BRZ1';

    public function encode( $meta, $payload ) {
        $json_str = wp_json_encode( $payload );
        $compressed = gzencode( $json_str, 9 );
        $base64data = base64_encode( $compressed );
        
        $crc = sprintf( '%08X', crc32( $compressed ) );
        
        return sprintf(
            '%s:%s:%s:%s:%s:%s:%s:%s',
            self::MAGIC_NUMBER,
            $meta['version'],
            $meta['operationType'],
            $meta['packageId'],
            $meta['timestamp'],
            isset($meta['operatorId']) ? $meta['operatorId'] : '',
            $crc,
            $base64data
        );
    }

    public function decode( $encoded ) {
        if ( strpos( $encoded, self::MAGIC_NUMBER . ':' ) !== 0 ) {
            throw new Exception( 'فرمت نامعتبر: هدر BRZ1 یافت نشد' );
        }

        $parts = explode( ':', $encoded );
        if ( count( $parts ) < 8 ) {
            throw new Exception( 'فرمت نامعتبر: تعداد بخش‌ها کمتر از حد مورد نیاز' );
        }

        $version = $parts[1];
        $operationType = $parts[2];
        $packageId = $parts[3];
        $timestamp = $parts[4];
        $operatorId = $parts[5];
        $expectedCrc = $parts[6];
        $base64data = implode( ':', array_slice( $parts, 7 ) );

        $compressed = base64_decode( $base64data );
        if ( $compressed === false ) {
            throw new Exception( 'خطا در بازگشایی Base64' );
        }

        $actualCrc = sprintf( '%08X', crc32( $compressed ) );
        if ( $actualCrc !== $expectedCrc ) {
            throw new Exception( 'خطای یکپارچگی: CRC مطابقت ندارد. بسته آسیب دیده است.' );
        }

        $json_str = gzdecode( $compressed );
        if ( $json_str === false ) {
            throw new Exception( 'خطا در خروج از حالت فشرده (GZip)' );
        }

        $payload = json_decode( $json_str, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'خطا در پارس JSON' );
        }

        return array(
            'meta' => array(
                'version' => $version,
                'operationType' => $operationType,
                'packageId' => $packageId,
                'timestamp' => (int) $timestamp,
                'operatorId' => $operatorId,
            ),
            'payload' => $payload
        );
    }
}
