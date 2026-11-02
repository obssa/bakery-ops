-- Runs once when the db volume is created: a separate database for Pest so tests never touch dev data.
CREATE DATABASE IF NOT EXISTS bakery_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON bakery_test.* TO 'bakery'@'%';
-- Step 5.5b: the scratch copy of the ops contracts (tests/Support/ScratchCopy.php) and the restore drill's database (backup:drill with
-- BACKUP_DRILL_DATABASE=bakery_drill in the backup-drill CI job). An existing volume does not run this file again: create them by hand there.
CREATE DATABASE IF NOT EXISTS bakery_scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS bakery_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON bakery_scratch.* TO 'bakery'@'%';
GRANT ALL PRIVILEGES ON bakery_drill.* TO 'bakery'@'%';
FLUSH PRIVILEGES;
