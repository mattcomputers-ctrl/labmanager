-- Code lookup: an all-digit query ("6374") finds sell numbers with that
-- number under any prefix. The existing keys all start with prefix_id, so
-- that probe scanned the table; this index lets it hit the index instead.
ALTER TABLE chroma_sell_numbers ADD INDEX IF NOT EXISTS ix_sell_number (number);
