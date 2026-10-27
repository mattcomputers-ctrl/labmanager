-- Projects module: the projects list searches item descriptions too
-- ("Pantone 300" finds the project that holds that color).
CREATE FULLTEXT INDEX IF NOT EXISTS ft_items_desc ON chroma_project_items (description);
