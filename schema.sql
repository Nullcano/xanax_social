-- Xanax Social – full schema for fresh installs.
-- Import into an empty database (create/select it first).
-- Already running v1? Use migrate.sql instead.
SET NAMES utf8mb4;


CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  bio VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(80) NOT NULL DEFAULT '',
  location VARCHAR(60) NOT NULL DEFAULT '',
  website VARCHAR(120) NOT NULL DEFAULT '',
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  coins INT UNSIGNED NOT NULL DEFAULT 0,
  streak INT UNSIGNED NOT NULL DEFAULT 0,
  best_streak INT UNSIGNED NOT NULL DEFAULT 0,
  last_daily DATE NULL,
  last_seen DATETIME NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE follows (
  follower_id INT UNSIGNED NOT NULL,
  followed_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, followed_id),
  FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL DEFAULT '',
  icon VARCHAR(16) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, category_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT NOT NULL,
  tag VARCHAR(20) NOT NULL DEFAULT 'Diskusjon',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  locked TINYINT(1) NOT NULL DEFAULT 0,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  reply_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at DATETIME NULL,
  last_post_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (category_id, last_post_at),
  INDEX (user_id),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at DATETIME NULL,
  INDEX (thread_id, created_at),
  INDEX (user_id),
  FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE likes (
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('thread','post') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  awarded TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, kind, target_id),
  INDEX (kind, target_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL,
  actor_id INT UNSIGNED NULL,
  thread_id INT UNSIGNED NULL,
  text VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, is_read, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_achievements (
  user_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE xp_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  xp INT UNSIGNED NOT NULL,
  reason VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at),
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE news (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT NOT NULL,
  tag VARCHAR(20) NOT NULL DEFAULT 'Kunngjøring',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  emoji VARCHAR(16) NOT NULL,
  cost INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE user_gifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_user INT UNSIGNED NOT NULL,
  to_user INT UNSIGNED NOT NULL,
  gift_id INT UNSIGNED NOT NULL,
  message VARCHAR(140) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (to_user),
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO categories (name, description, icon, sort_order) VALUES
('Presentasjoner', 'Ny her? Si hei og fortell litt om deg selv', '👋', 1),
('Generelt', 'Alt mellom himmel og jord', '💬', 2),
('Musikk og lyd', 'Lofi, vinyl, ambient og alt du har på øret', '🎧', 3),
('Natt og søvn', 'For nattuglene og alle som vil sove bedre', '🌙', 4),
('Teknologi og koding', 'Kode, gadgets, selvhosting og nettsteder', '💻', 5),
('Kreativt', 'Design, foto, tegning og skriving', '🎨', 6),
('Spill og film', 'Det du spiller og ser akkurat nå', '🎮', 7),
('Hobby og fritid', 'Det du driver med når du ikke jobber', '🧶', 8),
('Mental helse og støtte', 'Et trygt sted for å dele og støtte hverandre', '🫶', 9),
('Spørsmål og hjelp', 'Spør om plattformen eller be om råd', '❓', 10);

INSERT INTO gifts (name, emoji, cost) VALUES
('Kaffe', '☕', 5), ('Te', '🍵', 5), ('Hjerte', '❤️', 10), ('Kake', '🍰', 15),
('Stjerne', '⭐', 20), ('Blomst', '🌸', 25), ('Pokal', '🏆', 50), ('Diamant', '💎', 75),
('Rakett', '🚀', 100), ('Krone', '👑', 250);
