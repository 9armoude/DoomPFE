#!/bin/bash
# Setup script for adding PHP pages to existing Nextcloud container

echo "Setting up PHP pages in Nextcloud..."

# Step 1: Clone the repository
echo "Cloning repository..."
git clone https://github.com/9armoude/Test-SFE.git /tmp/Test-SFE

# Step 2: Find Nextcloud container and copy files
CONTAINER_NAME="nextcloud_app"  # Update this if your container has a different name
NEXTCLOUD_WEB_ROOT="/var/www/html"

echo "Copying PHP files to Nextcloud container..."
# Create custom directory inside Nextcloud
sudo docker exec "$CONTAINER_NAME" mkdir -p "$NEXTCLOUD_WEB_ROOT/estc"

# Copy the PHP files from the host into the container
sudo docker cp /tmp/Test-SFE/. "$CONTAINER_NAME:$NEXTCLOUD_WEB_ROOT/estc/"

# Set correct permissions (www-data is the default web user for Nextcloud images)
sudo docker exec "$CONTAINER_NAME" chown -R www-data:www-data "$NEXTCLOUD_WEB_ROOT/estc"
sudo docker exec "$CONTAINER_NAME" chmod -R 755 "$NEXTCLOUD_WEB_ROOT/estc"

echo "Setup completed successfully!"
echo "You should be able to access it at: http://localhost:8080/estc"

# Cleanup
rm -rf /tmp/Test-SFE

echo "Done!"
