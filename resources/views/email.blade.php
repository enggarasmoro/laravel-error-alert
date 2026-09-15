<!doctype html>
<html><body>
<h2>{{ $payload['service'] }} error alert</h2>
<p><strong>Environment:</strong> {{ $payload['environment'] }}</p>
<p><strong>Source:</strong> {{ $payload['source'] }}</p>
<p><strong>Status:</strong> {{ $payload['status'] }}</p>
<p><strong>Error code:</strong> {{ $payload['error_code'] ?: 'n/a' }}</p>
<p><strong>Type:</strong> {{ $payload['type'] }}</p>
<p><strong>Operation:</strong> {{ $payload['operation'] ?: 'n/a' }}</p>
<p><strong>Correlation ID:</strong> {{ $payload['correlation_id'] ?: 'n/a' }}</p>
<p><strong>Occurred at:</strong> {{ $payload['occurred_at'] }}</p>
<p>This alert contains sanitized metadata. Inspect application logs using the correlation ID.</p>
</body></html>
