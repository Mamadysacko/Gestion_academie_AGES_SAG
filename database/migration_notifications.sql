CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED   NOT NULL,
  type       ENUM('info','alerte_note','alerte_presence','alerte_evaluation','bulletin') NOT NULL DEFAULT 'info',
  titre      VARCHAR(200)   NOT NULL,
  message    TEXT           NOT NULL,
  lu         TINYINT(1)     NOT NULL DEFAULT 0,
  created_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_lu (user_id, lu),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
