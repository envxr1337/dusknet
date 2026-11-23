-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1
-- Üretim Zamanı: 23 Kas 2026, 01:26:43
-- Sunucu sürümü: 10.4.32-MariaDB
-- PHP Sürümü: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `dusknet`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `message` varchar(400) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `chat_rooms`
--

CREATE TABLE `chat_rooms` (
  `id` int(10) UNSIGNED NOT NULL,
  `slug` varchar(32) NOT NULL,
  `name` varchar(40) NOT NULL,
  `topic` varchar(160) NOT NULL,
  `category` enum('general','music','games','tech','community') NOT NULL DEFAULT 'community',
  `is_official` tinyint(1) NOT NULL DEFAULT 0,
  `password_hash` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `chat_rooms`
--

INSERT INTO `chat_rooms` (`id`, `slug`, `name`, `topic`, `category`, `is_official`, `password_hash`, `created_by`, `created_at`) VALUES
(1, 'lounge', '#lounge', 'General daily chat & hangouts', 'general', 1, NULL, NULL, '2026-11-23 00:22:11'),
(2, 'music', '#music', 'Songs, albums, playlists & radio', 'music', 1, NULL, NULL, '2026-11-23 00:22:11'),
(3, 'games', '#games', 'PC, retro, console & indie games', 'games', 1, NULL, NULL, '2026-11-23 00:22:11'),
(4, 'latenight', '#latenight', 'Late-night talks, chill & insomnia', 'general', 1, NULL, NULL, '2026-11-23 00:22:11'),
(5, 'tech', '#tech', 'Coding, web design & retro hardware', 'tech', 1, NULL, NULL, '2026-11-23 00:22:11');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `friendships`
--

CREATE TABLE `friendships` (
  `id` int(10) UNSIGNED NOT NULL,
  `requester_id` int(10) UNSIGNED NOT NULL,
  `addressee_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','accepted') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `friendships`
--

INSERT INTO `friendships` (`id`, `requester_id`, `addressee_id`, `status`, `created_at`) VALUES
(2, 2, 3, 'accepted', '2026-11-23 00:18:43');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'system',
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 2, 'friend_accept', 'dusknet accepted your friend request!', 'profile.php?u=dusknet', 0, '2026-11-23 00:05:52'),
(2, 2, 'friend_accept', 'xyl accepted your friend request!', 'profile.php?u=xyl', 0, '2026-11-23 00:20:05');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `profile_wall`
--

CREATE TABLE `profile_wall` (
  `id` int(10) UNSIGNED NOT NULL,
  `profile_user_id` int(10) UNSIGNED NOT NULL,
  `author_user_id` int(10) UNSIGNED NOT NULL,
  `message` varchar(300) NOT NULL,
  `status` enum('pending','approved') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `profile_wall`
--

INSERT INTO `profile_wall` (`id`, `profile_user_id`, `author_user_id`, `message`, `status`, `created_at`) VALUES
(1, 1, 2, 'mal pic', 'approved', '2026-11-22 23:47:29'),
(2, 2, 2, 'sa', 'approved', '2026-11-22 23:48:10'),
(3, 2, 3, 'mal', 'approved', '2026-11-23 00:17:44');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `room_presence`
--

CREATE TABLE `room_presence` (
  `room_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `last_ping` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('user','vip','mod','admin') NOT NULL DEFAULT 'user',
  `mail` varchar(120) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `username_changed_at` timestamp NULL DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `status_text` varchar(120) DEFAULT NULL,
  `profile_bg_color` varchar(7) NOT NULL DEFAULT '#d0d7e1',
  `profile_text_color` varchar(7) NOT NULL DEFAULT '#1a1a1a',
  `card_header_color` varchar(7) NOT NULL DEFAULT '#3b6a99',
  `card_profile_color` varchar(7) NOT NULL DEFAULT '#ffffff',
  `card_bio_color` varchar(7) NOT NULL DEFAULT '#ffffff',
  `card_music_color` varchar(7) NOT NULL DEFAULT '#ffffff',
  `card_extra_color` varchar(7) NOT NULL DEFAULT '#ffffff',
  `profile_music_title` varchar(100) DEFAULT NULL,
  `profile_music_url` varchar(255) DEFAULT NULL,
  `age` tinyint(3) UNSIGNED DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `favorite_artists` varchar(255) DEFAULT NULL,
  `favorite_media` varchar(255) DEFAULT NULL,
  `contacts` text DEFAULT NULL,
  `last_notified_role` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `mail`, `ip`, `created_at`, `last_seen`, `expires_at`, `avatar`, `username_changed_at`, `bio`, `status_text`, `profile_bg_color`, `profile_text_color`, `card_header_color`, `card_profile_color`, `card_bio_color`, `card_music_color`, `card_extra_color`, `profile_music_title`, `profile_music_url`, `age`, `interests`, `favorite_artists`, `favorite_media`, `contacts`, `last_notified_role`) VALUES
(1, 'dusknet', '$2y$10$pbMhKGzKO/2/nEc/lnD64.ggJ.BtrZ4SuHELEBfrYrbmBEOyE.tP6', 'admin', 'envxrr1337@gmail.com', '::1', '2026-11-22 22:56:01', NULL, NULL, 'avatars/u1_b82c5a490510.png', NULL, NULL, NULL, '#d0d7e1', '#1a1a1a', '#3b6a99', '#ffffff', '#ffffff', '#ffffff', '#ffffff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'admin'),
(2, 'fxck', '$2y$10$bToVfeqe1mtwWKW3WFmXr.6BECalIXFu4n2lBiASD8sO8Z1LlofLy', 'user', 'enverkirazoglu@gmail.com', '::1', '2026-11-22 23:28:32', '2026-11-23 00:23:11', '2026-11-23 21:28:32', 'avatars/u2_d16993a69652.png', NULL, '1337', NULL, '#ff0000', '#1a1a1a', '#000000', '#000000', '#2b00ff', '#000000', '#0040ff', NULL, 'music/m2_c1fc07da4b.mp3', 17, NULL, 'swirf', 'lucifer', 'discord: enverwav', 'user'),
(3, 'xyl', '$2y$10$1j1rQWb/q3jcHB/p/gzBnuM6rUKRW3hex9P7ez0ukN5KdKoVYR76e', 'user', 'parisekip@gmail.com', '::1', '2026-11-23 00:13:25', '2026-11-23 00:20:05', NULL, 'avatars/u3_af1ef61f614c.jpg', NULL, 'im 17 n playing valorant', 'oturuyom', '#000000', '#ff0000', '#000000', '#ffffff', '#e100ff', '#2e2424', '#b09292', 'akina', 'music/m3_615a15deff.mp3', 17, 'songs', 'swirf', 'aot', 'discord: enverwav', 'user');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `user_blocks`
--

CREATE TABLE `user_blocks` (
  `id` int(10) UNSIGNED NOT NULL,
  `blocker_id` int(10) UNSIGNED NOT NULL,
  `blocked_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `user_blocks`
--

INSERT INTO `user_blocks` (`id`, `blocker_id`, `blocked_id`, `created_at`) VALUES
(1, 2, 1, '2026-11-23 00:19:13');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `user_reports`
--

CREATE TABLE `user_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `reporter_id` int(10) UNSIGNED NOT NULL,
  `reported_user_id` int(10) UNSIGNED NOT NULL,
  `reason` varchar(60) NOT NULL,
  `details` varchar(300) DEFAULT NULL,
  `status` enum('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `user_reports`
--

INSERT INTO `user_reports` (`id`, `reporter_id`, `reported_user_id`, `reason`, `details`, `status`, `created_at`) VALUES
(1, 2, 1, 'Spam / Scam', 'asdasdas', 'open', '2026-11-23 00:18:59');

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_room_time` (`room_id`,`created_at`),
  ADD KEY `fk_msg_user` (`user_id`);

--
-- Tablo için indeksler `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_room_cat` (`category`),
  ADD KEY `fk_room_creator` (`created_by`);

--
-- Tablo için indeksler `friendships`
--
ALTER TABLE `friendships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_friend_pair` (`requester_id`,`addressee_id`),
  ADD KEY `idx_addressee` (`addressee_id`,`status`);

--
-- Tablo için indeksler `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`);

--
-- Tablo için indeksler `profile_wall`
--
ALTER TABLE `profile_wall`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_profile_user` (`profile_user_id`),
  ADD KEY `fk_wall_author` (`author_user_id`);

--
-- Tablo için indeksler `room_presence`
--
ALTER TABLE `room_presence`
  ADD PRIMARY KEY (`room_id`,`user_id`),
  ADD KEY `idx_presence_ping` (`last_ping`),
  ADD KEY `fk_pres_user` (`user_id`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `mail` (`mail`);

--
-- Tablo için indeksler `user_blocks`
--
ALTER TABLE `user_blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_block_pair` (`blocker_id`,`blocked_id`),
  ADD KEY `idx_blocked` (`blocked_id`);

--
-- Tablo için indeksler `user_reports`
--
ALTER TABLE `user_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_status` (`status`,`created_at`),
  ADD KEY `fk_report_reporter` (`reporter_id`),
  ADD KEY `fk_report_target` (`reported_user_id`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `chat_rooms`
--
ALTER TABLE `chat_rooms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Tablo için AUTO_INCREMENT değeri `friendships`
--
ALTER TABLE `friendships`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Tablo için AUTO_INCREMENT değeri `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Tablo için AUTO_INCREMENT değeri `profile_wall`
--
ALTER TABLE `profile_wall`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Tablo için AUTO_INCREMENT değeri `user_blocks`
--
ALTER TABLE `user_blocks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Tablo için AUTO_INCREMENT değeri `user_reports`
--
ALTER TABLE `user_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_msg_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD CONSTRAINT `fk_room_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `friendships`
--
ALTER TABLE `friendships`
  ADD CONSTRAINT `fk_friend_add` FOREIGN KEY (`addressee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_friend_req` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `profile_wall`
--
ALTER TABLE `profile_wall`
  ADD CONSTRAINT `fk_wall_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wall_profile` FOREIGN KEY (`profile_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `room_presence`
--
ALTER TABLE `room_presence`
  ADD CONSTRAINT `fk_pres_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pres_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `user_blocks`
--
ALTER TABLE `user_blocks`
  ADD CONSTRAINT `fk_block_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_block_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `user_reports`
--
ALTER TABLE `user_reports`
  ADD CONSTRAINT `fk_report_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_report_target` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
