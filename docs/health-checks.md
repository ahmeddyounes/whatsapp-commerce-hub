# Health Check System

## Overview

The WhatsApp Commerce Hub health check system provides comprehensive monitoring and observability for production deployments. Health checks are modular, composable, and designed to work in minimal contexts without requiring unrelated subsystems to be fully initialized.

## Architecture

### Design Principles

1. **Modularity**: Each health check is independent and registered as a callable
2. **Composability**: Custom health checks can be registered at runtime
3. **Minimal Dependencies**: Checks handle missing dependencies gracefully
4. **Non-Blocking**: Checks don't require booting unrelated subsystems
5. **Discoverable**: Available checks can be listed via API

### Core Components

- **HealthCheck Service** (`includes/Monitoring/HealthCheck.php`): Main health check orchestrator
- **MonitoringServiceProvider** (`includes/Providers/MonitoringServiceProvider.php`): Registers health check endpoints
- **JobMonitor** (`includes/Queue/JobMonitor.php`): Queue health metrics (optional)
- **CircuitBreakerRegistry** (`includes/Resilience/CircuitBreakerRegistry.php`): Circuit breaker health (optional)

## REST API Endpoints

### 1. Full Health Check

**Endpoint**: `GET /wp-json/wch/v1/health`
**Authentication**: Required (`manage_woocommerce` capability)
**Purpose**: Comprehensive system health check with all components

**Response**:
```json
{
  "status": "healthy",
  "timestamp": "2026-01-18T12:00:00+00:00",
  "version": "3.0.0",
  "checks": {
    "database": {
      "status": "healthy",
      "latency": 2.45,
      "duration_ms": 2.62
    },
    "woocommerce": {
      "status": "healthy",
      "version": "8.5.0",
      "duration_ms": 0.12
    },
    "action_scheduler": {
      "status": "healthy",
      "pending": 125,
      "failed": 3,
      "duration_ms": 5.23
    },
    "queue": {
      "status": "healthy",
      "pending": 45,
      "throughput": 12.5,
      "duration_ms": 8.91
    },
    "circuit_breakers": {
      "status": "healthy",
      "open": 0,
      "total": 3,
      "duration_ms": 1.45
    },
    "disk": {
      "status": "healthy",
      "free_percent": 67.5,
      "free_gb": 125.34,
      "duration_ms": 0.89
    },
    "memory": {
      "status": "healthy",
      "limit": "256M",
      "usage_percent": 45.2,
      "usage_mb": 115.84,
      "peak_mb": 128.92,
      "duration_ms": 0.05
    }
  }
}
```

### 2. Liveness Probe

**Endpoint**: `GET /wp-json/wch/v1/health/live`
**Authentication**: Public (no authentication required)
**Purpose**: Simple liveness check for load balancers

**Response**:
```json
{
  "status": "ok",
  "time": 1737206400
}
```

**Use Case**: Container orchestration systems (Kubernetes, Docker Swarm) to determine if the application is alive and should receive traffic.

### 3. Readiness Probe

**Endpoint**: `GET /wp-json/wch/v1/health/ready`
**Authentication**: Public (no authentication required)
**Purpose**: Determine if application is ready to serve requests

**Response** (ready):
```json
{
  "ready": true,
  "database": "healthy",
  "woocommerce": "healthy"
}
```

**Response** (not ready - HTTP 503):
```json
{
  "ready": false,
  "database": "unhealthy",
  "woocommerce": "healthy"
}
```

**Use Case**: Load balancers to determine if the instance should receive traffic during startup or after maintenance.

### 4. List Available Checks

**Endpoint**: `GET /wp-json/wch/v1/health/checks`
**Authentication**: Public (no authentication required)
**Purpose**: Discover all registered health checks

