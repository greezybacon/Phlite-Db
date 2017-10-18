<?php
namespace Phlite\Db\Fields;

/**
 * Same as a standard ForeignKey, except that the reverse relationship is
 * automatically *not* a list. One to one fields are often used as a form
 * of inheritance or extension in SQL databases.
 */
class OneToOneField
extends ForeignKey {
}