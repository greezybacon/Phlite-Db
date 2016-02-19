<?php

namespace Phlite\Db\Model;

/**
 * AnnotatedModel
 *
 * Simple wrapper class which allows wrapping and write-protecting of
 * annotated fields retrieved from the database. Instances of this class
 * will delegate most all of the heavy lifting to the wrapped Model instance.
 */
class AnnotatedModel {

    var $model;
    var $annotations;

    function __construct($model, $annotations) {
        $this->model = $model;
        $this->annotations = $annotations;
    }

    function __get($what) {
        return $this->get($what);
    }
    function get($what) {
        if (isset($this->annotations[$what]))
            return $this->annotations[$what];
        return $this->model->get($what, null);
    }
    function __set($what, $to) {
        return $this->set($what, $to);
    }
    function set($what, $to) {
        if (isset($this->annotations[$what]))
            throw new Exception\OrmError('Annotated fields are read-only');
        return $this->model->set($what, $to);
    }
    function __isset($what) {
        return isset($this->annotations[$what]) || $this->model->__isset($what);
    }

    // Delegate everything else to the model
    function __call($what, $how) {
        return call_user_func_array(array($this->model, $what), $how);
    }
}
