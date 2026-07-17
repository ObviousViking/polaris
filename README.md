# Polaris

A case management system for a digital forensics lab. Runs in Docker only.

```bash
docker compose up -d
```

Then visit `http://localhost:8080/` (change the port in `docker-compose.yml` if needed). First
visit walks you through: superuser creation + confirming the data storage path, then straight
into the dashboard.

**Change the placeholder passwords in `docker-compose.yml` before running this anywhere reachable
off your own machine.**

## Locked out?

```bash
docker exec -it polaris_app php bin/reset_password.php user@example.com 'NewPassword123!'
```

Resets that user's password directly in the database. CLI-only - not reachable over HTTP.
