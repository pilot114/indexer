CREATE TABLE indexed_pages (
   id SERIAL PRIMARY KEY,
   url TEXT UNIQUE,
   html TEXT
);

CREATE TABLE index_progress (
    id SERIAL PRIMARY KEY,
    parsed_count INT
);

CREATE TABLE visited_urls (
  id SERIAL PRIMARY KEY,
  url TEXT UNIQUE
);

CREATE TABLE urls_to_visit (
   id SERIAL PRIMARY KEY,
   url TEXT UNIQUE
);