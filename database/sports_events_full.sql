-- =============================================================================
-- SportSync — Single consolidated schema (all tables used by the current build)
-- =============================================================================
-- FRESH INSTALLS: import only this file.
--   mysql -u root -p < database/sports_events_full.sql
--
-- This is a convenience consolidation of the tables the application actually
-- reads and writes today. Maintenance still happens through config/schema.php,
-- which adds missing columns and migrates older installs in place.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS sports_event_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sports_event_system;

SET FOREIGN_KEY_CHECKS=0;

-- Drop legacy tables from older SportSync imports so a re-import stays clean.
DROP TABLE IF EXISTS password_reset_tokens,likes,comments,voice_messages,notifications,
  sms_logs,weather_alerts,sales,order_items,cart_items,carts,merchandise,inventory_transactions,
  inventory,equipment_assignments,event_equipment_reservations,registrations,participants,
  venue_bookings,teams,matches,system_settings;

-- Drop current consolidated tables so the single-file import is idempotent.
DROP TABLE IF EXISTS post_interactions,community_posts,messages,announcements,orders,items,
  event_equipment_reservations,equipment,event_registrations,events,venues,users,roles,tournaments,
  activity_logs,gcash_payouts,announcement_comments;

SET FOREIGN_KEY_CHECKS=1;

-- 1. roles -----------------------------------------------------------------
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. users (absorbs password_reset_tokens) ---------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  clerk_id VARCHAR(64) NULL UNIQUE,
  phone VARCHAR(40),
  address VARCHAR(255) NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role_id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. venues ----------------------------------------------------------------
