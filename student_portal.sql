-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 04, 2025 at 06:28 AM
-- Server version: 10.4.24-MariaDB
-- PHP Version: 8.1.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `student_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(10) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `att_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Compensation') NOT NULL,
  `is_compensation` tinyint(1) NOT NULL DEFAULT 0,
  `compensation_request_id` int(11) DEFAULT NULL,
  `is_video_comp` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`att_id`, `student_id`, `date`, `status`, `is_compensation`, `compensation_request_id`, `is_video_comp`) VALUES
(67, 90, '2025-06-01', 'Present', 0, NULL, 0),
(68, 90, '2025-06-02', 'Absent', 0, NULL, 0),
(69, 91, '2025-06-06', 'Compensation', 1, 21, 0),
(70, 92, '2025-06-06', 'Compensation', 1, 22, 0),
(71, 92, '2025-06-06', 'Compensation', 1, 23, 0),
(72, 92, '2025-06-07', 'Compensation', 1, 24, 0),
(73, 92, '2025-07-04', 'Compensation', 1, 25, 0),
(74, 92, '2025-07-05', 'Compensation', 1, 26, 0),
(75, 92, '2025-07-05', 'Compensation', 1, 27, 0),
(76, 92, '2025-07-04', 'Compensation', 1, 28, 0);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `operation` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` int(10) UNSIGNED NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `operation`, `table_name`, `record_id`, `changes`, `created_at`) VALUES
(1, 3, 'DELETE', 'students', 44, '{\"before\":{\"id\":\"44\",\"user_id\":\"52\",\"centre_id\":\"2\",\"name\":\"ishi\",\"email\":\"Ishika@gmail.com\",\"phone\":\"9123456780\",\"group_name\":\"Color Pencils\",\"dob\":\"2025-04-28\",\"address\":\"Vignana nagar\",\"photo_path\":null,\"is_legacy\":\"1\"}}', '2025-05-20 09:44:17'),
(2, 3, 'DELETE', 'students', 43, '{\"before\":{\"id\":\"43\",\"user_id\":\"51\",\"centre_id\":\"1\",\"name\":\"karan\",\"email\":\"karan@gmail.com\",\"phone\":\"9123456780\",\"group_name\":\"oil pastetls\",\"dob\":\"2025-04-27\",\"address\":\"Basvanagar\",\"photo_path\":null,\"is_legacy\":\"1\"}}', '2025-05-20 09:44:17'),
(3, 3, 'DELETE', 'students', 46, '{\"before\":{\"id\":\"46\",\"user_id\":\"55\",\"centre_id\":\"2\",\"name\":\"kirti\",\"email\":\"kirti@gmail.com\",\"phone\":\"9123456780\",\"group_name\":\"Color Pencils\",\"dob\":\"2025-04-27\",\"address\":\"Vignana nagar\",\"photo_path\":null,\"is_legacy\":\"1\"}}', '2025-05-20 09:44:18'),
(4, 3, 'DELETE', 'students', 45, '{\"before\":{\"id\":\"45\",\"user_id\":\"54\",\"centre_id\":\"1\",\"name\":\"RAVI PANDEY\",\"email\":\"mahi@gmail.com\",\"phone\":\"07060356382\",\"group_name\":\"pencil shading\",\"dob\":\"2025-05-05\",\"address\":\"56, 1st Cross Rd, Bhuvaneswari Nagar, C V Raman Nagar\",\"photo_path\":null,\"is_legacy\":\"0\"}}', '2025-05-20 09:44:18'),
(5, 3, 'DELETE', 'students', 39, '{\"before\":{\"id\":\"39\",\"user_id\":\"48\",\"centre_id\":\"2\",\"name\":\"Rohan\",\"email\":\"Rohan123@gmail.com\",\"phone\":\"9876532412\",\"group_name\":\"Color Pencils\",\"dob\":\"2025-05-06\",\"address\":\"Basvanagar\",\"photo_path\":null,\"is_legacy\":\"1\"}}', '2025-05-20 09:44:18'),
(6, 3, 'DELETE', 'students', 42, '{\"before\":{\"id\":\"42\",\"user_id\":\"50\",\"centre_id\":\"3\",\"name\":\"Soham\",\"email\":\"Soham@gmail.com\",\"phone\":\"987654321\",\"group_name\":\"Color Pencils\",\"dob\":\"2025-05-08\",\"address\":\"Vignana nagar\",\"photo_path\":null,\"is_legacy\":\"1\"}}', '2025-05-20 09:44:18'),
(7, 3, 'INSERT', 'students', 47, '{\"new\":{\"id\":47,\"user_id\":57,\"name\":\"mahi\",\"email\":\"mahi@gmail.com\",\"centre_id\":3,\"group_name\":\"oil pastetls\"}}', '2025-05-20 09:50:59'),
(8, 3, 'DELETE', 'students', 47, '{\"deleted_user_id\":57}', '2025-05-20 11:48:23'),
(9, 3, 'INSERT', 'students', 48, '{\"new\":{\"id\":48,\"user_id\":58,\"name\":\"Ishan N\",\"email\":\"anu959081@gmail.com\",\"centre_id\":1,\"group_name\":\"oil pastels\"}}', '2025-05-20 11:54:45'),
(10, 3, 'DELETE', 'students', 48, '{\"deleted_user_id\":58}', '2025-05-20 15:26:17'),
(11, 3, 'DELETE', 'students', 58, '{\"deleted_user_id\":69}', '2025-05-23 14:27:04'),
(12, 3, 'DELETE', 'students', 60, '{\"deleted_user_id\":71}', '2025-05-23 14:27:04'),
(13, 3, 'DELETE', 'students', 51, '{\"deleted_user_id\":61}', '2025-05-23 14:27:05'),
(14, 3, 'DELETE', 'students', 52, '{\"deleted_user_id\":62}', '2025-05-23 14:27:05'),
(15, 3, 'DELETE', 'students', 57, '{\"deleted_user_id\":68}', '2025-05-23 14:27:05'),
(16, 3, 'DELETE', 'students', 59, '{\"deleted_user_id\":70}', '2025-05-23 14:27:06'),
(17, 3, 'DELETE', 'students', 56, '{\"deleted_user_id\":67}', '2025-05-23 14:27:06'),
(18, 3, 'DELETE', 'students', 61, '{\"deleted_user_id\":72}', '2025-05-23 14:27:06'),
(19, 3, 'DELETE', 'students', 72, '{\"deleted_user_id\":84}', '2025-05-24 17:46:54'),
(20, 3, 'DELETE', 'students', 65, '{\"deleted_user_id\":76}', '2025-05-24 17:46:54'),
(21, 3, 'DELETE', 'students', 66, '{\"deleted_user_id\":77}', '2025-05-24 17:46:54'),
(22, 3, 'DELETE', 'students', 70, '{\"deleted_user_id\":82}', '2025-05-24 17:46:54'),
(23, 3, 'DELETE', 'students', 64, '{\"deleted_user_id\":75}', '2025-05-24 17:46:54'),
(24, 3, 'DELETE', 'students', 69, '{\"deleted_user_id\":80}', '2025-05-24 17:46:54'),
(25, 3, 'DELETE', 'students', 71, '{\"deleted_user_id\":83}', '2025-05-24 17:46:55'),
(26, 3, 'DELETE', 'students', 73, '{\"deleted_user_id\":85}', '2025-05-24 17:46:55'),
(27, 3, 'DELETE', 'students', 68, '{\"deleted_user_id\":79}', '2025-05-24 17:46:55'),
(28, 3, 'DELETE', 'students', 74, '{\"deleted_user_id\":86}', '2025-05-24 17:46:56'),
(29, 3, 'DELETE', 'students', 78, '{\"deleted_user_id\":90}', '2025-05-25 15:55:48'),
(30, 3, 'DELETE', 'students', 75, '{\"deleted_user_id\":87}', '2025-05-25 15:55:48'),
(31, 3, 'DELETE', 'students', 76, '{\"deleted_user_id\":88}', '2025-05-25 15:55:49'),
(32, 3, 'DELETE', 'students', 77, '{\"deleted_user_id\":89}', '2025-05-25 15:55:49'),
(33, 3, 'INSERT', 'homework_assigned', 38, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:37:21'),
(34, 3, 'INSERT', 'homework_assigned', 39, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:38:12'),
(35, 3, 'INSERT', 'homework_assigned', 40, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:41:14'),
(36, 3, 'INSERT', 'homework_assigned', 41, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:41:29'),
(37, 3, 'INSERT', 'homework_assigned', 42, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:41:39'),
(38, 3, 'DELETE', 'homework_assigned', 32, '{\"student_id\":83,\"title\":\"We have to this\",\"description\":\"submitt by next week\",\"file_path\":null}', '2025-05-30 11:48:48'),
(39, 3, 'DELETE', 'homework_assigned', 33, '{\"student_id\":83,\"title\":\"We have to this\",\"description\":\"submitt by next week\",\"file_path\":null}', '2025-05-30 11:48:56'),
(40, 3, 'DELETE', 'homework_assigned', 35, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:49:02'),
(41, 3, 'DELETE', 'homework_assigned', 36, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:49:08'),
(42, 3, 'DELETE', 'homework_assigned', 34, '{\"student_id\":84,\"title\":\"do it\",\"description\":\"tesy tingvgn\",\"file_path\":\"uploads/homework_attachments/hw_683942e0a0cd0_IMG-20170418-WA0021.jpg\"}', '2025-05-30 11:49:14'),
(43, 3, 'DELETE', 'homework_assigned', 37, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:49:20'),
(44, 3, 'DELETE', 'homework_assigned', 29, '{\"student_id\":83,\"title\":\"tes\",\"description\":\"dsdgfxfb\",\"file_path\":null}', '2025-05-30 11:49:31'),
(45, 3, 'DELETE', 'homework_assigned', 38, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:49:38'),
(46, 3, 'DELETE', 'homework_assigned', 39, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 11:49:50'),
(47, 3, 'DELETE', 'homework_assigned', 40, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 12:12:13'),
(48, 3, 'DELETE', 'homework_assigned', 30, '{\"student_id\":83,\"title\":\"tes\",\"description\":\"dsdgfxfb\",\"file_path\":null}', '2025-05-30 12:12:20'),
(49, 3, 'DELETE', 'homework_assigned', 41, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 12:12:27'),
(50, 3, 'DELETE', 'homework_assigned', 42, '{\"student_id\":84,\"title\":\"erdfd\",\"description\":\"fdvdfgd\",\"file_path\":null}', '2025-05-30 12:12:34'),
(51, 3, 'DELETE', 'students', 84, '{\"user_id\":96}', '2025-05-31 21:34:52');

-- --------------------------------------------------------

--
-- Table structure for table `centres`
--

CREATE TABLE `centres` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `centres`
--

INSERT INTO `centres` (`id`, `name`, `address`) VALUES
(1, 'Center A', NULL),
(2, 'Center B', NULL),
(3, 'Center C', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `compensation_requests`
--

CREATE TABLE `compensation_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `slot` varchar(50) NOT NULL,
  `comp_date` date NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('approved','missed') NOT NULL DEFAULT 'approved'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `compensation_requests`