**Response**:
```json
{
  "checks": [
    {
      "name": "database",
      "description": "Database connectivity and query latency",
      "category": "infrastructure"
    },
    {
      "name": "woocommerce",
      "description": "WooCommerce plugin availability and version",
      "category": "dependencies"
    },
    {
      "name": "action_scheduler",
      "description": "Action Scheduler queue status and pending jobs",
      "category": "background_jobs"
    },
    {
      "name": "queue",
      "description": "Job queue health, throughput, and dead letter queue",
      "category": "background_jobs"
    },
    {
      "name": "circuit_breakers",
      "description": "Circuit breaker states for external services",
      "category": "resilience"
    },
    {
      "name": "disk",
      "description": "Disk space availability in upload directory",
      "category": "infrastructure"
    },
    {
      "name": "memory",
      "description": "PHP memory usage and limits",
      "category": "infrastructure"
    }
  ],
  "count": 7
}
```

### 5. Individual Component Check

**Endpoint**: `GET /wp-json/wch/v1/health/{component}`
**Authentication**: Required (`manage_woocommerce` capability)
**Purpose**: Check a specific component

**Example**: `GET /wp-json/wch/v1/health/database`

**Response**:
```json
{
  "status": "healthy",
  "latency": 2.45
}
```

**Error Response** (component not found - HTTP 404):
```json
{
  "error": "Component not found"
}
```

## Health Check Components

### 1. Database Check

**Name**: `database`
**Category**: Infrastructure
**Dependencies**: WordPress database (`$wpdb`)

**Checks**:
- Database connectivity via `SELECT 1` query
- Query latency measurement

**Status Levels**:
- `healthy`: Database is accessible
- `unhealthy`: Database query failed

**Response Fields**:
- `status`: Health status
- `latency`: Query latency in milliseconds

### 2. WooCommerce Check

**Name**: `woocommerce`
**Category**: Dependencies
**Dependencies**: WooCommerce plugin

**Checks**:
- WooCommerce class availability
- Version detection

**Status Levels**:
- `healthy`: WooCommerce is loaded
- `unhealthy`: WooCommerce is not available

**Response Fields**:
- `status`: Health status
- `version`: WooCommerce version or 'unknown'

### 3. Action Scheduler Check

**Name**: `action_scheduler`
**Category**: Background Jobs
**Dependencies**: Action Scheduler library

**Checks**:
- Action Scheduler function availability
- Pending job count
- Failed job count

**Status Levels**:
- `healthy`: Normal operation (< 1000 pending, < 100 failed)
- `warning`: High pending jobs (> 1000)
- `degraded`: High failed jobs (> 100)
- `unavailable`: Action Scheduler not loaded

**Response Fields**:
- `status`: Health status
- `pending`: Number of pending jobs
- `failed`: Number of failed jobs

### 4. Queue Check (Optional)

**Name**: `queue`
**Category**: Background Jobs
**Dependencies**: `JobMonitor` service (optional)

**Checks**:
- Priority queue statistics
- Job throughput metrics
- Dead letter queue status

**Status Levels**:
- `healthy`: Normal operation
- `warning`: Alert thresholds exceeded
- `unavailable`: Queue monitoring not enabled
- `error`: Exception during check

**Response Fields**:
- `status`: Health status
- `pending`: Total pending jobs
- `throughput`: Jobs per minute
- `message`: Error message if unavailable

**Minimal Context**: This check returns `unavailable` if the queue monitoring system is not initialized, allowing health checks to work in minimal contexts.

### 5. Circuit Breakers Check (Optional)

**Name**: `circuit_breakers`
**Category**: Resilience
**Dependencies**: `CircuitBreakerRegistry` service (optional)

**Checks**:
- Number of open circuit breakers
- Total registered circuits
- Overall resilience health

**Status Levels**:
- `healthy`: All circuits closed or half-open
- `degraded`: Some circuits open
- `critical`: Multiple critical circuits open
- `unavailable`: Circuit breaker system not enabled
- `error`: Exception during check

