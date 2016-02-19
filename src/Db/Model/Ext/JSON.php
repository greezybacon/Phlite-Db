<?php
namespace Phlite\Db\Model\Ext;

use Phlite\Db\Model;

trait Json {
    // implements \JsonSerializable

    // ---- JsonSerializable interface ------------------------
    function jsonSerialize() {
        $fields = $this->__ht__;
        foreach (static::$meta['joins'] as $k=>$j) {
            // For join relationships, drop local ID from the fields
            // list. Instead, list the ID as the member of the join unless
            // it is an InstrumentedList or ModelBase instance, in which case
            // use it as is
            $L = @$fields[$k];
            if ($L instanceof Model\InstrumentedList)
                // Don't drop the local ID field from the list
                continue;
            if (!$L instanceof Model\ModelBase)
                // Relationship to a deferred model. Use the local ID instead
                $fields[$k] = $fields[$k['local']];
            unset($fields[$j['local']]);
        }
        return $fields;
    }

    function fromJson($json) {
        $ht = array();
        foreach (static::$meta['fields'] as $f) {
            if (isset($json[$f]))
                $ht[$f] = $json[$f];
        }
        // Return new instance avoiding the constructor
        return static::$meta->newInstance($ht);
    }
}
