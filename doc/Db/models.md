# Defining a Model

Models are classes which extends from the ORM ModelBase class or another
subclass of ModelBase. Models must define some basic meta-data which links
the model class to the rows and columns in the database.

	use Phlite\Db;
    class User extends Db\Model\ModelBase {
		static $meta = array(
			'table' => 'user',
			'pk' => ['id'],
		);
	}

## Model Meta-Data

The meta data is used to establish the connection between this model and
the data in the database, as well as the connections between this model and
other models defined in your project.

At a minimum, the database table, and the primary key of the model must be
defined in the model's `$meta` variable. Other keys can be defined which
will infleuence how the ORM will represent and link your model in your
project.

### 'table'

The table defines which table in the database holds the data for your model.

### 'pk'

Datatype: 	Array

The primary key of your model defines the columns which uniquely identify
an instance of your model. This is commonly and ID field, but may be any
combination of columns which are not null and uniquely identify exactly one
instance of your model. The pk fields are likely defined in the database
schema to be the primary key of your table; however, Phlite does not
require the pk fields to be the database primary key.

### 'view'

Datatype: 	Boolean
Default: 	False

If your model does not store any unique data in the database, it can be
defined as a view. View models need to implement the View interface and
define a few methods which allow the ORM compiler to retrieve a QuerySet to
describe the rows retrieved in the view.

### 'select_related'

Datatype: 	Array
Default:	null

When building QuerySet instances, you can give hints as to which related
objects should be fetched from the database when fetching the main model
instances of the QuerySet. Doing so can streamline access to the database
by eliminating the need for extra trips to fetch related objects. It does,
however, also increase the complexity of the query and the time needed to
process the results form the database.

The `select_related` meta data defines the default relationships to be
retrieved from the database when fetching instances for this model if the
QuerySet does not specify a list of `select_related` items.

### 'ordering'

Datatype: 	Array
Default: 	null

The `ordering` meta data defines the default fields used for sorting when
instances of this model are retrieved from the database and the QuerySet
used does not specify any `order_by` fields. The format of the fields is
the same as for the QuerySet's `order_by` method and items are allowed to
span relationships of this model and related models.

### 'joins'

Datatype: 	HashArray
Default:	empty

Joins define relationships between this model and other models defined in
your project. Each join has several sub-properties which define how the
foreign model is related to this model. Each join should have a name which
will map to a property of model instances, and map to an array of
properties which define the relationship.

	class User extends Db\Model\ModelBase {
		static $meta = [
			'joins' => [
				'emails' => [
					'constraint' => ['id' => 'UserEmail.user_id'],
					'list' => true,
				],
				'manager' => [
					'constraint' => ['manager_id' => 'User.id'],
					'null' => true,
				]
			],
		];
	}

#### 'constraint'

This defines the main join constraint. The constraint is defined as a list
of local fields which relate fields of another model. This is analogous to
the SQL foreign key concept. The left hand side (key) of each item in the
constraint array should be a local field to the model, or a constant
enclosed in single quotes. The right-hand-side (value) of each item should
be a dotted name of the model class and field name of the foreign side of
the relationship.

#### 'null'

Datatype: 	Boolean
Default:	false

If the relationship is allowed to be NULL, this property can instruct the
ORM to allow such. This is automatically set for reverse relationships.

#### 'reverse'

Datatype: 	Model.join
Default:	null

This is used as a convenience to specify the opposite of a One-To-Many
relationship normally configured in model meta data. For instance, for a
join connection user and user-email, the reverse would connect user-email
to a list of user's with that email address. Using a reverse automatically
turns on the `list` property. If the reverse should be exactly one model
instance, be sure to also set `list` to `false`.

#### 'list'

Datatype:	Boolean
Default:	`true` if `reverse` is specified otherwise `false`

For one to many relationships, a list is built which mimicks the PHP array
built-in with some additions. The class specified by the `broker` property
is used to manage the list of related items. This property is automatically
enabled when specifying a reverse relationship.

#### 'broker'

Datatype: 	Classname
Default:	Db\Model\InstrumentedList

This specifies broker class used for list relationships. If your
relationship requires special hardware, define a class which extends
`InstrumentedList` and add your extra features. Specify the name of that
class in this property.

### 'defer'

When retrieving fields for this model, this property indicates expensive
fields which should only be fetched from the database if requested
specifically. This would be useful for large BLOB fields, for instance,
which should be fetched separately from the rest of the record. Fields can
be deferred when building the QuerySet. This property specifies the default
deferred fields if the QuerySet does not specify any deferred fields.

