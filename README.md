# Polaris

A case management system for a digital forensics lab. Runs in Docker only.

## Quick Start

Create a folder, then add these two files inside it.

**`docker-compose.yml`**

```yaml
version: "3.8"

services:
  db:
    image: mysql:8.0
    container_name: polaris_db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: change_me_root
      MYSQL_DATABASE: polaris
      MYSQL_USER: polaris_app
      MYSQL_PASSWORD: change_me_app
    ports:
      - "3306:3306"
    volumes:
      - polaris_db_data:/var/lib/mysql
    command: --default-authentication-plugin=mysql_native_password --log_bin_trust_function_creators=1
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-pchange_me_root"]
      interval: 5s
      timeout: 5s
      retries: 10

  app:
    image: obviousviking/polaris:latest
    container_name: polaris_app
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      DB_HOST: db
      DB_PORT: "3306"
      DB_USER: polaris_app
      DB_PASS: change_me_app
      DB_NAME: polaris
      DATA_HOST_PATH: ${POLARIS_DATA_PATH:-./polaris_data}
      HISTORY_HMAC_KEY: ${HISTORY_HMAC_KEY:-change_me_hmac_key}
    ports:
      - "8080:80"
    volumes:
      - ${POLARIS_DATA_PATH:-./polaris_data}:/var/www/polaris-data

volumes:
  polaris_db_data:
```

**`.env`**

```bash
POLARIS_DATA_PATH=./polaris_data
HISTORY_HMAC_KEY=change_me_hmac_key
```

Generate a real value for `HISTORY_HMAC_KEY` (used to tamper-seal the case/exhibit history log) instead of leaving the placeholder:

```bash
openssl rand -hex 32
```

Then also change `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`, and `DB_PASS` in `docker-compose.yml` to match each other and to something that isn't a placeholder, and run:

```bash
docker compose up -d
```

Visit `http://localhost:8080/` (change the `8080:80` port mapping above if that's taken). First visit walks you through superuser creation and confirming the data storage path, then straight into the dashboard.

**Change every placeholder password/key above before running this anywhere reachable off your own machine.**

## Locked out?

```bash
docker exec -it polaris_app php bin/reset_password.php user@example.com 'NewPassword123!'
```

Resets that user's password directly in the database. CLI-only - not reachable over HTTP.
