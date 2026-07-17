-- User Profile achievements panel - see includes/achievements.php.
--
-- achievements is the fixed catalog, kept in sync with
-- ACHIEVEMENT_DEFINITIONS by sync_achievement_catalog() on every request.
-- user_achievements records who unlocked what, and when - the ONLY writer
-- is check_and_unlock_achievements(), which always recomputes the real
-- metric count server-side rather than trusting any caller-supplied value,
-- so there's no path (client-facing or otherwise) that can unlock one early.
--
-- users.created_at backs the tenure ("One Year On") metric - existing users
-- get NOW() as a reasonable default since their real creation date was
-- never tracked before this.

CREATE TABLE IF NOT EXISTS `achievements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `achievement_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `icon` varchar(16) NOT NULL,
  `metric` varchar(50) NOT NULL,
  `threshold` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `achievement_key` (`achievement_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `user_achievements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `achievement_id` int NOT NULL,
  `unlocked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_achievement` (`user_id`,`achievement_id`),
  KEY `achievement_id` (`achievement_id`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE users
  ADD COLUMN created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;
