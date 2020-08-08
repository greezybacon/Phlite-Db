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
then `exact` is assumed and results are compared to your search term exactly. In some cases, this is dependent upon how the underlying platform considers equality. For example, MySQL will use the defined column coalition to make a comparison. And, by default, the comparison is performed case insensitively.

Many other transforms exist to search for data based on the field type. These
are a few of the supported Transforms:

| Lookup      | Field Type   | Operation |
|-------------|--------------|-------------------------------|
| exact       | Any          | Field exactly matches criteria |
| lt          | Any          | Field value is less than criteria |
| gt          | Any          |Field value is greater than criteria|
| lte         | Any          |Field value is less than or equal to criteria|
| gte         | Any          |Field value is greater than or equal to criteria|
| isnull      | Any          | Value is null or not null, based on criteria |
| in          | Any          | Field value is in the list of items or records in a QuerySet |
| range       | Any          | Field value is between a list of two values |
| contains    | TextField    | Field value contains the criteria |
| startswith  | TextField    | Field value starts with the criteria |
| endswith    | TextField    | Field value ends with the criteria |
| regex       | TextField    | Field value matches regular expression |
| hasbit      | IntegerField | Field contains the bit(s) in the criteria |

Transforms, unlike lookups, are used to change field data into another type.
For instance, the `year` transform will convert a date field into the
corresponding year contained within the date.

| Transform   | Field Type | Operation |
|-------------|------------|---------------------------------|
| year        | DateField  | Extracts the year portion of the date |

### `constrain`

For more advanced filtering, `constrain` allows the query to describe filtering on relationships. It works like `filter` except that it is added to join constraints when the query is built.

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
that a numerically-index array is retrieved instead. This allows for the general semantics normally used in fetching records from databases in PHP.

Multiple calls to `values_flat` will reset the fields list to avoid ambiguity.

Using the Northwind example data, here are some examples:

```
>>> Customer::objects()->values('CompanyName')->first();
array (
  'CompanyName' => 'Alfreds Futterkiste',
)
```

## Windowing Recordsets

### `limit`

Limit is used to limit the number of results in a record set.

### `offset`

Offset is used to set the number of records to skip before the first record to be returned in the query set. Limit and offset are generally used to implement things like pageniation. For example, to get results 30-40:

```
>>> Customer::objects()->limit(10)->offset(30);
```

## Annotations and Aggregates

Annotations and aggregates are used to add virtual fields to a query set. 

### Annotations

Annotations are used to add virtual expressions to each record in a queryset. For instance, to get a list of customers and, for each one, get the number of orders:

```
>>> Customer::objects()->annotate([
...     'order_count' => Phlite\Db\Util\Aggregate::COUNT('orders')
... ])->values()->first();
array (
  'CustomerID' => 'ALFKI',
  'CompanyName' => 'Alfreds Futterkiste',
  'ContactName' => 'Maria Anders',
  'ContactTitle' => 'Sales Representative',
  'Address' => 'Obere Str. 57',
  'City' => 'Berlin',
  'Region' => NULL,
  'PostalCode' => '12209',
  'Country' => 'Germany',
  'Phone' => '030-0074321',
  'Fax' => '030-0076545',
  'order_count' => 6,
)

```

Of course, if the `values` method is not used, then annotated instances of the `Customer` class are retrieved from the QuerySet iterator. These instances will have a magic property for `order_count` which will allow access to the annotated field. This magic propery is not updateable. Update attempts will result in an error.

Annotations are welcome in `filter` and `order_by` clauses. For instance, to find the customer with the most recent order:

```
>>> Customer::objects()->annotate([
... 'last_order' => Phlite\Db\Util\Aggregate::MAX('orders__OrderDate')
... ])->order_by('-last_order')->first();
array (
  'CustomerID' => 'BONAP',
  'CompanyName' => 'Bon app\'',
  'ContactName' => 'Laurence Lebihan',
  'ContactTitle' => 'Owner',
  'Address' => '12, rue des Bouchers',
  'City' => 'Marseille',
  'Region' => NULL,
  'PostalCode' => '13008',
  'Country' => 'France',
  'Phone' => '91.24.45.40',
  'Fax' => '91.24.45.41',
  'last_order' => '1998-05-06 00:00:00.000',
)
```

Annotations are nestable, so something more complex like finding customers with at least five orders with at least five items each might look like this:

```
>>> $large_orders = Order::objects()->annotate([
...     'size' => Phlite\Db\Util\Aggregate::COUNT('details')
... ])->filter(['size__gte' => 5]);

>>> $customers = Customer::objects()->filter([
...     'orders__OrderID__in' => $large_orders->values_flat('OrderID')
... ])->annotate([
...     'lg_order_count' => Phlite\Db\Util\Aggregate::COUNT('orders')
... ])->filter(['order_count__gte' => 5]);
```

### Aggregates

Aggregates are used to reduce a QuerySet to a single value. If this is a count, then the `count()` method can be simply used. If it's to find a maximum, then use the aggregate. For instance, to find the most expensive product:

```
>>> Product::objects()->aggregate(
...     Phlite\Db\Util\Aggregate::MAX('UnitPrice')
... )->one();
array (
  'unitprice__max' => 263.5,
)
```

## Subqueries

### Nesting with the `in` operator
### Unions

## Bulk Updates

### `update`

### `delete`