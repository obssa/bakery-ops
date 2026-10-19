-- Runs once when the db volume is created: a separate database for Pest so tests never touch dev data.
CREATE DATABASE IF NOT EXISTS bakery_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON bakery_test.* TO 'bakery'@'%';
FLUSH PRIVILEGES;
