<?php
namespace Phlite\Db\Util;

/**
 * Extremely lightweight Uuid implementation which loosely follows the
 * Rfc4122 posting to create UUIDs using short and simple code. Emphasis is
 * added to *loosely*, as simplicity as opposed to strict compliance is the
 * goal. Also adds the ability to represent the Uuid as a string with more
 * than 4 bits-per-char and without dashes.
 */
class Uuid
# extends SplString
{
    public $bytes;
    static $sequence;

    const NS_DNS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    const NS_URL = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';
    const NS_OID = '6ba7b812-9dad-11d1-80b4-00c04fd430c8';
    const NS_X500 = '6ba7b814-9dad-11d1-80b4-00c04fd430c8';

    function __construct($bytes) {
        $this->bytes = $bytes;
    }

    static function nil() {
        return new static("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00");
    }

    static function fromString($value, $bpc=4) {
        switch ($bpc) {
        case 8:
            return new static($value);
        case 6:
            return new static(base64_decode($value));
        case 4:
            return new static(hex2bin(str_replace('-', '', $value)));
        default:
            return new \Exception('Unexpected bpc.');
        }
    }

    static function generate($version=4, $name=false, $ns=self::NS_OID) {
        switch ($version) {
        case 4:
            return static::generate4();
        case 1:
            return static::generate1();
        case 3:
            return static::generate3($name, $ns);
        case 5:
            return static::generate5($name, $ns);
        }
    }

    static function forName($name) {
        return static::generate5($name, self::NS_OID);
    }

    static function generate1($mac=false) {
        $time = microtime('true');
        $time_high = (int)$time >> 16;
        $time_mid = (int)$time & 0xffff;
        $time_low = ($time - $time_high) * 1000000;
        $seq = self::nextSeq();
        $clock_seq_low = $seq & 0xff;
        $clock_seq_high = ($seq >> 8) & 0x3f;
        # MAC plus date/time
        $mac = dechex(str_replace(':', '', $mac))
            ?: pack("nN", random_int(0, 65535), random_int(0, 4e9));
        return new static(pack("NnnCCa*",
            $time_low, $time_mid,
            ($time_high & 0x0FFF) | (0x1 << 12),
            $clock_seq_high | (0x1 << 6), $clock_seq_low,
            $mac));
    }

    static function generate3($name, $ns) {
        # Meh. This doesn't address machine endianness...
        if (!$ns instanceof self)
            $ns = static::fromString($ns);
        $values = unpack('n8', md5($ns->bytes . $name, true));
        $values[2] = $values[2] & 0x0FFF | (0x3 << 12);
        $values[4] = $values[4] & 0x3FFF | (0x1 << 14);
        return new static(pack('n*', ...$values));
    }

    static function generate5($name, $ns) {
        # Meh. This doesn't address machine endianness...
        if (!$ns instanceof self)
            $ns = static::fromString($ns);
        $values = unpack('n8', sha1($ns->bytes . $name, true));
        $values[3] = $values[3] & 0x0FFF | (0x5 << 12);
        $values[4] = $values[4] & 0x3FFF | (0x1 << 14);
        return new static(pack('n*', ...$values));
    }

    static function generate4() {
        $values = unpack('n8', random_bytes(16));
        $values[3] = $values[3] & 0x0FFF | (0x4 << 12);
        $values[4] = $values[4] & 0x3FFF | (0x1 << 14);
        return new static(pack('n*', ...$values));
    }

    static function nextSeq() {
        if (!isset(static::$sequence))
            static::$sequence = random_int(0, 1e8);
        return static::$sequence++;
    }

    function __toString() {
        $chunks = unpack('n*', $this->bytes);
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', ...$chunks);
    }

    function asBpc($bits=4) {
        switch ($bits) {
        case 4:
            return bin2hex($this->bytes);
        case 6:
            return str_replace('=', '', base64_encode($this->bytes));
        }
    }
}