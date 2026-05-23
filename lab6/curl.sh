#!/bin/bash
# curl.sh — Load test script for Auto Scaling lab
# Usage: ./curl.sh <ALB_DNS> [threads] [duration_seconds]
#
# Examples:
#   ./curl.sh project-alb-xxx.eu-central-1.elb.amazonaws.com
#   ./curl.sh project-alb-xxx.eu-central-1.elb.amazonaws.com 10 120
#   ./curl.sh project-alb-xxx.eu-central-1.elb.amazonaws.com 20 180 --ab

ALB_DNS="${1:-}"
THREADS="${2:-5}"
DURATION="${3:-60}"
MODE="${4:-curl}"

if [ -z "$ALB_DNS" ]; then
    echo "Usage: $0 <ALB_DNS> [threads] [duration_seconds] [--ab]"
    echo "Example: $0 project-alb-xxx.eu-central-1.elb.amazonaws.com 10 120"
    exit 1
fi

URL="http://${ALB_DNS}/load?seconds=${DURATION}"
echo "============================================="
echo "  AWS Lab 6 — Load Test"
echo "============================================="
echo "  Target:   $URL"
echo "  Threads:  $THREADS"
echo "  Duration: ${DURATION}s"
echo "  Mode:     $MODE"
echo "============================================="
echo ""

# Use Apache Benchmark if --ab flag provided and ab is installed
if [ "$MODE" = "--ab" ] && command -v ab &> /dev/null; then
    echo "Using Apache Benchmark (ab)..."
    ab -n 100000 -c "$THREADS" -t "$DURATION" "$URL"
    exit 0
fi

# Use hey if available
if [ "$MODE" = "--hey" ] && command -v hey &> /dev/null; then
    echo "Using hey..."
    hey -n 100000 -c "$THREADS" -z "${DURATION}s" "$URL"
    exit 0
fi

# Default: parallel curl loops
echo "Starting $THREADS parallel curl workers for ${DURATION}s..."
echo "Press Ctrl+C to stop."
echo ""

END_TIME=$(($(date +%s) + DURATION + 30))

worker() {
    local id=$1
    local count=0
    while [ $(date +%s) -lt $END_TIME ]; do
        STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 90 "$URL")
        count=$((count + 1))
        echo "[Worker $id] Request $count — HTTP $STATUS"
    done
    echo "[Worker $id] Done — $count requests sent"
}

# Launch workers in background
PIDS=()
for i in $(seq 1 "$THREADS"); do
    worker "$i" &
    PIDS+=($!)
    sleep 0.2
done

echo "All $THREADS workers started (PIDs: ${PIDS[*]})"
echo "Waiting for completion..."

# Wait for all workers
for pid in "${PIDS[@]}"; do
    wait "$pid"
done

echo ""
echo "Load test completed!"
