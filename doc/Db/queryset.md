QuerySet instances represent an object approach to defining a SQL
statement. QuerySet instances can be used to both retrieve models as well
as do bulk updates.

QuerySets should not be created directly. Use the `objects` static method
of your model classes to instanciate a QuerySet for your model.

## Paths and Transforms

Traversing relationships and forming criteria are performed by placing `__`
between field names and operators. The `__` is used both as a field
separator and an operator separator.

### Traversing Relationships



### Operators and Lookups

## Filtering

### `filter` and `exclude`

The `filter` method is used to describe the values of fields which should
be matched in the resulting record set. `exclude` is the opposite of
`filter`. It specifies constraints which should *not* be matched in the
resulting record set.

Relationships can be traversed using `__` between field names. The `__` is 
used both as a field separator and an operator separator.

### Combining and Negated Criteria

For more complex criteria, the `Q` object can be used to group complex
criteria together, and to specify boolean operators between criteria. For instance:

	use Phlite\Db\Util\Q;
	$users = User::objects()->filter(Q::any([
		'username' => 'bubba',
		'emails__address' => 'bubba@mycompany.com',
	]));

The `Q` class has methods for `any` (OR), `all` (AND) and `not` which can
be used to define very complex criteria.

### Transforms

Transforms and Lookups are used to define how the results in your filter are
compiled into SQL statements. If your filter does not specify any transform,
then `exact` is assumed and results are compared to your search term exactly.

Many other transforms exist to search for data based on the field type. These
are a few of the supported Transforms:

Lookup      | Field Type   | Operation
------------+--------------+-------------------------------
exact       | Any          | Field exactly matches criteria
lt          | Any          | Field value is less than criteria
gt
lte
gte
isnull      | Any          | Value is null or not null, based on criteria
in          | Any          | Field value is in the list of items or QuerySet
range       | Any          | Field value is between a list of two values
contains    | TextField    | Field value contains the criteria
startswith  | TextField    | Field value starts with the criteria
endswith    | TextField    | Field value ends with the criteria
regex       | TextField    | Field value matches regular expression
hasbit      | IntegerField | Field contains the bit(s) in the criteria

Transform   | Field Type | Operation
------------+------------+---------------------------------
year        | DateField  | Extracts the year portion of the date

## Fetching

### `first`

Using `first` causes the record set to be truncated to return only the first
match. Additionally, instead of being iterable or returning a list, the object
or array which would be the first item in the record set is returned directly.
For instance

	>>> User::objects()->filter(['name' => 'greezy'])->first()
	{User id=33}

### `one`

One operates exactly like `first` with the addition that an exception is
raised if not exactly one record is retrieved from the database.
`DoesNotExist` is thrown if no records match the criteria, and `NotUnique`
is thrown if more than one record is matched by the criteria.

### `all`

All provides access to an Interator instance which can be used to iterate
across the results. It is implied if the QuerySet is used as an iterator or as an array.

### `values` and `values_flat`

Instead of retrieving model instances, your query set can be configured to
retrieve only a list of fields from the database. As with any query set
method, relationships can be spanned with `__` between fields. `values`
will result in a list of hasharray records from the database where the keys
are the field paths as specified in the arguments to `values`, and the
values are the corresponding values. `values_flat` is very similar, except
that a numerically-index array is retrieved instead. Multiple calls to
`values_flat` will reset the fields list to avoid ambiguity.

## Annotations and Aggregates

## Subqueries

### Nesting with the `in` operator
### Unions

## Bulk Updates

### `update`

### `delete`