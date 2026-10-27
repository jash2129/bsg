<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Article numbers: 2 letters + 8-digit serial + check digit + "IN" (13 characters).
 * Check digit = weighted modulus 11 over the 8 serial digits, weights 8 6 4 2 3 5 9 7,
 * as in the Department of Posts "Barcode Generation Logic".
 *   remainder = sum mod 11; digit = 11 - remainder; 10 becomes 0, 11 becomes 5.
 * Worked example from the document: 47312482 gives sum 200, remainder 2, digit 9.
 */
class BSG_IP_Barcode {
    const LOCK = 'bsg_ip_barcode_lock';

    public static function check_digit($serial8) {
        $serial8 = (string) $serial8;
        if (!preg_match('/^\d{8}$/', $serial8)) {
            return null;
        }
        $w = array(8, 6, 4, 2, 3, 5, 9, 7);
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $serial8[$i] * $w[$i];
        }
        $d = 11 - ($sum % 11);
        if ($d === 10) return 0;
        if ($d === 11) return 5;
        return $d;
    }

    public static function build($prefix, $serial8) {
        $c = self::check_digit($serial8);
        return $c === null ? '' : strtoupper($prefix) . $serial8 . $c . 'IN';
    }

    public static function is_valid($code) {
        return (bool) preg_match('/^[A-Z]{2}(\d{8})(\d)IN$/', $code, $m) && (int) $m[2] === self::check_digit($m[1]);
    }

    /**
     * Hands out the next unused number from the assigned range and advances the counter.
     * Returns '' when the range is not set up or is used up.
     */
    public static function next() {
        $got = '';
        // A short-lived lock so two orders booked at the same moment never share a number.
        for ($i = 0; $i < 20; $i++) {
            if (add_option(self::LOCK, time(), '', false)) {
                break;
            }
            $held = (int) get_option(self::LOCK);
            if ($held && time() - $held > 30) {
                delete_option(self::LOCK);
            }
            usleep(100000);
        }
        $s = BSG_IP_Settings::get();
        $next = (string) $s['barcode_next'];
        $end  = (string) $s['barcode_end'];
        if (preg_match('/^\d{8}$/', $next) && preg_match('/^\d{8}$/', $end) && (int) $next <= (int) $end && preg_match('/^[A-Z]{2}$/', $s['barcode_prefix'])) {
            $got = self::build($s['barcode_prefix'], $next);
            $opt = get_option(BSG_IP_Settings::OPTION);
            $opt['barcode_next'] = str_pad((string) ((int) $next + 1), 8, '0', STR_PAD_LEFT);
            update_option(BSG_IP_Settings::OPTION, $opt, false);
        }
        delete_option(self::LOCK);
        return $got;
    }

    public static function remaining() {
        $s = BSG_IP_Settings::get();
        if (!preg_match('/^\d{8}$/', (string) $s['barcode_next']) || !preg_match('/^\d{8}$/', (string) $s['barcode_end'])) {
            return 0;
        }
        return max(0, (int) $s['barcode_end'] - (int) $s['barcode_next'] + 1);
    }
}
