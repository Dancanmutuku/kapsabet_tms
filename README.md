# Kapsabet Express TMS prototype (PHP, MySQL, XAMPP)

## Installation
1. Install XAMPP and start Apache and MySQL in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\kapsabet_tms`.
3. Open http://localhost/phpmyadmin, choose Import, select `sql/database.sql`, and run it (creates database `kapsabet_tms`).
4. If your MySQL root has a password, set `DB_PASS` in the environment used by Apache, or replace the empty default in `config.php` with that password. You can also set `DB_HOST`, `DB_NAME`, and `DB_USER` there via environment variables. Restart Apache after changing its environment.
5. Open http://localhost/kapsabet_tms/setup.php once, then delete `setup.php`.
6. Open http://localhost/kapsabet_tms/ . Staff login: admin, official, clerk, conductor (password `Pass@1234`).

## Run the tests
Command prompt in the project folder: `C:\xampp\php\php.exe tests\run_tests.php`
(Run setup.php first. Tests run inside a transaction and roll back.)

## Modules
Vehicle registration (vehicles.php), route and fare management (routes.php), queue, trip and boarding (trips.php),
passenger departure search (index.php), feedback and complaint handling (feedback.php, complaints.php).
