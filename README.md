# PHP Wiki with MySQL

The application uses PHP 8.3, Apache, MySQL 8.4, and PDO. The schema in
`schema.json` is synchronized automatically whenever the web container starts.

## Start the application

1. Create the local environment file:

   ```bash
   cp .env.example .env
   ```

2. Replace both example passwords in `.env` with long random passwords.

3. Build and start the containers:

   ```bash
   docker compose up -d --build
   ```

4. Open <http://localhost:8086>.

View startup logs with:

```bash
docker compose logs -f web db
```

## Schema synchronization

Safe synchronization creates missing tables, columns, and indexes:

```bash
docker compose exec web php /var/www/html/database.php
```

Changed or removed definitions are reported but not applied in safe mode. To
apply them, run:

```bash
docker compose exec web php /var/www/html/database.php --destructive
```

To also drop database tables absent from `schema.json`:

```bash
docker compose exec web php /var/www/html/database.php \
  --destructive --drop-missing-tables
```

Destructive mode first creates snapshot tables whose names start with
`__schema_backup_`.

## Data persistence

MySQL data is stored in the `mysql-data` Docker volume and survives ordinary
container recreation. `docker compose down -v` deletes that volume and all
database data, so use it only when a complete reset is intended.

The old `database.sqlite*` files are retained only as legacy backups. They are
not mounted, opened, or used by the application.
