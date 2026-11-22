-- Estensioni richieste dal geocoder.
-- Va eseguito da un utente con i privilegi per creare estensioni.
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;  -- levenshtein(), per i refusi

CREATE SCHEMA IF NOT EXISTS geocoder;
CREATE SCHEMA IF NOT EXISTS staging;
