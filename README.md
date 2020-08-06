Phlite-Db is a database abstraction layer for PHP. It's designed in the
spirit of Django but to fit the design style if PHP.

Phlite is still mostly in a conceptual stage and has a ways to grow. It's
intended to be easy to use and to create code that is easy to read.

## Getting Started

### Define a Model

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

### Fetch some Data

    $user = User::objects()->filter(['username' => 'greezy'])->one();

### Use the Data

    if (!cmpare_pwd($user->password, $pw)) ...

### Make updates

    $user->name = "John Doe";
    $user->save();
