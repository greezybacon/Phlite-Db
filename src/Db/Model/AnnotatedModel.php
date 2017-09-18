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
    static function wrap(ModelBase $model, $extras=array(), $class=false) {
        static $classes = array();

        $fqclass = $class ?: get_class($model);
        $class = $class ?: (new \ReflectionClass($model))->getShortName();
        $extra = ($extras instanceof ModelBase) ? 'Writeable' : '';
        $classname = "_{$extra}Annotated_{$class}";

        // XXX: Would be super nice to return an anonymous class, but that
        //      might imply a metadata inspection for each instance, and
        //      an anonymous class cannot extend another class defined by
        //      a variable (return new class extends $parentClass {})

        // For consistent meta-data, the annotated class should be in the same
        // namespace as the parent class
        $namespace = (new \ReflectionClass($model))->getNamespaceName();

        if (!isset($classes[$classname])) {
            $local_ns = __NAMESPACE__;
            $q = <<<END_CLASS
namespace {$namespace};
class {$classname}
extends \\{$fqclass} {
    private \$__overlay__;
    use \\$local_ns\\{$extra}AnnotatedModelTrait;

    static \$meta = array();

    static function __hydrate(\$ht=false, \$annotations=false) {
        \$instance = parent::__hydrate(\$ht);
        \$instance->__overlay__ = \$annotations;
        return \$instance;
    }
}
END_CLASS;
            eval($q);
            $classes[$classname] = 1;
        }
        $class = $namespace . '\\' . $classname;
        return $class::__hydrate($model->__ht__, $extras);
    }
}

trait AnnotatedModelTrait {
    function get($what, $default=false) {
        if (isset($this->__overlay__[$what]))
            return $this->__overlay__[$what];
        return parent::get($what);
    }

    function set($what, $to) {
        if (isset($this->__overlay__[$what]))
            throw new \Phlite\Db\Exception\OrmException('Annotated fields are read-only');
        return parent::set($what, $to);
    }

    function __isset($what) {
        if (isset($this->__overlay__[$what]))
            return true;
        return parent::__isset($what);
    }

    function getDbFields() {
        return $this->__overlay__ + parent::getDbFields();
    }
}

/**
 * Slight variant on the AnnotatedModelTrait, except that the overlay is
 * another model. Its fields are preferred over the wrapped model's fields.
 * Updates to the overlayed fields are tracked in the overlay model and
 * therefore kept separate from the annotated model's fields. ::save() will
 * call save on both models. Delete will only delete the overlay model (that
 * is, the annotated model will remain).
 */
trait WriteableAnnotatedModelTrait {
    function get($what, $default=false) {
        if ($this->__overlay__->__isset($what))
            return $this->__overlay__->get($what);
        return parent::get($what);
    }

    function set($what, $to) {
        if (isset($this->__overlay__)
            && $this->__overlay__->__isset($what)) {
            return $this->__overlay__->set($what, $to);
        }
        return parent::set($what, $to);
    }

    function __isset($what) {
        if (isset($this->__overlay__) && $this->__overlay__->__isset($what))
            return true;
        return parent::__isset($what);
    }

    function getDbFields() {
        return $this->__overlay__->getDbFields() + parent::getDbFields();
    }

    function save($refetch=false) {
        $this->__overlay__->save($refetch);
        return parent::save($refetch);
    }

    function delete() {
        if ($rv = $this->__overlay__->delete())
            // Mark the annotated object as deleted, but don't drop the 
            // related model
            $this->__deleted__ = true;
        return $rv;
    }

    function __call($func, $args) {
        if (isset($this->__overlay__)) {
            // Invoke in overlay, but do not change the $this variable. That
            // will allow this annotation to continue to be used for lookups inside
            // the overlay method.
            return $this->__overlay__->{$func}(...$args);
        }
    }
}