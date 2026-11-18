-- Migration : colonne first_login dans users
-- Compatible MySQL 5.7 et MySQL 8+
-- Authorization::currentUser() sélectionne déjà u.first_login dans sa requête.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'first_login'
);

-- Exécuter seulement si la colonne n'existe pas
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN first_login TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1 -- colonne first_login déjà présente'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
