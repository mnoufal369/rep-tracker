-- Separate database for `composer test:mysql` (partitioning tests).
CREATE DATABASE IF NOT EXISTS rep_tracking_test;
GRANT ALL PRIVILEGES ON rep_tracking_test.* TO 'tracking'@'%';
