-- Explicit schema-only upgrade; replace pre_ with reviewed prefix. No backfill.
CREATE TABLE pre_funds_snapshot (
 event_key varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload mediumtext NOT NULL,
 addtime datetime NOT NULL,
 PRIMARY KEY(event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