## Field Magic Properties

The fields for your model are accessed magically from the PHP object
instances used to represent them. In other words, they do not need to be
specified in your model. All properties have public access in your model,
and you are free to define getter and setter methods to control access to
your fields as you see fit.

	>>> class User extends Db\Model\ModelBase {
	... 	static $meta = array(
	... 		'table' => 'user',
	... 		'pk' => ['id'],
	... 	);
	...	}
	>>> $u = User::objects()->lookup(13);
	>>> $u->name
	'Bubba'

In the above example, the `name` field is retrieved from the database when
fetching the User instance via the `lookup` method. The instances fields
are retrieved magically and map to the column names in the database. The
fields should *not* be specified in your model class as properties, or this 
magic will not work properly.

Relationships defined in your `joins` property in your model's meta data
are also accessible via magic properties. They will be loaded lazily from
the database, or immediately if they were fetched via the `select_related`
configuration.

# Retrieving Instances

The ModelBase class comes with two ways to retrieve instances of your
models from the database. `lookup` is used to fetch at most one instance by
the model's primary key or some other unique constraint. `objects` is used
to fetch a list of object based on some criteria. The `objects` method
creates and returns a QuerySet instance which can be used to specify
criteria as well as configure many aspects of the query sent to the
database as well as the data retrieved from the database.

## `lookup`

Lookup is used to fetch exactly one instance of your model. If no instance
can be found, `DoesNotExist` is thrown from the ORM. If more than one
instance is found, `NotUnique` is thrown. Therefore, the method is
guaranteed to return an instance of the model or throw an exception
otherwise.

If querying by a model's primary key, the key values can be specified as
arguments to the `lookup` method in the order the fields are declared in
the model's meta data. Model's with composite primary keys will simply
require multiple arguments to the `lookup` method.

## `objects`

Objects is used to construct a QuerySet instance for the model. QuerySet
instances allow for a wide array of options to find and update instances of
your model. If you require special queries when retrieving your instances,
you can define a custom `objects` method and build off the base definition.
For instance, you might constrain a field based on some known value:

	class User extends Db\Model\ModelBase {
		static function objects() {
			$objects = parent::objects();
			return $objects->filter(['id__lt' => 1000]);
		}
	}

# Creating and Updating Instances

Phlite uses the active record pattern. That is, individual model instances
have `save` and `delete` methods to adjust their state in the database.
Both methods have immediate effects and return boolean true or false
indicating if the update was successful.

Creating new instances is done by using the model's constructor. The
constructor will create and configure a new instance which, when saved,
will result in an insert into the database.

	>>> $u = new User(['name' => 'Me'])
	>>> $u->save()
	true
	>>> $u->id
	8

As shown in the example, field values can be specified when calling the
constructor by passing a hash array with the field names and values.

When instances are saved, if the model's primary key is an auto-increment
field, the inserted id number is retrieved from the database and set into
the field automatically.

Saving an instance will trigger saving related objects. Because creating an
object does not imply saving the object, objects can be related using the
ORM join magic properties. Technically, at the database level, the data
fields will eventually need to receive the related object's key ids. The
model will automatically save related *new* instances when it is saved,
capture their key id numbers, and stash them in the related, local fields
before saving.

## Bulk Updates

Updates can be performed in bulk with the QuerySet via the
`QuerySet::update()` and `QuerySet::delete()` methods. Bulk inserts are not supported.

# Caching

The ORM is designed so that only one instance of a given object,
represented by its class and primary key, is in memory at any time. If at
any time, an instance of a model is loaded and it is already in the
cache, the cache instance is used rather than creating a new instance. This
algorithm helps ensure that unsaved updates to any model are reflected
globally, no matter how many references to the model exist and no matter
how the instances were retrieved from the database nor at what time it was
retrieved.

# Model Meta Class

In some circumstances, you may need to handle some special processing
around the creation of new model instances for your class, or handle the
meta data specially. In such a case, you can declare which metadata class
should be used to manage the metadata for your model. To do so, define a
`$metaclass` static variable to declare which ModelMeta deriviative class
should be used for your model.

Datatype: 	Classname
Default:	Db\Model\ModelMeta


	class MyUserMeta
	extends Db\Model\ModelMeta {
		function build() {
			// Add something magical
			return parent::build();
		}
	}

    class User
	extends Db\Model\ModelBase {
		static $metaclass = MyUserMeta::class;
	}