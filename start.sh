#!/bin/bash
# Simple script to start the PHP development server

PORT=${1:-8000}

echo "Starting BBCC Website on http://localhost:$PORT"
echo "Press Ctrl+C to stop the server"
echo ""

php -S localhost:$PORT
