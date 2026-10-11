<?php
/**
 * Spreadsheet-safe CSV serialization helpers.
 *
 * @package Dsquared_Hub_Connector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DHC_CSV {

    /**
     * Force formula-leading values to remain text in spreadsheet clients.
     *
     * The original value is preserved after a leading apostrophe, including
     * whitespace and control characters that some importers ignore before a
     * formula marker.
     *
     * @param mixed $value Cell value.
     * @return mixed
     */
    public static function safe_cell( $value ) {
        if ( ! is_string( $value ) || '' === $value ) return $value;

        $dangerous = preg_match( '/^(?:\x{FEFF}|\p{Z}|[\x00-\x20])*[=+\-@]/u', $value );
        if ( false === $dangerous ) {
            $dangerous = preg_match( '/^[\x00-\x20]*[=+\-@]/', $value );
        }

        return 1 === $dangerous ? "'" . $value : $value;
    }

    /**
     * Write one spreadsheet-safe CSV row.
     *
     * @param resource $stream Open output stream.
     * @param array    $row    Cell values.
     * @return int|false
     */
    public static function write_row( $stream, $row ) {
        return fputcsv( $stream, array_map( array( __CLASS__, 'safe_cell' ), (array) $row ), ',', '"', '' );
    }
}
