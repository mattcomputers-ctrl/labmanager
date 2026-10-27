-- Admin → Customers: the search box matches any word of a customer's name ("labels" finds "ACME Labels, Inc.")
-- through a FULLTEXT index on the normalized name instead of an infix LIKE that would scan the whole table.
CREATE FULLTEXT INDEX IF NOT EXISTS ft_customers_name ON chroma_customers (name_normalized);
