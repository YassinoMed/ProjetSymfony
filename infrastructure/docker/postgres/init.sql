-- Chaque service est propriétaire de sa base de données.
-- La base "core" est créée automatiquement par POSTGRES_DB.
CREATE DATABASE ai;
CREATE DATABASE generation;

\connect core
CREATE EXTENSION IF NOT EXISTS vector;

\connect ai
CREATE EXTENSION IF NOT EXISTS vector;

\connect generation
CREATE EXTENSION IF NOT EXISTS vector;