**Response Fields**:
- `status`: Health status
- `open`: Number of open circuits
- `total`: Total registered circuits
- `message`: Error message if unavailable

**Minimal Context**: This check returns `unavailable` if the circuit breaker system is not initialized.

### 6. Disk Space Check

**Name**: `disk`
**Category**: Infrastructure
**Dependencies**: WordPress upload directory

**Checks**:
- Free disk space in upload directory
- Percentage of free space

**Status Levels**:
- `healthy`: > 20% free space
- `warning`: 5-20% free space
- `critical`: < 5% free space
- `error`: Directory not accessible

**Response Fields**:
- `status`: Health status
- `free_percent`: Percentage of free space
- `free_gb`: Free space in gigabytes

### 7. Memory Check

**Name**: `memory`
**Category**: Infrastructure
**Dependencies**: PHP runtime

**Checks**:
- Current memory usage
- Memory limit
- Peak memory usage

**Status Levels**:
- `healthy`: < 70% memory usage
- `warning`: 70-90% memory usage
- `critical`: > 90% memory usage

**Response Fields**:
- `status`: Health status
- `limit`: PHP memory limit
- `usage_percent`: Current memory usage percentage
- `usage_mb`: Current memory usage in MB
- `peak_mb`: Peak memory usage in MB

## Status Levels

Health checks use a hierarchical status system:

| Status | Priority | Description |
|--------|----------|-------------|
| `healthy` | 0 | Component is functioning normally |
| `unavailable` | 0 | Optional component not enabled (not an error) |
| `unknown` | 1 | Status could not be determined |
| `warning` | 2 | Component is functional but approaching limits |
| `degraded` | 3 | Component is functional but performing below normal |
| `unhealthy` | 4 | Component is not functioning |
| `critical` | 5 | Component is in critical state |
| `error` | 6 | Exception occurred during check |

**Overall Status Calculation**:
- The overall status is the highest priority status among all checks
- `unavailable` status is ignored in overall calculation (optional features)
- A single `error` check will make the overall status `error`

## Extensibility

### Registering Custom Health Checks

You can register custom health checks using the `HealthCheck::register()` method:

```php
use WhatsAppCommerceHub\Monitoring\HealthCheck;

add_action('init', function() {
    $health = wch_container()->get(HealthCheck::class);

    $health->register('redis', function(): array {
        $redis = new Redis();
        $connected = $redis->connect('127.0.0.1', 6379);

        return [
            'status' => $connected ? 'healthy' : 'unhealthy',
            'host' => '127.0.0.1:6379',
        ];
    });
});
```

### Custom Check Categories

When registering custom checks, they are automatically categorized as `custom`. To add custom category descriptions, you can filter the health check results.

## Permissions

### Endpoint Permissions

| Endpoint | Permission | Capability |
|----------|-----------|------------|
| `/health` | Authenticated | `manage_woocommerce` |
| `/health/live` | Public | None |
| `/health/ready` | Public | None |
| `/health/checks` | Public | None |
| `/health/{component}` | Authenticated | `manage_woocommerce` |

### Security Considerations

1. **Public Endpoints**: Liveness, readiness, and checks list are public to support load balancers and monitoring tools
2. **Authenticated Endpoints**: Full health check and individual component checks require `manage_woocommerce` capability to prevent information disclosure
3. **Information Leakage**: Health checks avoid exposing sensitive information like database credentials or API keys

## WordPress Dashboard Integration

### Health Widget

The monitoring system adds a dashboard widget to the WordPress admin dashboard:

**Widget**: "WhatsApp Commerce Hub - System Health"
**Capability**: `manage_woocommerce`
**Location**: WordPress Dashboard (`/wp-admin/`)

**Features**:
- Overall system status with color coding
- List of all components with checkmarks/crosses
- Link to full health report API endpoint

