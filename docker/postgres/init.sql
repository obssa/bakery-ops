-- Runs once when the db volume is created: a separate database for Pest so tests never touch dev data.
CREATE DATABASE bakery_test OWNER bakery;
