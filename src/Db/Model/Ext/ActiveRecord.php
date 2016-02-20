<?php
namespace Phlite\Db\Model\Ext;

use Phlite\Db\Exception;
use Phlite\Db\Manager;
use Phlite\Db\Model;
use Phlite\Signal;

trait ActiveRecord {
    var $__deleted__ = false;

    /**
     * Drop this record from the database. Returns TRUE if the drop was
     * successful according to the database and FALSE otherwise.
     *
     * Signals:
     * `model.deleted` after successful delete
     */
    function delete() {
        $ex = Manager::delete($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1)
                return false;

            $this->__deleted__ = true;
            Signal::send('model.deleted', $this);
        }
        catch (Exception\DbError $e) {
            return false;
        }
        return true;
    }

    /**
     * Commit changes made to this model to the database. Returns TRUE if the
     * model was successfully persisted to the database and FALSE otherwise.
     *
     * Caveats:
     * If a relationship property of this model is associated with a foreign
     * *new* object, then those objects will be saved and the keys will be
     * updated in this model locally before this model is saved. Then, after
     * this model is saved, if this object has relationships where the primary
     * key of this model is a foreign key for a related model, the primary key
     * value is automatically set in the foreign models.
     *
     * Signals:
     * `model.updated` - if an existing record was updated for this model
     * `model.created` - if a new record was inserted for this model
     */
    function save($refetch=false) {
        if ($this->__deleted__)
            throw new Exception\OrmError('Trying to update a deleted object');

        $pk = static::getMeta('pk');
        $wasnew = $this->__new__;

        // First, if any foreign properties of this object are connected to
        // another *new* object, then save those objects first and set the
        // local foreign key field values
        foreach (static::getMeta('joins') as $prop => $j) {
            if (isset($this->ht[$prop])
                && ($foreign = $this->ht[$prop])
                && $foreign instanceof VerySimpleModel
                && !in_array($j['local'], $pk)
                && null === $this->get($j['local'])
            ) {
                if ($foreign->__new__ && !$foreign->save())
                    return false;
                $this->set($j['local'], $foreign->get($j['fkey'][1]));
            }
        }

        // If there's nothing in the model to be saved, then we're done
        if (count($this->__dirty__) === 0)
            return true;

        $ex = Manager::save($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1) {
                // This doesn't really signify an error. It just means that
                // the database believes that the row did not change. For
                // inserts though, it's a deal breaker
                if ($this->__new__) {
                    return false;
                }
                else {
                    // No need to reload the record if requested — the
                    // database didn't update anything
                    $refetch = false;
                }
            }
        }
        catch (Exception\OrmError $e) {
            return false;
        }

        if ($wasnew) {
            // XXX: Ensure AUTO_INCREMENT is set for the field
            if (count($pk) === 1 && !$refetch) {
                $key = $pk[0];
                $id = $ex->insert_id();
                if (!isset($this->{$key}) && $id)
                    $this->__ht__[$key] = $id;
            }
            $this->__new__ = false;
            Signal::send('model.created', $this);
        }
        else {
            $data = array('dirty' => $this->__dirty__);
            Signal::send('model.updated', $this, $data);
        }
        # Refetch row from database
        if ($refetch) {
            // Preserve non database information such as list relationships
            // across the refetch
            $this->__ht__ = static::objects()->filter($this->getPk())->values()->one()
                + $this->__ht__;
        }
        if ($wasnew) {
            // Attempt to update foreign, unsaved objects with the PK of
            // this newly created object
            foreach (static::getMeta('joins') as $prop => $j) {
                if (isset($this->ht[$prop])
                    && ($foreign = $this->ht[$prop])
                    && in_array($j['local'], $pk)
                ) {
                    if ($foreign instanceof Model\ModelBase
                        && null === $foreign->get($j['fkey'][1])
                    ) {
                        $foreign->set($j['fkey'][1], $this->get($j['local']));
                    }
                    elseif ($foreign instanceof Model\InstrumentedList) {
                        foreach ($foreign as $item) {
                            if (null === $item->get($j['fkey'][1]))
                                $item->set($j['fkey'][1], $this->get($j['local']));
                        }
                    }
                }
            }
        }
        $this->__dirty__ = array();
        return true;
    }
}
