-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 19, 2025 at 11:49 AM
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
  `status` enum('Present','Absent','Compensation') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`att_id`, `student_id`, `date`, `status`) VALUES
(30, 35, '2025-05-02', 'Absent'),
(31, 35, '2025-05-09', 'Present'),
(32, 35, '2025-05-10', 'Compensation'),
(33, 35, '2025-05-16', 'Present');

-- --------------------------------------------------------

--
-- Table structure for table `center_fee_settings`
--

CREATE TABLE `center_fee_settings` (
  `centre_id` int(10) UNSIGNED NOT NULL,
  `enrollment_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `advance_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prorate_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `late_fee` decimal(10,2) NOT NULL DEFAULT 50.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 18.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `center_fee_settings`
--

INSERT INTO `center_fee_settings` (`centre_id`, `enrollment_fee`, `advance_fee`, `prorate_allowed`, `late_fee`, `gst_percent`) VALUES
(1, '600.00', '0.00', 1, '100.00', '18.00'),
(2, '600.00', '0.00', 1, '100.00', '18.00'),
(3, '600.00', '1699.00', 0, '100.00', '18.00');

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
(22, 33, '2025-05-16', 'do it', 'do it', NULL),
(23, 35, '2025-05-16', 'do it today', 'do it today', 'uploads/homework_attachments/6827115145d52_20250311_090508.jpg'),
(24, 35, '2025-05-18', 'This is a test homework', 'Chekc this out', 'uploads/homework_attachments/68295ea1aec1e_IMG-20160902-WA0005.jpg'),
(25, 35, '2025-05-18', 'This is a test homework', 'Chekc this out', 'uploads/homework_attachments/68295ea8b5893_IMG-20160902-WA0005.jpg'),
(26, 36, '2025-05-18', 'This is a test hw', 'This is a test hw', 'uploads/homework_attachments/6829c7b521c95_IMG-20170322-WA0000.jpg');

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

--
-- Dumping data for table `homework_submissions`
--

INSERT INTO `homework_submissions` (`id`, `assignment_id`, `student_id`, `file_path`, `submitted_at`, `feedback`, `star_given`) VALUES
(3, 23, 35, 'uploads/homework/35_23_1747484736.jpeg', '2025-05-17 17:55:36', 'good', 1),
(4, 22, 33, '../uploads/homework/33_22_1747574912.jpg', '2025-05-18 18:58:32', NULL, 0);

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `student_id`, `title`, `message`, `is_read`, `created_at`) VALUES
(10, 33, 'New Feedback on Homework', 'Great work', 0, '2025-05-18 21:10:05'),
(11, 33, 'New Feedback on Homework', 'Great work hurry', 0, '2025-05-18 21:15:28'),
(12, 35, 'New Feedback on Homework', 'Good job', 1, '2025-05-19 10:41:48'),
(13, 35, 'New Feedback on Homework', 'grt', 1, '2025-05-19 10:47:35'),
(14, 35, 'New Feedback on Homework', 'good wrk', 1, '2025-05-19 11:07:38'),
(15, 35, 'New Feedback on Homework', 'woo nice', 1, '2025-05-19 11:20:14'),
(16, 35, 'New Feedback on Homework', 'hurrry', 1, '2025-05-19 11:29:49'),
(17, 33, 'New Feedback on Homework', 'hurry', 0, '2025-05-19 11:43:29'),
(18, 33, 'New Feedback on Homework', 'y good', 0, '2025-05-19 11:56:33'),
(19, 35, 'New Feedback on Homework', 'hurrry', 1, '2025-05-19 12:02:47');

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
(25, 33, 'Pending', '0.00', '10407.60', NULL),
(26, 33, 'Paid', '1735.00', '0.00', '2025-05-16 14:56:16'),
(27, 34, 'Pending', '0.00', '885.00', NULL),
(28, 34, 'Paid', '1770.00', '0.00', '2025-05-16 15:16:02'),
(29, 35, 'Pending', '0.00', '12129.22', NULL),
(30, 35, 'Paid', '12129.00', '0.00', '2025-05-16 15:20:59'),
(31, 36, 'Pending', '0.00', '3610.80', NULL);

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
(10, 3, 'Oil Pastels', 'Oil Pastels – Monthly', 1, '1699.00', '600.00', '0.00', 1, '100.00', '18.00'),
(11, 3, 'Oil Pastels', 'Oil Pastels – 3-Month', 3, '4077.00', '600.00', '1699.00', 0, '100.00', '18.00'),
(12, 3, 'Oil Pastels', 'Oil Pastels – 6-Month', 6, '7140.00', '600.00', '999.00', 0, '100.00', '18.00'),
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

--
-- Dumping data for table `payment_proofs`
--

INSERT INTO `payment_proofs` (`id`, `student_id`, `file_path`, `uploaded_at`, `status`, `admin_comment`, `payment_method`, `txn_id`, `approved_at`, `method`, `amount`, `rejection_reason`) VALUES
(17, 33, 'uploads/payment_proofs/33_1747387551_smd_may25.jpeg', '2025-05-16 14:55:51', 'Approved', NULL, 'BankTransfer', 'xxtest', '2025-05-16 14:56:16', 'UPI', '0.00', NULL),
(18, 34, 'uploads/payment_proofs/34_1747388750_share_percentage_SMD_feb25_.jpeg', '2025-05-16 15:15:50', 'Approved', NULL, 'BankTransfer', 'cxxxx', '2025-05-16 15:16:02', 'UPI', '0.00', NULL),
(19, 35, 'uploads/payment_proofs/35_1747389049_share_percentage_SMD_feb25_.jpeg', '2025-05-16 15:20:49', 'Approved', NULL, 'Cash', 'cash', '2025-05-16 15:20:59', 'UPI', '0.00', NULL);

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

