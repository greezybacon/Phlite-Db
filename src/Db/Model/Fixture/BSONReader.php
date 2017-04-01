<?php
namespace Phlite\Db\Model\Fixture;

class ObjectId {
    var $hex;

    function __construct($hex) {
        $this->hex = $hex;
    }

    static function fromBuffer($buffer) {
        $ints = unpack('V*', $buffer);
        $instance = new static(sprintf('%x%x%x', ...$ints));
        return $instance;
    }

    function __toString() {
        return sprintf('ObjectId(%s)', $this->hex);
    }
}

class BSONReader
extends FileReader {
    function readDocument() {        
        $doc = [];
        $start = $this->file->ftell();
        list(,$size) = @unpack('V', $this->file->fread(4));
        if (!$size)
            return $doc;
        while (list(,$type) = unpack('C', $this->file->fread(1))) {
            if ($type === 0)
                break;
            $name = $this->readCString();
            $doc[$name] = $this->readNextElement($type);
        }
        assert($this->file->ftell() - $start == $size);
        return $doc;
    }

    function readCString() {
        $buffer = '';
        $start = $this->file->ftell();
        do {
            $buffer .= $this->file->fread(128);
            $nullpos = strpos($buffer, "\x00");
        } while ($nullpos === false);
        $string = substr($buffer, 0, $nullpos);
        $this->file->fseek($start + $nullpos + 1);
        return $string;
    }

    function readString() {
        list(, $length) = unpack('V', $this->file->fread(4));
        $string = $this->file->fread($length-1);
        $this->file->fseek(1, SEEK_CUR);
        return $string;
    }

    function readNextElement($type) {
        switch ($type) {
        case 0x01:
            return unpack('d', $this->file->fread(8))[1];
        case 0x02:
            return $this->readString();
        case 0x03:
        case 0x04:
            return $this->readDocument();
        case 0x05:
            return $this->readBinary();
        case 0x06:
        case 0x0a:
            return null;
        case 0x07:
            return ObjectId::fromBuffer($this->file->fread(12));
        case 0x08:
            return (bool) $this->file->fread(1);
        case 0x09:
        case 0x11:
        case 0x12:
            // XXX: Convert to signed
            return unpack('P', $this->file->fread(8))[1];
        case 0x10:
            return unpack('V', $this->file->fread(4))[1];
        }
    }

    function readNext() {
        return $this->readDocument();
    }
}

$bsr = new BSONStreamReader(dirname(__file__) . '/../../../tests/Data/categories.bson');
foreach ($bsr as $doc) {
    print_r($doc);
}