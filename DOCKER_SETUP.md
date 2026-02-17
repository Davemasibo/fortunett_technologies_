# Docker Setup Instructions for Fortunett Technologies

## Quick Start

### Prerequisites
- Docker Desktop (or Docker Engine)
- Docker Compose

### Step 1: Environment Configuration
Copy and configure your environment file:

```bash
cp .env.example .env
```

Edit `.env` with your settings:
- Database credentials (or use defaults)
- Gmail SMTP credentials for email notifications
- M-Pesa API keys (if needed)

### Step 2: Start Services
Build and start all services:

```bash
docker compose up -d
```

This will:
- Build the PHP/Apache application image
- Start MySQL database container
- Start Apache web server container
- Initialize the database schema (from `sql/` directory if .sql files exist)

### Step 3: Verify Services
Check container status:

```bash
docker compose ps
```

View application logs:

```bash
docker compose logs -f app
```

View database logs:

```bash
docker compose logs -f mysql
```

### Step 4: Access Application
Open your browser and navigate to:
```
http://localhost
```

If you changed `APP_PORT` in `.env`, use that port instead.

---

## Development Workflow

### Live Code Changes
The compose file maps your entire project directory as a volume. Any PHP/HTML/CSS changes are instantly reflected without restarting containers.

### Database Access
Connect to MySQL directly:

```bash
docker compose exec mysql mysql -u fortunett -p
```

Enter password: `fortunett_pass` (or your configured `DB_PASS`)

Or use MySQL client from your local machine:
```bash
mysql -h localhost -u fortunett -p fortunett_technologies
```

### Running PHP Commands
Execute PHP scripts or Composer commands in the running container:

```bash
docker compose exec app php -r "phpinfo();"
docker compose exec app composer install
docker compose exec app composer format
docker compose exec app composer lint:php
```

### Viewing Container Logs
For application errors:
```bash
docker compose logs app
```

For database errors:
```bash
docker compose logs mysql
```

---

## Stopping Services

Gracefully stop containers:
```bash
docker compose stop
```

Remove containers but keep volumes (database data persists):
```bash
docker compose down
```

Remove everything including volumes (WARNING: deletes database):
```bash
docker compose down -v
```

---

## Production Deployment

### Build for Production
```bash
docker build -t fortunett_app:production .
```

### Push to Registry
```bash
docker tag fortunett_app:production your-registry.com/fortunett:latest
docker push your-registry.com/fortunett:latest
```

### Deploy with Docker Compose
Update the compose file image reference and deploy:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

---

## File Structure

- **Dockerfile**: Multi-stage build with PHP 8.2 + Apache
- **docker-compose.yml**: Local development environment
- **.docker/apache.conf**: Apache configuration with security headers
- **.dockerignore**: Files excluded from Docker build context
- **.env.example**: Environment template (copy to `.env`)

---

## Best Practices Applied

✅ **Multi-stage builds**: Composer dependencies installed in builder stage, not in final image
✅ **Health checks**: Both services have health checks for orchestration
✅ **Environment variables**: All configuration externalized to `.env`
✅ **Volume mounts**: Source code synced for development
✅ **Security headers**: Apache configured with security best practices
✅ **Permissions**: Proper file ownership and permissions set
✅ **Performance**: Apache MaxRequestWorkers optimized
✅ **Compression**: Gzip compression enabled for static assets
✅ **Caching**: Cache control headers for CSS, JS, images

---

## Troubleshooting

### Port 80 Already in Use
Change `APP_PORT` in `.env` to an available port:
```env
APP_PORT=8080
```

### Database Connection Failed
Check MySQL container:
```bash
docker compose logs mysql
```

Verify `DB_HOST` in `.env` is set to `mysql` (container name).

### Permission Denied Errors
Rebuild with proper permissions:
```bash
docker compose down
docker compose up -d --build
```

### Out of Disk Space
Clean up Docker resources:
```bash
docker system prune -a
```

---

For additional Docker resources:
- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/reference/)
- [Dockerfile Reference](https://docs.docker.com/reference/dockerfile/)

Sources: https://docs.docker.com/, https://docs.docker.com/compose/, https://docs.docker.com/reference/dockerfile/
