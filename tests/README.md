The common Northwind data is used as the model and data for the testing of
the ORM system. Because unit tests, be definition, are intended to be
independent of other tests, the data model needs to be created in the database
backend and the data fixtures need to be loaded into the backend database
model.

This configuration also allows for testing of various database backends.
The default backend is SQLite3, but this can be changed with the setup
script. After changing the backend, the model needs to be synced again and
the data should be loaded.

Test Setup
==========
Run the `setup.php` script to configure the database backend. Sync the
database model to the backend,

    php tests/setup.php create --file=database.db --backend=sqlite

Then run the tests with the test data

    php tests/setup.php test --file=database.db --backend=sqlite