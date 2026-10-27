-- Historical spellings (legacy_text) are searchable, so index them.
ALTER TABLE chroma_sell_numbers ADD INDEX IF NOT EXISTS ix_sell_legacy_text (legacy_text);
