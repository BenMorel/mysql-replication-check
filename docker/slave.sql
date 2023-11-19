CREATE DATABASE test;
USE test;

CREATE TABLE in_sync (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE different_checksum (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);

INSERT INTO in_sync (name) VALUES ('a'), ('b'), ('c');
INSERT INTO different_checksum (name) VALUES ('a'), ('b'), ('x');