--

INSERT INTO `compensation_requests` (`id`, `user_id`, `slot`, `comp_date`, `requested_at`, `status`) VALUES
(22, 110, 'fri_17_19', '2025-06-06', '2025-06-03 20:50:58', 'approved'),
(23, 110, 'fri_17_19', '2025-06-06', '2025-06-03 20:55:07', 'approved'),
(24, 110, 'sat_10_12', '2025-06-07', '2025-06-03 21:07:03', 'approved'),
(25, 110, 'fri_17_19', '2025-07-04', '2025-06-03 21:08:21', 'approved'),
(26, 110, 'sat_10_12', '2025-07-05', '2025-06-03 21:12:06', 'approved'),
(27, 110, 'sat_10_12', '2025-07-05', '2025-06-03 21:15:21', 'approved'),
(28, 110, 'fri_17_19', '2025-07-04', '2025-06-03 21:17:27', 'approved');

-- --------------------------------------------------------

--
-- Table structure for table `compensation_videos`
--

CREATE TABLE `compensation_videos` (
  `id` int(11) NOT NULL,
  `centre_id` int(11) NOT NULL,
  `class_date` date NOT NULL,
  `video_url` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `compensation_videos`
--

INSERT INTO `compensation_videos` (`id`, `centre_id`, `class_date`, `video_url`, `created_at`) VALUES
(6, 2, '2025-06-01', 'https://music.youtube.com/', '2025-06-02 13:55:44');

-- --------------------------------------------------------

--
-- Table structure for table `homework_assigned`
--

CREATE TABLE `homework_assigned` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `date_assigned` date NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `homework_assigned`
--

INSERT INTO `homework_assigned` (`id`, `student_id`, `date_assigned`, `title`, `description`, `file_path`) VALUES
(44, 90, '2025-06-03', 'fsdfvdfs', 'vdfvd', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `homework_feedback`
--

CREATE TABLE `homework_feedback` (
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `feedback` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `homework_rewards`
--

CREATE TABLE `homework_rewards` (
  `id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `stars` tinyint(4) DEFAULT 1,
  `awarded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `homework_submissions`
--

CREATE TABLE `homework_submissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `feedback` text DEFAULT NULL,
  `star_given` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `email`, `token`, `expires_at`) VALUES
(1, 'mahi@gmail.com', '518e214b89cfa36f9ecee4aeb7c7286d38e7e51856a72666a2205e273b42b6ce', '2025-05-28 17:21:16');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `status` enum('Paid','Pending','Overdue') NOT NULL DEFAULT 'Pending',
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `student_id`, `status`, `amount_paid`, `amount_due`, `paid_at`) VALUES
(101, 89, 'Pending', '0.00', '1905.70', NULL),
(102, 90, 'Pending', '0.00', '1681.50', NULL),
(103, 91, 'Pending', '0.00', '2352.98', NULL),
(104, 92, 'Pending', '0.00', '8945.58', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_plans`
--

CREATE TABLE `payment_plans` (
  `id` int(10) UNSIGNED NOT NULL,
  `centre_id` int(10) UNSIGNED NOT NULL,
  `group_name` varchar(50) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `duration_months` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `enrollment_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `advance_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prorate_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `late_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `payment_plans`
--

INSERT INTO `payment_plans` (`id`, `centre_id`, `group_name`, `plan_name`, `duration_months`, `amount`, `enrollment_fee`, `advance_fee`, `prorate_allowed`, `late_fee`, `gst_percent`) VALUES
(1, 1, 'Oil Pastels', 'Oil Pastels – Monthly', 1, '1500.00', '600.00', '0.00', 1, '100.00', '18.00'),
(2, 1, 'Color Pencils', 'Color Pencils – Monthly', 1, '1700.00', '600.00', '0.00', 1, '100.00', '18.00'),
(3, 1, 'Pencil Shading', 'Pencil Shading – Monthly', 1, '2000.00', '600.00', '0.00', 1, '100.00', '18.00'),
(4, 2, 'Oil Pastels', 'Oil Pastels – Monthly', 1, '1500.00', '600.00', '0.00', 1, '100.00', '18.00'),
(5, 2, 'Oil Pastels', 'Oil Pastels – 2-Month', 2, '2700.00', '600.00', '0.00', 1, '100.00', '18.00'),
(6, 2, 'Color Pencils', 'Color Pencils – Monthly', 1, '1700.00', '600.00', '0.00', 1, '100.00', '18.00'),
(7, 2, 'Color Pencils', 'Color Pencils – 2-Month', 2, '3060.00', '600.00', '0.00', 1, '100.00', '18.00'),
(8, 2, 'Pencil Shading', 'Pencil Shading – Monthly', 1, '2000.00', '600.00', '0.00', 1, '100.00', '18.00'),
(9, 2, 'Pencil Shading', 'Pencil Shading – 2-Month', 2, '3600.00', '600.00', '0.00', 1, '100.00', '18.00'),
(10, 3, 'Oil Pastels', 'Oil Pastels – Monthly', 1, '1699.00', '600.00', '1699.00', 0, '100.00', '18.00'),
(11, 3, 'Oil Pastels', 'Oil Pastels – 3-Month', 3, '4077.00', '600.00', '1359.00', 0, '100.00', '18.00'),
(12, 3, 'Oil Pastels', 'Oil Pastels – 6-Month', 6, '7140.00', '600.00', '1190.00', 0, '100.00', '18.00'),
(13, 3, 'Color Pencils', 'Color Pencils – Monthly', 1, '1899.00', '600.00', '0.00', 1, '100.00', '18.00'),
(14, 3, 'Color Pencils', 'Color Pencils – 3-Month', 3, '4560.00', '600.00', '1499.00', 0, '100.00', '18.00'),
(15, 3, 'Color Pencils', 'Color Pencils – 6-Month', 6, '7980.00', '600.00', '1299.00', 0, '100.00', '18.00'),
(16, 3, 'Pencil Shading', 'Pencil Shading – Monthly', 1, '2099.00', '600.00', '0.00', 1, '100.00', '18.00'),
(17, 3, 'Pencil Shading', 'Pencil Shading – 3-Month', 3, '5040.00', '600.00', '0.00', 0, '100.00', '18.00'),
(18, 3, 'Pencil Shading', 'Pencil Shading – 6-Month', 6, '8820.00', '600.00', '0.00', 0, '100.00', '18.00');

-- --------------------------------------------------------

--
-- Table structure for table `payment_proofs`
--

CREATE TABLE `payment_proofs` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_comment` varchar(255) DEFAULT NULL,
  `payment_method` enum('UPI','BankTransfer','Cash','Other') NOT NULL DEFAULT 'UPI',
  `txn_id` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `method` enum('UPI','Bank','Cash') NOT NULL DEFAULT 'UPI',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `progress`
--

CREATE TABLE `progress` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `hand_control` tinyint(4) NOT NULL DEFAULT 0,
  `hc_remark` text DEFAULT NULL,
  `coloring_shading` tinyint(4) NOT NULL DEFAULT 0,
  `cs_remark` text DEFAULT NULL,
  `observations` tinyint(4) NOT NULL DEFAULT 0,
  `obs_remark` text DEFAULT NULL,
  `temperament` tinyint(4) NOT NULL DEFAULT 0,
  `temp_remark` text DEFAULT NULL,
  `attendance` tinyint(4) NOT NULL DEFAULT 0,
  `att_remark` text DEFAULT NULL,
  `homework` tinyint(4) NOT NULL DEFAULT 0,
  `hw_remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `progress_feedback`
--

CREATE TABLE `progress_feedback` (
  `id` int(10) UNSIGNED NOT NULL,
  `progress_id` int(10) UNSIGNED NOT NULL,
  `feedback` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `remark_templates`
--

CREATE TABLE `remark_templates` (
  `id` int(11) NOT NULL,
  `category_key` varchar(32) NOT NULL COMMENT 'which metric, e.g. hand_control, attendance, etc.',
  `text` varchar(255) NOT NULL COMMENT 'the actual remark text'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `remark_templates`
--

INSERT INTO `remark_templates` (`id`, `category_key`, `text`) VALUES
(5, 'attendance', 'Always on time'),
(6, 'attendance', 'Occasional late arrivals'),
(3, 'coloring_shading', 'Good pressure control on brush'),
(4, 'coloring_shading', 'Work on blending transitions'),
(2, 'hand_control', 'Needs to relax wrist'),
(1, 'hand_control', 'Strong grip & steady lines');

-- --------------------------------------------------------

--
-- Table structure for table `stars`
--

CREATE TABLE `stars` (
  `student_id` int(10) UNSIGNED NOT NULL,
  `star_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `stars`
--

INSERT INTO `stars` (`student_id`, `star_count`) VALUES
(90, 15);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `centre_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `group_name` varchar(50) NOT NULL,
  `dob` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_legacy` tinyint(1) NOT NULL DEFAULT 0,
  `referred_by` int(11) DEFAULT NULL,
  `pending_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `centre_id`, `name`, `email`, `phone`, `group_name`, `dob`, `address`, `photo_path`, `is_legacy`, `referred_by`, `pending_discount_percent`) VALUES
(89, 107, 1, 'mahi', 'mahi@gmail.com', '987654321', 'Color Pencils', '0000-00-00', '', NULL, 1, NULL, '0.00'),
(90, 108, 2, 'karan', 'karan@gmail.com', '987654321', 'Oil Pastels', '0000-00-00', '', NULL, 1, NULL, '0.00'),
(91, 109, 3, 'soham', 'Soham@gmail.com', '9123456780', 'Pencil Shading', '0000-00-00', '', NULL, 1, NULL, '0.00'),
(92, 110, 3, 'ishika', 'Ishika@gmail.com', '987654321', 'Color Pencils', '0000-00-00', '', NULL, 1, NULL, '0.00');

-- --------------------------------------------------------

--
-- Table structure for table `student_subscriptions`
--

CREATE TABLE `student_subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `subscribed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `referral_applied` tinyint(1) NOT NULL DEFAULT 0,
  `referral_amount` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `student_subscriptions`
--

INSERT INTO `student_subscriptions` (`id`, `student_id`, `plan_id`, `subscribed_at`, `referral_applied`, `referral_amount`) VALUES
(89, 89, 2, '2025-06-02 09:26:43', 0, '0.00'),
(90, 90, 4, '2025-06-02 10:26:28', 0, '0.00'),
(91, 91, 16, '2025-06-02 15:21:11', 0, '0.00'),
(92, 92, 15, '2025-06-03 09:59:14', 0, '0.00');

-- --------------------------------------------------------

--
-- Table structure for table `student_verifications`
--

CREATE TABLE `student_verifications` (
  `verification_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `id_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','admin') NOT NULL DEFAULT 'student',
  `centre_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `centre_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(3, 'admin@rartworks.com', '$2y$10$i8bKhTIkGG07KuTTZiWRZuhWSktJtjlg73IVamspW.5HXAHcFZh5O', 'admin', 1, '2025-04-29 18:44:52', NULL, NULL),
(107, 'mahi@gmail.com', '$2y$10$iZXZn2cjEg7wcuhBme.UUecYXwYqPg0XtjbGC4LiWcUhU/hp30YnW', 'student', 1, '2025-06-02 12:56:43', NULL, NULL),
(108, 'karan@gmail.com', '$2y$10$z7HdNixtV//IgHYXhBOgOeBYL5Y/M9U.BmVLMK40scLkRIJRFWw6K', 'student', 2, '2025-06-02 13:56:28', NULL, NULL),
(109, 'Soham@gmail.com', '$2y$10$yreuVFC9P1alFx2JyZivPeBPQrCh/Am11uBU9V.oAm56bmrlZ4xCS', 'student', 3, '2025-06-02 18:51:11', NULL, NULL),
(110, 'Ishika@gmail.com', '$2y$10$U1XG1oc3mue4GBabFzqa4.4EnoKSvN35M5C2kKru0zNmvrcLOIlpK', 'student', 3, '2025-06-03 13:29:14', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `video_completions`
--

CREATE TABLE `video_completions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `watched_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `video_quiz_questions`
--

CREATE TABLE `video_quiz_questions` (
  `id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`options_json`)),
  `correct_index` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`att_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `is_video_comp` (`is_video_comp`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `table_name` (`table_name`);

--
-- Indexes for table `centres`
--
ALTER TABLE `centres`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `compensation_requests`
--
ALTER TABLE `compensation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `requested_at` (`requested_at`);

--
-- Indexes for table `compensation_videos`
--
ALTER TABLE `compensation_videos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `homework_feedback`
--
ALTER TABLE `homework_feedback`
  ADD PRIMARY KEY (`assignment_id`,`student_id`);

--
-- Indexes for table `homework_rewards`
--
ALTER TABLE `homework_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `homework_rewards_ibfk_2` (`student_id`);

--
-- Indexes for table `homework_submissions`
--
ALTER TABLE `homework_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `token` (`token`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `payment_plans`
--
ALTER TABLE `payment_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_plans` (`centre_id`,`group_name`,`duration_months`),
  ADD KEY `centre_id` (`centre_id`);

--
-- Indexes for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_proofs_student` (`student_id`);

--
-- Indexes for table `progress`
--
ALTER TABLE `progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `progress_feedback`
--
ALTER TABLE `progress_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `progress_id` (`progress_id`);

--
-- Indexes for table `remark_templates`
--
ALTER TABLE `remark_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_text` (`category_key`,`text`);

--
-- Indexes for table `stars`
--
ALTER TABLE `stars`
  ADD PRIMARY KEY (`student_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_students_centres` (`centre_id`);

--
-- Indexes for table `student_subscriptions`
--
ALTER TABLE `student_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indexes for table `student_verifications`
--
ALTER TABLE `student_verifications`
  ADD PRIMARY KEY (`verification_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `centre_id` (`centre_id`);

--
-- Indexes for table `video_completions`
--
ALTER TABLE `video_completions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `video_id` (`video_id`);

--
-- Indexes for table `video_quiz_questions`
--
ALTER TABLE `video_quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `video_id` (`video_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `att_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `centres`
--
ALTER TABLE `centres`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `compensation_requests`
--
ALTER TABLE `compensation_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `compensation_videos`
--
ALTER TABLE `compensation_videos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `homework_rewards`
--
ALTER TABLE `homework_rewards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `homework_submissions`
--
ALTER TABLE `homework_submissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `payment_plans`
--
ALTER TABLE `payment_plans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `progress`
--
ALTER TABLE `progress`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `progress_feedback`
--
ALTER TABLE `progress_feedback`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remark_templates`
--
ALTER TABLE `remark_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `student_subscriptions`
--
ALTER TABLE `student_subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `student_verifications`
--
ALTER TABLE `student_verifications`
  MODIFY `verification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `video_completions`
--
ALTER TABLE `video_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `video_quiz_questions`
--
ALTER TABLE `video_quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  ADD CONSTRAINT `homework_assigned_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homework_rewards`
--
ALTER TABLE `homework_rewards`
  ADD CONSTRAINT `homework_rewards_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `homework_assigned` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `homework_rewards_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homework_submissions`
--
ALTER TABLE `homework_submissions`
  ADD CONSTRAINT `homework_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `homework_assigned` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `homework_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stars`
--
ALTER TABLE `stars`
  ADD CONSTRAINT `fk_stars_students` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `video_completions`
--
ALTER TABLE `video_completions`
  ADD CONSTRAINT `video_completions_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `compensation_videos` (`id`);

--
-- Constraints for table `video_quiz_questions`
--
ALTER TABLE `video_quiz_questions`
  ADD CONSTRAINT `video_quiz_questions_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `compensation_videos` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
