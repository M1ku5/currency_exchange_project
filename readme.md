php: 8.2, mySql: 10.4.32, Xampp: 3.3.0
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:fetch-currency-rates