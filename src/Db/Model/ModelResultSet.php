<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;

class ModelResultSet
extends CachedResultSet {
    /**
     * Find the first item in the current set which matches the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance.
     *
     * Example:
     * >>> $a = new User();
     * >>> $a->roles->add(Role::lookup(['name' => 'administator']));
     * >>> $a->roles->findFirst(['roles__name__startswith' => 'admin']);
     * <Role: administrator>
     */
    function findFirst($criteria) {
        $records = $this->findAll($criteria, 1);
        return @$records[0];
    }

    /**
     * Find all the items in the current set which match the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance, as they can provide results with one more trip to the
     * database.
     */
    function findAll($criteria, $limit=false) {
        $records = array();
        if (!$criteria instanceof Q)
            $criteria = new Q($criteria);
        foreach ($this as $record) {
            if ($criteria->matches($record))
                $records[] = $record;
            if ($limit && count($records) == $limit)
                break;
        }
        return $records;
    }
}