**Status Colors**:
- Green (`success`): `healthy` status
- Yellow (`warning`): `warning` or `degraded` status
- Red (`error`): `unhealthy`, `critical`, or `error` status

## Monitoring and Alerting

### Prometheus Integration

The health check system complements Prometheus metrics exported by the queue system. For queue-specific metrics, see `JobMonitor::exportPrometheusMetrics()`.

### Recommended Monitoring Setup

1. **Liveness Probe**: Configure load balancer to check `/health/live` every 10 seconds
2. **Readiness Probe**: Configure load balancer to check `/health/ready` every 5 seconds
3. **Full Health Check**: Monitor `/health` endpoint every 60 seconds for detailed status
4. **Alerting**: Set up alerts based on status levels:
   - `warning`: Low-priority alert
   - `degraded`: Medium-priority alert
   - `unhealthy`, `critical`, `error`: High-priority alert

### Example Kubernetes Configuration

```yaml
livenessProbe:
  httpGet:
    path: /wp-json/wch/v1/health/live
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 10
  timeoutSeconds: 5
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /wp-json/wch/v1/health/ready
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 3
```

## Troubleshooting

### Common Issues

**Issue**: Health check returns 404
- **Cause**: WordPress permalinks not configured or REST API disabled
- **Solution**: Visit Settings → Permalinks and save to flush rewrite rules

**Issue**: Health check returns 403 Forbidden
- **Cause**: Insufficient permissions for authenticated endpoints
- **Solution**: Ensure user has `manage_woocommerce` capability

**Issue**: Queue check shows "unavailable"
- **Cause**: Queue monitoring system not initialized (this is normal in minimal contexts)
- **Solution**: This is expected behavior when queue system is not enabled

**Issue**: Circuit breakers check shows "unavailable"
- **Cause**: Resilience system not initialized (this is normal in minimal contexts)
- **Solution**: This is expected behavior when circuit breaker system is not enabled

**Issue**: Database check shows high latency
- **Cause**: Database performance issues or network latency
- **Solution**: Investigate database server performance, check for slow queries

**Issue**: Memory check shows "critical"
- **Cause**: PHP memory usage > 90% of limit
- **Solution**: Increase PHP memory limit or investigate memory leaks

## Best Practices

1. **Regular Monitoring**: Check health endpoints regularly in production
2. **Alert Configuration**: Set up alerts for degraded and critical statuses
3. **Load Balancer Integration**: Use liveness and readiness probes for zero-downtime deployments
4. **Custom Checks**: Add custom health checks for critical dependencies
5. **Minimal Context**: Health checks work without full system initialization
6. **Graceful Degradation**: Optional components show `unavailable` instead of failing

## API Examples

### cURL Examples

```bash
# Full health check (requires authentication)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://example.com/wp-json/wch/v1/health

# Liveness probe
curl https://example.com/wp-json/wch/v1/health/live

# Readiness probe
curl https://example.com/wp-json/wch/v1/health/ready

# List available checks
curl https://example.com/wp-json/wch/v1/health/checks

# Check specific component (requires authentication)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://example.com/wp-json/wch/v1/health/database
```

### PHP Examples

```php
// Get health check service
$health = wch_container()->get(\WhatsAppCommerceHub\Monitoring\HealthCheck::class);

// Run all checks
$status = $health->check();
echo "Overall status: " . $status['status'];

// Check single component
$db_status = $health->checkOne('database');
echo "Database: " . $db_status['status'];

// Liveness probe
$liveness = $health->liveness();

// Readiness probe
$readiness = $health->readiness();

// List available checks
$checks = $health->getAvailableChecks();
foreach ($checks as $check) {
    echo "{$check['name']}: {$check['description']}\n";
}
```

## Related Documentation

- [Module Map](module-map.md) - Complete module overview
- [Queue System](../includes/Queue/README.md) - Job queue documentation
- [Circuit Breakers](../includes/Resilience/README.md) - Resilience patterns
