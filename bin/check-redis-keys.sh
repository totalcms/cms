#!/bin/bash

# Redis Cache Key Analysis Script
# Run this on your production server to analyze cache key patterns

echo "=== Redis Cache Key Analysis ==="
echo ""

# Overall stats
echo "Overall Statistics:"
redis-cli info stats | grep -E "keyspace_hits|keyspace_misses|expired_keys|evicted_keys"
echo ""

# Calculate hit rate
HITS=$(redis-cli info stats | grep keyspace_hits | cut -d: -f2 | tr -d '\r')
MISSES=$(redis-cli info stats | grep keyspace_misses | cut -d: -f2 | tr -d '\r')
TOTAL=$((HITS + MISSES))
if [ $TOTAL -gt 0 ]; then
    HIT_RATE=$(echo "scale=2; ($HITS * 100) / $TOTAL" | bc)
    echo "Hit Rate: ${HIT_RATE}%"
fi
echo ""

# Memory usage
echo "Memory Usage:"
redis-cli info memory | grep -E "used_memory_human|maxmemory_human|maxmemory_policy"
echo ""

# Key count by prefix
echo "Key Count by Prefix:"
redis-cli --scan --pattern "*" | cut -d: -f2 | sort | uniq -c | sort -rn | head -20
echo ""

# Total keys
echo "Total Keys:"
redis-cli dbsize
echo ""

# Sample keys
echo "Sample Keys (first 10):"
redis-cli --scan --pattern "*" | head -10
