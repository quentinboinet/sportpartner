CREATE DATABASE IF NOT EXISTS sport_health CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sport_health_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sport_health.* TO 'app'@'%';
GRANT ALL PRIVILEGES ON sport_health_test.* TO 'app'@'%';
FLUSH PRIVILEGES;
