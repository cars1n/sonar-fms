<?php

// Wait only for the disposable test database; never bootstrap the application.
$deadline = time() + 60;
do {
    try {
        new PDO(
            'pgsql:host='.(getenv('DB_HOST') ?: 'pgsql').';port='.(getenv('DB_PORT') ?: '5432').';dbname=fms_test',
            getenv('DB_USERNAME') ?: 'sail',
            getenv('DB_PASSWORD') ?: 'testing',
        );
        exit(0);
    } catch (PDOException $exception) {
        sleep(1);
    }
} while (time() < $deadline);

fwrite(STDERR, "Test PostgreSQL did not become ready.\n");
exit(1);