--
-- Dumping data for table `progress`
--

INSERT INTO `progress` (`id`, `student_id`, `month`, `hand_control`, `hc_remark`, `coloring_shading`, `cs_remark`, `observations`, `obs_remark`, `temperament`, `temp_remark`, `attendance`, `att_remark`, `homework`, `hw_remark`) VALUES
(12, 33, '2025-05', 2, 'Needs to relax wrist', 3, 'Work on blending transitions', 3, 'need focus', 4, 'very attentive', 5, 'Always on time', 3, 'need a bit more complex drwaing'),
(13, 35, '2025-05', 1, 'Needs to relax wrist', 2, 'Work on blending transitions', 3, 'good', 3, 'need attention', 4, 'Always on time', 3, 'need a bit more complex drwaing');

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
-- Table structure for table `stars`
--

CREATE TABLE `stars` (
  `student_id` int(10) UNSIGNED NOT NULL,
  `star_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  `is_legacy` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `centre_id`, `name`, `email`, `phone`, `group_name`, `dob`, `address`, `photo_path`, `is_legacy`) VALUES
(33, 40, 3, 'Karan', 'karan@gmail.com', '9874563210', 'pencil shading', '2025-05-04', 'Vignana nagar', NULL, 1),
(34, 41, 2, 'RAVI PANDEY', 'mahi@gmail.com', '07060356382', 'oil pastetls', '2025-05-12', '56', NULL, 1),
(35, 42, 3, 'Ishika', 'Ishika@gmail.com', '07060356382', 'Color Pencils', '2025-04-27', '56, 1st Cross Rd, Bhuvaneswari Nagar, C V Raman Nagar', NULL, 0),
(36, 43, 1, 'Alice Kapoor', 'alice.kapoor@example.com', '9874563210', 'Color Pencils', '2025-04-27', 'assertz', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `student_subscriptions`
--

CREATE TABLE `student_subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `subscribed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `student_subscriptions`
--

INSERT INTO `student_subscriptions` (`id`, `student_id`, `plan_id`, `subscribed_at`) VALUES
(19, 18, 15, '2025-05-09 15:02:15'),
(31, 33, 18, '2025-05-16 09:46:36'),
(32, 34, 1, '2025-05-16 11:44:51'),
(33, 35, 15, '2025-05-16 11:49:09'),
(34, 34, 4, '2025-05-18 06:33:20'),
(35, 36, 7, '2025-05-18 13:41:30');

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
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `centre_id`, `created_at`) VALUES
(3, 'admin@rartworks.com', '$2y$10$i8bKhTIkGG07KuTTZiWRZuhWSktJtjlg73IVamspW.5HXAHcFZh5O', 'admin', 1, '2025-04-29 18:44:52'),
(40, 'karan@gmail.com', '$2y$10$V2LnhbvX.kDUX8LdEXO0n.oXRlyYO9/vOqwA2F5xSeOciRgY5TZNC', 'student', 3, '2025-05-16 13:16:36'),
(41, 'mahi@gmail.com', '$2y$10$6/r54xmAM8vTttP22UefIOLvdRp05v2DqoO86e8q76BCAO.KO8kQe', 'student', 1, '2025-05-16 15:14:51'),
(42, 'Ishika@gmail.com', '$2y$10$dq4N8opxDsU1u8I2V/ZEG.vuKyhxKfNBolxXaQsKiIPSxKo0seLJK', 'student', 3, '2025-05-16 15:19:09'),
(43, 'alice.kapoor@example.com', '$2y$10$ERkLOJ3mNU8neg8Jw8/OaOeW1m6ggq7Z8XupkdBwYy0HI0AyfNyr6', 'student', 1, '2025-05-18 17:11:30');

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
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `center_fee_settings`
--
ALTER TABLE `center_fee_settings`
  ADD PRIMARY KEY (`centre_id`);

--
-- Indexes for table `centres`
--
ALTER TABLE `centres`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

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
  MODIFY `att_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `centres`
--
ALTER TABLE `centres`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `payment_plans`
--
ALTER TABLE `payment_plans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `payment_proofs`
--
ALTER TABLE `payment_proofs`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `progress`
--
ALTER TABLE `progress`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `progress_feedback`
--
ALTER TABLE `progress_feedback`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `student_subscriptions`
--
ALTER TABLE `student_subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `student_verifications`
--
ALTER TABLE `student_verifications`
  MODIFY `verification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `center_fee_settings`
--
ALTER TABLE `center_fee_settings`
  ADD CONSTRAINT `fk_cfs_centres` FOREIGN KEY (`centre_id`) REFERENCES `centres` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homework_assigned`
--
ALTER TABLE `homework_assigned`
  ADD CONSTRAINT `homework_assigned_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `homework_submissions`
--
ALTER TABLE `homework_submissions`
  ADD CONSTRAINT `homework_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `homework_assigned` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `homework_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