CREATE TABLE venues (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255),
  latitude DECIMAL(10,7),
  longitude DECIMAL(10,7),
  capacity INT DEFAULT 0,
  facilities TEXT,
  layout_notes TEXT,
  image_path VARCHAR(255) NULL,
  status ENUM('available','maintenance','inactive') DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. events (absorbs venue_bookings) ---------------------------------------
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  description TEXT,
  event_type VARCHAR(80),
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  venue_id INT NULL,
  organizer_id INT NULL,
  status ENUM('draft','scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
  is_outdoor TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_organizer_schedule (organizer_id, status, start_at),
  INDEX idx_events_venue_schedule (venue_id, status, start_at, end_at),
  FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
  FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. event_registrations (absorbs participants + registrations) ------------
CREATE TABLE event_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150),
  phone VARCHAR(40),
  team VARCHAR(100),
  category VARCHAR(80),
  emergency_contact VARCHAR(160),
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_registration (user_id, event_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. equipment -------------------------------------------------------------
CREATE TABLE equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  category VARCHAR(80),
  quantity INT DEFAULT 1,
  condition_status ENUM('excellent','good','fair','repair') DEFAULT 'good',
  current_location VARCHAR(120),
  status ENUM('available','checked_out','maintenance') DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. event_equipment_reservations (absorbs equipment_assignments) ----------
CREATE TABLE event_equipment_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  equipment_id INT NOT NULL,
  user_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status ENUM('reserved','released','cancelled') NOT NULL DEFAULT 'reserved',
  notes VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eer_event (event_id),
  INDEX idx_eer_equipment_time (equipment_id, start_at, end_at, status),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. items (absorbs merchandise + inventory) --------------------------------
CREATE TABLE items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('supply','product') NOT NULL DEFAULT 'product',
  name VARCHAR(140) NOT NULL,
  category VARCHAR(80),
  description TEXT,
  price DECIMAL(12,2) NULL,
  quantity INT DEFAULT 0,
  min_stock INT DEFAULT 0,
  max_stock INT DEFAULT 9999,
  unit VARCHAR(30) DEFAULT 'pcs',
  location VARCHAR(120),
  image_path VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_items_kind (kind, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. orders (absorbs carts, cart_items, order_items, sales) ---------------
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NULL,
  items_json TEXT NOT NULL,
  total_amount DECIMAL(12,2) DEFAULT 0,
  payment_method VARCHAR(60) DEFAULT 'Cash',
  payment_status ENUM('pending','paid','failed','cancelled') DEFAULT 'pending',
  payment_reference VARCHAR(120) NULL,
  payment_verified_at DATETIME NULL,
  status ENUM('pending','confirmed','ready','completed','cancelled') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_orders_user_created (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. announcements (absorbs weather_alerts) -------------------------------
CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  type ENUM('general','urgent','emergency') DEFAULT 'general',
  audience VARCHAR(80) DEFAULT 'all',
  event_id INT NULL,
  created_by INT,
  expires_at DATETIME NULL,
  sms_total INT NOT NULL DEFAULT 0,
  sms_sent INT NOT NULL DEFAULT 0,
  sms_failed INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. messages (absorbs notifications + voice_messages) -------------------
CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NULL,
  receiver_id INT NOT NULL,
  message_type ENUM('text','voice') NOT NULL DEFAULT 'text',
  subject VARCHAR(180),
  message TEXT NOT NULL,
  file_path VARCHAR(255) NULL,
  duration_seconds INT DEFAULT 0,
  is_read TINYINT(1) DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_message_receiver (receiver_id, is_read),
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. community_posts -----------------------------------------------------
CREATE TABLE community_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  post_type ENUM('community','event','tournament') NOT NULL DEFAULT 'community',
  event_id INT NULL,
  tournament_id INT NULL,
  content TEXT NOT NULL,
  image_path VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_community_created (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. post_interactions (absorbs comments + likes) ------------------------
CREATE TABLE post_interactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  type ENUM('comment','like') NOT NULL,
  content VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interaction (post_id, user_id, type),
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. tournaments (absorbs teams + matches via bracket_json) --------------
CREATE TABLE tournaments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  event_id INT NULL,
  format VARCHAR(60) DEFAULT 'Single Elimination',
  status ENUM('upcoming','ongoing','completed') DEFAULT 'upcoming',
  bracket_json TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. activity_logs (absorbs sms_logs) -------------------------------------
CREATE TABLE activity_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_created (created_at),
  INDEX idx_activity_user (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. gcash_payouts -------------------------------------------------------
CREATE TABLE gcash_payouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  checkout_session_id VARCHAR(80) NULL,
  reference_number VARCHAR(120) NULL,
  sender_mobile VARCHAR(32) NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('verifying','paid','failed') NOT NULL DEFAULT 'verifying',
  note VARCHAR(255) NULL,
  verified_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL,
  INDEX idx_gcash_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. announcement_comments ------------------------------------------------
CREATE TABLE announcement_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  user_id INT NOT NULL,
  content VARCHAR(500) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_announcement_comment (announcement_id, created_at),
  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- Seed data — demo accounts use the password: SportSync2026! (administrator:
-- Admin@123)
-- =============================================================================

INSERT INTO roles(name) VALUES ('Administrator'),('Event Organizer'),('Staff/Coordinator'),('Participant/Athlete'),('Spectator/Community Member');

INSERT INTO users(role_id,full_name,email,password_hash,phone,email_verified_at) VALUES
(1,'System Administrator','admin@sports.local','$2y$10$dmXzMRfZ8RM8sZaTnYETr.IntVL5bTZt1jJa2tzet7briMNteHpFG','09170000000',NOW()),
(2,'Alex Organizer','organizer@sports.local','$2y$10$ngFWfhcppmhC0i5on6aKtOy9bboQb96gtIq20bRFmLz/jgHP6wUpO','09170000001',NOW()),
(3,'Jamie Coordinator','staff@sports.local','$2y$10$ngFWfhcppmhC0i5on6aKtOy9bboQb96gtIq20bRFmLz/jgHP6wUpO','09170000002',NOW()),
(4,'Taylor Athlete','athlete@sports.local','$2y$10$ngFWfhcppmhC0i5on6aKtOy9bboQb96gtIq20bRFmLz/jgHP6wUpO','09170000003',NOW()),
(5,'Community Viewer','viewer@sports.local','$2y$10$ngFWfhcppmhC0i5on6aKtOy9bboQb96gtIq20bRFmLz/jgHP6wUpO','09170000004',NOW());

INSERT INTO venues(name,address,latitude,longitude,capacity,facilities) VALUES
('Tupi Municipal Gymnasium','Tupi Municipal Compound, Tupi, South Cotabato',6.3348000,124.9526000,3000,'Covered court, bleachers, comfort rooms, parking, first-aid area'),
('Tupi Community Sports Field','Tupi, South Cotabato',6.3319000,124.9563000,1500,'Open sports field, bleachers, lighting, event staging area'),
('Tupi Training & Activity Center','Tupi, South Cotabato',6.3371000,124.9498000,800,'Indoor activity area, changing rooms, equipment staging, parking');

INSERT INTO events(title,description,event_type,start_at,end_at,venue_id,organizer_id,status,is_outdoor) VALUES
('Inter-Barangay Basketball Finals','Championship game and awarding ceremony','Basketball',DATE_ADD(NOW(),INTERVAL 5 DAY),DATE_ADD(NOW(),INTERVAL 5 DAY)+INTERVAL 4 HOUR,1,2,'scheduled',0),
('Community Fun Run','5K community road race','Running',DATE_ADD(NOW(),INTERVAL 12 DAY),DATE_ADD(NOW(),INTERVAL 12 DAY)+INTERVAL 5 HOUR,2,2,'scheduled',1);

INSERT INTO items(kind,name,category,quantity,min_stock,max_stock,unit,location) VALUES
('supply','Basketballs','Sports Gear',18,10,40,'pcs','Equipment Room A'),
('supply','Bottled Water','Supplies',7,20,200,'cases','Storage B'),
('supply','First Aid Kits','Safety',5,5,15,'kits','Medical Desk'),
('supply','Traffic Cones','Logistics',85,20,60,'pcs','Storage A');

INSERT INTO items(kind,name,category,description,price,quantity,location) VALUES
('product','Official Event Jersey','Apparel','Dri-fit sports jersey',550.00,60,'Shop Room'),
('product','Sports Cap','Apparel','Adjustable embroidered cap',250.00,35,'Shop Room'),
('product','Community Tumbler','Merchandise','Insulated 500ml tumbler',320.00,40,'Shop Room');

INSERT INTO equipment(name,category,quantity,condition_status,current_location,status) VALUES
('Portable Scoreboard','Event Equipment',2,'excellent','Equipment Room A','available'),
('PA Speaker Set','Audio',4,'good','Storage C','available'),
('Timing Gate','Race Equipment',2,'good','Storage A','available');

INSERT INTO announcements(title,body,type,audience,created_by) VALUES
('Welcome to SportSync','All event logistics and announcements are now centralized here.','general','all',1),
('Hydration Reminder','Outdoor participants should bring water and arrive 30 minutes early.','urgent','participants',2);

INSERT INTO messages(sender_id,receiver_id,subject,message) VALUES
(NULL,2,'Welcome to SportSync','Your organizer account is ready. Explore events, venues, equipment, and communication tools.'),
(NULL,4,'Welcome to SportSync','Your athlete account is ready. Browse events and register online.');

INSERT INTO community_posts(user_id,content) VALUES
(2,'Welcome everyone! Check the calendar for upcoming community sports activities.'),
(4,'Excited for the next community event!');

INSERT INTO post_interactions(post_id,user_id,type,content) VALUES
(1,4,'comment','Looking forward to it!'),
(1,4,'like',NULL);

INSERT INTO tournaments(name,event_id,format,status,bracket_json) VALUES
('Barangay Basketball Cup',1,'Single Elimination','ongoing','{"teams":[{"name":"Blue Hawks","seed":1},{"name":"Red Lions","seed":2},{"name":"Green Titans","seed":3},{"name":"Gold Warriors","seed":4}],"matches":[{"round":"Semifinal","team1":"Blue Hawks","team2":"Gold Warriors","score1":null,"score2":null,"offset_hours":48,"status":"scheduled"},{"round":"Semifinal","team1":"Red Lions","team2":"Green Titans","score1":null,"score2":null,"offset_hours":72,"status":"scheduled"}]}');
