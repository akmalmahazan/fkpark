-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 22, 2025 at 09:58 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fkpark`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `name`, `email`, `phone`) VALUES
('a23015', 'Billie Ellish', 'billie@admin.fk.edu', '+60123456789'),
('a23021', 'Adam Afiq', 'adamafiq@admin.fk.com', '+60148226359');

-- --------------------------------------------------------

--
-- Table structure for table `area_closure`
--

CREATE TABLE `area_closure` (
  `closure_ID` int(15) NOT NULL,
  `closure_reason` varchar(255) NOT NULL,
  `closed_from` datetime NOT NULL,
  `closed_to` datetime NOT NULL,
  `admin_id` varchar(255) DEFAULT NULL,
  `area_ID` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `area_closure`
--

INSERT INTO `area_closure` (`closure_ID`, `closure_reason`, `closed_from`, `closed_to`, `admin_id`, `area_ID`) VALUES
(14, 'Event', '2025-12-22 01:19:00', '2025-12-22 01:25:00', 'a23015', 6),
(18, 'Parking Untuk Ceramah Ustaz Azhar Idrus', '2025-12-22 23:13:00', '2025-12-22 23:17:00', 'a23015', 6),
(19, 'Masjlis Minta Restu', '2025-12-23 04:00:00', '2025-12-24 03:58:00', 'a23015', 7);

-- --------------------------------------------------------

--
-- Table structure for table `booking`
--

CREATE TABLE `booking` (
  `booking_id` int(15) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `QrCode` mediumblob DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `student_id` varchar(255) NOT NULL,
  `space_id` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `booking`
--

INSERT INTO `booking` (`booking_id`, `date`, `time`, `QrCode`, `status`, `student_id`, `space_id`) VALUES
(31, '2025-12-23', '04:02:00', 0x526b745151564a4c58304a505430744a546b64664d7a453d, 'Completed', 's23015', 281);

-- --------------------------------------------------------

--
-- Table structure for table `demerit_point`
--

CREATE TABLE `demerit_point` (
  `demerit_id` int(15) NOT NULL,
  `student_id` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `total` int(15) NOT NULL,
  `traffic_summonID` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `demerit_point`
--

INSERT INTO `demerit_point` (`demerit_id`, `student_id`, `description`, `date`, `total`, `traffic_summonID`) VALUES
(1, 's23021', 'Parking Violation', '2025-12-23', 10, 1),
(2, 's23015', 'Parking Violation', '2025-12-23', 10, 2),
(3, 's23015', 'Accident caused', '2025-12-23', 20, 3),
(4, 's23015', 'Accident caused', '2025-12-23', 20, 4),
(5, 's23015', 'Not comply in campus traffic regulations', '2025-12-23', 15, 5),
(6, 's23015', 'Accident caused', '2025-12-23', 20, 6);

-- --------------------------------------------------------

--
-- Table structure for table `login`
--

CREATE TABLE `login` (
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_type` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login`
--

INSERT INTO `login` (`username`, `password`, `role_type`) VALUES
('a23015', '$2y$10$Skf.ExcK.KZ8v4K62CMXO.vB.2bISfUU1bSoMMldztCAvlcYJKSbO', 'Admin'),
('a23021', '$2y$10$jfjp5LDiTnAQ4mKo39y8eeoOyR.itPVg.jKRTuwSqX.XWcuPMx6MO', 'Admin'),
('s23015', '$2y$10$EQBNcTekWmTFWbSnHRYDg.hzjtUrwu0XjIlg.xOQBmSrxcQApBk7i', 'Student'),
('s23021', '$2y$10$ShqkBtB9.NJ4zVZywkHEJ.NKIdDvEU1w5YysJWeq9FZ/KPyJFx4me', 'Student'),
('ss23015', '$2y$10$ZqzXKAqv./6X3jru4WyPuuHFHHyFGVZeK1t38Fc5qCfmi8E3D/yci', 'Security'),
('ss23021', '$2y$10$QOgzx/6gKbd8eCMSdguESuPsbOtroqbxCQl42d5/E9IgQncA9jvZi', 'Security');

-- --------------------------------------------------------

--
-- Table structure for table `parking`
--

CREATE TABLE `parking` (
  `parking_id` int(15) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `booking_id` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parking`
--

INSERT INTO `parking` (`parking_id`, `date`, `time`, `booking_id`) VALUES
(28, '2025-12-23', '04:00:58', 31);

-- --------------------------------------------------------

--
-- Table structure for table `parking_area`
--

CREATE TABLE `parking_area` (
  `Area_ID` int(15) NOT NULL,
  `area_name` varchar(100) NOT NULL,
  `admin_id` varchar(255) NOT NULL,
  `area_type` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parking_area`
--

INSERT INTO `parking_area` (`Area_ID`, `area_name`, `admin_id`, `area_type`) VALUES
(1, 'A1', 'a23015', 'staff'),
(2, 'A2', 'a23015', 'staff'),
(3, 'A3', 'a23015', 'staff'),
(4, 'A4', 'a23015', 'staff'),
(5, 'B1', 'a23015', 'student'),
(6, 'B2', 'a23015', 'student'),
(7, 'B3', 'a23015', 'student');

-- --------------------------------------------------------

--
-- Table structure for table `parking_space`
--

CREATE TABLE `parking_space` (
  `space_id` int(15) NOT NULL,
  `space_num` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Available',
  `QrCode` varchar(255) DEFAULT NULL,
  `area_ID` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parking_space`
--

INSERT INTO `parking_space` (`space_id`, `space_num`, `status`, `QrCode`, `area_ID`) VALUES
(181, 'A1-01', 'Available', 'RktQQVJLX1NQQUNFXzE4MQ==', 1),
(182, 'A1-02', 'Available', 'RktQQVJLX1NQQUNFXzE4Mg==', 1),
(183, 'A1-03', 'Available', 'RktQQVJLX1NQQUNFXzE4Mw==', 1),
(184, 'A1-04', 'Available', 'RktQQVJLX1NQQUNFXzE4NA==', 1),
(185, 'A1-05', 'Available', 'RktQQVJLX1NQQUNFXzE4NQ==', 1),
(186, 'A1-06', 'Available', 'RktQQVJLX1NQQUNFXzE4Ng==', 1),
(187, 'A1-07', 'Available', 'RktQQVJLX1NQQUNFXzE4Nw==', 1),
(188, 'A1-08', 'Available', 'RktQQVJLX1NQQUNFXzE4OA==', 1),
(189, 'A1-09', 'Available', 'RktQQVJLX1NQQUNFXzE4OQ==', 1),
(190, 'A1-10', 'Available', 'RktQQVJLX1NQQUNFXzE5MA==', 1),
(191, 'A1-11', 'Available', 'RktQQVJLX1NQQUNFXzE5MQ==', 1),
(192, 'A1-12', 'Available', 'RktQQVJLX1NQQUNFXzE5Mg==', 1),
(193, 'A1-13', 'Available', 'RktQQVJLX1NQQUNFXzE5Mw==', 1),
(194, 'A1-14', 'Available', 'RktQQVJLX1NQQUNFXzE5NA==', 1),
(195, 'A1-15', 'Available', 'RktQQVJLX1NQQUNFXzE5NQ==', 1),
(196, 'A1-16', 'Available', 'RktQQVJLX1NQQUNFXzE5Ng==', 1),
(197, 'A1-17', 'Available', 'RktQQVJLX1NQQUNFXzE5Nw==', 1),
(198, 'A1-18', 'Available', 'RktQQVJLX1NQQUNFXzE5OA==', 1),
(199, 'A1-19', 'Available', 'RktQQVJLX1NQQUNFXzE5OQ==', 1),
(200, 'A1-20', 'Available', 'RktQQVJLX1NQQUNFXzIwMA==', 1),
(201, 'A1-21', 'Available', 'RktQQVJLX1NQQUNFXzIwMQ==', 1),
(202, 'A1-22', 'Available', 'RktQQVJLX1NQQUNFXzIwMg==', 1),
(203, 'A1-23', 'Available', 'RktQQVJLX1NQQUNFXzIwMw==', 1),
(204, 'A1-24', 'Available', 'RktQQVJLX1NQQUNFXzIwNA==', 1),
(205, 'A1-25', 'Available', 'RktQQVJLX1NQQUNFXzIwNQ==', 1),
(206, 'A2-01', 'Available', 'RktQQVJLX1NQQUNFXzIwNg==', 2),
(207, 'A2-02', 'Available', 'RktQQVJLX1NQQUNFXzIwNw==', 2),
(208, 'A2-03', 'Available', 'RktQQVJLX1NQQUNFXzIwOA==', 2),
(209, 'A2-04', 'Available', 'RktQQVJLX1NQQUNFXzIwOQ==', 2),
(210, 'A2-05', 'Available', 'RktQQVJLX1NQQUNFXzIxMA==', 2),
(211, 'A2-06', 'Available', 'RktQQVJLX1NQQUNFXzIxMQ==', 2),
(212, 'A2-07', 'Available', 'RktQQVJLX1NQQUNFXzIxMg==', 2),
(213, 'A2-08', 'Available', 'RktQQVJLX1NQQUNFXzIxMw==', 2),
(214, 'A2-09', 'Available', 'RktQQVJLX1NQQUNFXzIxNA==', 2),
(215, 'A2-10', 'Available', 'RktQQVJLX1NQQUNFXzIxNQ==', 2),
(216, 'A2-11', 'Available', 'RktQQVJLX1NQQUNFXzIxNg==', 2),
(217, 'A2-12', 'Available', 'RktQQVJLX1NQQUNFXzIxNw==', 2),
(218, 'A2-13', 'Available', 'RktQQVJLX1NQQUNFXzIxOA==', 2),
(219, 'A2-14', 'Available', 'RktQQVJLX1NQQUNFXzIxOQ==', 2),
(220, 'A2-15', 'Available', 'RktQQVJLX1NQQUNFXzIyMA==', 2),
(221, 'A2-16', 'Available', 'RktQQVJLX1NQQUNFXzIyMQ==', 2),
(222, 'A2-17', 'Available', 'RktQQVJLX1NQQUNFXzIyMg==', 2),
(223, 'A2-18', 'Available', 'RktQQVJLX1NQQUNFXzIyMw==', 2),
(224, 'A2-19', 'Available', 'RktQQVJLX1NQQUNFXzIyNA==', 2),
(225, 'A2-20', 'Available', 'RktQQVJLX1NQQUNFXzIyNQ==', 2),
(226, 'A2-21', 'Available', 'RktQQVJLX1NQQUNFXzIyNg==', 2),
(227, 'A2-22', 'Available', 'RktQQVJLX1NQQUNFXzIyNw==', 2),
(228, 'A2-23', 'Available', 'RktQQVJLX1NQQUNFXzIyOA==', 2),
(229, 'A2-24', 'Available', 'RktQQVJLX1NQQUNFXzIyOQ==', 2),
(230, 'A2-25', 'Available', 'RktQQVJLX1NQQUNFXzIzMA==', 2),
(231, 'A3-01', 'Available', 'RktQQVJLX1NQQUNFXzIzMQ==', 3),
(232, 'A3-02', 'Available', 'RktQQVJLX1NQQUNFXzIzMg==', 3),
(233, 'A3-03', 'Available', 'RktQQVJLX1NQQUNFXzIzMw==', 3),
(234, 'A3-04', 'Available', 'RktQQVJLX1NQQUNFXzIzNA==', 3),
(235, 'A3-05', 'Available', 'RktQQVJLX1NQQUNFXzIzNQ==', 3),
(236, 'A3-06', 'Available', 'RktQQVJLX1NQQUNFXzIzNg==', 3),
(237, 'A3-07', 'Available', 'RktQQVJLX1NQQUNFXzIzNw==', 3),
(238, 'A3-08', 'Available', 'RktQQVJLX1NQQUNFXzIzOA==', 3),
(239, 'A3-09', 'Available', 'RktQQVJLX1NQQUNFXzIzOQ==', 3),
(240, 'A3-10', 'Available', 'RktQQVJLX1NQQUNFXzI0MA==', 3),
(241, 'A3-11', 'Available', 'RktQQVJLX1NQQUNFXzI0MQ==', 3),
(242, 'A3-12', 'Available', 'RktQQVJLX1NQQUNFXzI0Mg==', 3),
(243, 'A3-13', 'Available', 'RktQQVJLX1NQQUNFXzI0Mw==', 3),
(244, 'A3-14', 'Available', 'RktQQVJLX1NQQUNFXzI0NA==', 3),
(245, 'A3-15', 'Available', 'RktQQVJLX1NQQUNFXzI0NQ==', 3),
(246, 'A3-16', 'Available', 'RktQQVJLX1NQQUNFXzI0Ng==', 3),
(247, 'A3-17', 'Available', 'RktQQVJLX1NQQUNFXzI0Nw==', 3),
(248, 'A3-18', 'Available', 'RktQQVJLX1NQQUNFXzI0OA==', 3),
(249, 'A3-19', 'Available', 'RktQQVJLX1NQQUNFXzI0OQ==', 3),
(250, 'A3-20', 'Available', 'RktQQVJLX1NQQUNFXzI1MA==', 3),
(251, 'A3-21', 'Available', 'RktQQVJLX1NQQUNFXzI1MQ==', 3),
(252, 'A3-22', 'Available', 'RktQQVJLX1NQQUNFXzI1Mg==', 3),
(253, 'A3-23', 'Available', 'RktQQVJLX1NQQUNFXzI1Mw==', 3),
(254, 'A3-24', 'Available', 'RktQQVJLX1NQQUNFXzI1NA==', 3),
(255, 'A3-25', 'Available', 'RktQQVJLX1NQQUNFXzI1NQ==', 3),
(256, 'A4-01', 'Available', 'QR_256_5a5884e3802b7762f70ff4fc4ef2409c', 4),
(257, 'A4-02', 'Available', 'QR_257_895de1da3755c95dcb7083ffcaa6090c', 4),
(258, 'A4-03', 'Available', 'QR_258_706f16b4535e34e8f3d9acd7566d151a', 4),
(259, 'A4-04', 'Available', 'QR_259_0a95838fb182cec6dc475e08d2fd4631', 4),
(260, 'A4-05', 'Available', 'QR_260_f24a3a2baf19753f274e1f65fecc4c97', 4),
(261, 'A4-06', 'Available', 'QR_261_91f61bb3dbc3673e46ad93a504a8cba9', 4),
(262, 'A4-07', 'Available', 'QR_262_0722cdfa9a670c7381d6d1153341aecd', 4),
(263, 'A4-08', 'Available', 'QR_263_4a9b6f7ee38cd82f5c00c93e5709495f', 4),
(264, 'A4-09', 'Available', 'QR_264_c7d6e827ce14ceee6367deb9531dc7b0', 4),
(265, 'A4-10', 'Available', 'QR_265_05576ba56fa8ab915ea2655f6c32b0e6', 4),
(266, 'A4-11', 'Available', 'QR_266_8c0341b87367b411d8c0b84b4b5d1950', 4),
(267, 'A4-12', 'Available', 'QR_267_d16f7cffa70b78fec852114206e0382b', 4),
(268, 'A4-13', 'Available', 'QR_268_f8d1b90c9e783daa5f24eb6bdb2d8f0f', 4),
(269, 'A4-14', 'Available', 'QR_269_162b569a4547d796f7d633881877f8c8', 4),
(270, 'A4-15', 'Available', 'QR_270_71cf69c1408d53352502408fb4d3e678', 4),
(271, 'A4-16', 'Available', 'QR_271_a48f272d87dd69861869f3f2ccc77151', 4),
(272, 'A4-17', 'Available', 'QR_272_230d68d768c533255d7e0c822dd7b441', 4),
(273, 'A4-18', 'Available', 'QR_273_895ea6f57e62b52a4f67cfb2043f56a2', 4),
(274, 'A4-19', 'Available', 'QR_274_7846ecc4cef758a85c2b3d34782a2604', 4),
(275, 'A4-20', 'Available', 'QR_275_5ed0c9cfbab5dc7e81cc92a25a6a0b07', 4),
(276, 'A4-21', 'Available', 'QR_276_3206383fd4db961a4fcd6fb96f71efa5', 4),
(277, 'A4-22', 'Available', 'QR_277_0e30a886be16f01c37c110c32575480a', 4),
(278, 'A4-23', 'Available', 'QR_278_b1726eebd1f12fcbda89788e31eee0c9', 4),
(279, 'A4-24', 'Available', 'QR_279_c2eb1e1984166ab79b634a8d8bcad847', 4),
(280, 'A4-25', 'Available', 'QR_280_08efab27263f28d566cdd764a0a8ebd4', 4),
(281, 'B1-01', 'Available', 'QR_281_5b5c4f150fa160dc6d1cf5dd0c48969d', 5),
(282, 'B1-02', 'Available', 'QR_282_2debe023aa23aac683f25da40b1bd00d', 5),
(283, 'B1-03', 'Available', 'QR_283_0f0b66fb562c716c122943db37cc5403', 5),
(284, 'B1-04', 'Available', 'QR_284_b0c1001c4b1f146e364a17cb603010f5', 5),
(285, 'B1-05', 'Available', 'QR_285_52ffaedb4dd7a141b0c2b0d57bee41cb', 5),
(286, 'B1-06', 'Available', 'QR_286_50ad261c87d0c9caf33a2bfe55b9353f', 5),
(287, 'B1-07', 'Available', 'QR_287_d23f452c08d673b4e06a2b0cafe89485', 5),
(288, 'B1-08', 'Available', 'QR_288_0dec37800f5b301cbbe83d3984dbb420', 5),
(289, 'B1-09', 'Available', 'QR_289_7abbfc48b63c6df20948b7c8d10cfa36', 5),
(290, 'B1-10', 'Available', 'QR_290_a30e2eb7e8eb8fcb3a5dba63f62b7758', 5),
(291, 'B1-11', 'Available', 'QR_291_e9014edd959bdd6ab857ebbd5e6cbb7c', 5),
(292, 'B1-12', 'Available', 'QR_292_14610eb7c08822db5881ce4ba86aa904', 5),
(293, 'B1-13', 'Available', 'QR_293_6b90e1a885886d79bbf52d87c43d3e34', 5),
(294, 'B1-14', 'Available', 'QR_294_81bc86a23ca335ebca7cdbb06828aad0', 5),
(295, 'B1-15', 'Available', 'QR_295_a4c5cbc54b482973852fabc916cd4d2a', 5),
(296, 'B1-16', 'Available', 'QR_296_3e1ca654208308b5eabba546937b3faa', 5),
(297, 'B1-17', 'Available', 'QR_297_191d1bec87bb6958e4adcb7620b9b512', 5),
(298, 'B1-18', 'Available', 'QR_298_a6dabc40affee9797dd3b85d957907b4', 5),
(299, 'B1-19', 'Available', 'QR_299_a4e434115491eedd06419ac6dde67e13', 5),
(300, 'B1-20', 'Available', 'QR_300_dc1177ef175ec392f0b9af0fa7a861af', 5),
(301, 'B1-21', 'Available', 'QR_301_0870b8e3bde53ed6b53e619c6c239113', 5),
(302, 'B1-22', 'Available', 'QR_302_a058543e2a7c9101fa0328cb9da991ad', 5),
(303, 'B1-23', 'Available', 'QR_303_906d899dd721d98134e5475453b9e522', 5),
(304, 'B1-24', 'Available', 'QR_304_a880483addd7e8df48652ac5eff62aec', 5),
(305, 'B1-25', 'Available', 'QR_305_2b8ef1bf92d946338d7fbf69085cc9c2', 5),
(306, 'B1-26', 'Available', 'QR_306_7ae453e722bbc9c4fc3dcd8b2f5185a2', 5),
(307, 'B1-27', 'Available', 'QR_307_e61f421e952cb64cf9370d8f78269368', 5),
(308, 'B1-28', 'Available', 'QR_308_42ee9fae79a1141e7a42775319494467', 5),
(309, 'B1-29', 'Available', 'QR_309_e53ecd494acdf5b1697664aafaba55e5', 5),
(310, 'B1-30', 'Available', 'QR_310_aa1485057cb4f0e0967802c3810550eb', 5),
(311, 'B1-31', 'Available', 'QR_311_32ca205f7d36dd69d8f3279d7846226c', 5),
(312, 'B1-32', 'Available', 'QR_312_59010af3afe640c8ff0a2b16312e007f', 5),
(313, 'B1-33', 'Available', 'QR_313_6b1c77e263dfb7062b5eedadd09b69e8', 5),
(314, 'B1-34', 'Available', 'QR_314_328154f550b6c3b01686424bacef8669', 5),
(315, 'B1-35', 'Available', 'QR_315_afeff7361601c651a36dcc51011f991c', 5),
(316, 'B1-36', 'Available', 'QR_316_af113469daa1300d64ea47104ed62e1b', 5),
(317, 'B1-37', 'Available', 'QR_317_e6fdb66d5a5bb35be31b9e2fc82bfcec', 5),
(318, 'B1-38', 'Available', 'QR_318_4a948a2602914fd28c7c42ba43361e82', 5),
(319, 'B1-39', 'Available', 'QR_319_d53bc7ba2f8d4ce45d0d0650f0446d6c', 5),
(320, 'B1-40', 'Available', 'QR_320_3e40795ecfcc6ebf45c6e92513caf0b4', 5),
(321, 'B2-01', 'Available', 'QR_321_8b8f62e4d069d7b82d7b8e8854a93ae9', 6),
(322, 'B2-02', 'Available', 'QR_322_f196c73a782dc276d62a0753637af761', 6),
(323, 'B2-03', 'Available', 'QR_323_1edecfedce6bf12886163a3fcf59f032', 6),
(324, 'B2-04', 'Available', 'QR_324_f7da127e74cf891770429878a6303722', 6),
(325, 'B2-05', 'Available', 'QR_325_bcade23c4bcf5399981735634654043b', 6),
(326, 'B2-06', 'Available', 'QR_326_ac02b27bcc5c2f33e6f5f64d38af2fac', 6),
(327, 'B2-07', 'Available', 'QR_327_b25cdccd23ca92c0ceb30e18233ae6e3', 6),
(328, 'B2-08', 'Available', 'QR_328_d0255645255d87e363cfa61eac03f4b6', 6),
(329, 'B2-09', 'Available', 'QR_329_43162dc63c3c73f6193b3427af936e98', 6),
(330, 'B2-10', 'Available', 'QR_330_9b7359a006ea2394c547b72fffb55090', 6),
(331, 'B2-11', 'Available', 'QR_331_883ef243571a22a364b1cf8b3c760ba7', 6),
(332, 'B2-12', 'Available', 'QR_332_b69bae0d1ec5683d731d09d54fec8a8c', 6),
(333, 'B2-13', 'Available', 'QR_333_5d0e6816df8edec5fa064f6b3659558d', 6),
(334, 'B2-14', 'Available', 'QR_334_8df2a8497a600a5c112a870490d2f86a', 6),
(335, 'B2-15', 'Available', 'QR_335_5677bbb945d3aa9d32998b53c22a4b0f', 6),
(336, 'B2-16', 'Available', 'QR_336_e71ac195b2091289328fefcdcfff598b', 6),
(337, 'B2-17', 'Available', 'QR_337_c901528be05cb9b1228a41eb0ba08586', 6),
(338, 'B2-18', 'Available', 'QR_338_1f8781f43535932574c4da0dcdb8e306', 6),
(339, 'B2-19', 'Available', 'QR_339_3c82a118facb97fc4f17995e0f1a5218', 6),
(340, 'B2-20', 'Available', 'QR_340_e01dce575d760f3f82c47b6c271eea35', 6),
(341, 'B2-21', 'Available', 'QR_341_bedee8afde3b22f4381ccc69bf901098', 6),
(342, 'B2-22', 'Available', 'QR_342_cdc6418b531fe014ed62897ad423ec44', 6),
(343, 'B2-23', 'Available', 'QR_343_62c7a40875c071008bfae6f4df059923', 6),
(344, 'B2-24', 'Available', 'QR_344_adf90d4576f302ade50a96e830555c48', 6),
(345, 'B2-25', 'Available', 'QR_345_4ebeb381b5114b26d0ddcaa6eca3b355', 6),
(346, 'B2-26', 'Available', 'QR_346_d7f558143b54b7c55ec0fa7edef12374', 6),
(347, 'B2-27', 'Available', 'QR_347_4cbfb45846bd497e395b2af8dbfe606e', 6),
(348, 'B2-28', 'Available', 'QR_348_a12074bcca6b0217e070039549716de7', 6),
(349, 'B2-29', 'Available', 'QR_349_b1a3363bdc890593e46938a64a48f4e9', 6),
(350, 'B2-30', 'Available', 'QR_350_e5115bd6d1b713b27d1a87185845f915', 6),
(351, 'B3-01', 'Available', 'QR_351_97db8b6102e29993261d61768e888e0c', 7),
(352, 'B3-02', 'Available', 'QR_352_a20e73eeaaa3d3012a1bb5c7600975a1', 7),
(353, 'B3-03', 'Available', 'QR_353_d7f5dfe12e5f275594ffedb9f4007cd7', 7),
(354, 'B3-04', 'Available', 'QR_354_99ec540fe4028023ab0eeba199806360', 7),
(355, 'B3-05', 'Available', 'QR_355_eeddb11bf15d4c40d558a1f6329f859f', 7),
(356, 'B3-06', 'Available', 'QR_356_34834e509de671deb8cbd2b13bb4b535', 7),
(357, 'B3-07', 'Available', 'QR_357_2ad126ffb56ec92da2f3e28f9cd01648', 7),
(358, 'B3-08', 'Available', 'QR_358_4e572effa14bd5f0421be4dbe3e45540', 7),
(359, 'B3-09', 'Available', 'QR_359_bd1703b28a14b21af86bc2be519b33a1', 7),
(360, 'B3-10', 'Available', 'QR_360_3e828fa9d3c099899efe375d9478508e', 7),
(361, 'B3-11', 'Available', 'QR_361_e7185b6be56df0c50cfe3f99333975b5', 7),
(362, 'B3-12', 'Available', 'QR_362_119abe5e7f2b813c63f3a4a46a7c0244', 7),
(363, 'B3-13', 'Available', 'QR_363_6e87865b5df0c541dcbef4540a23442d', 7),
(364, 'B3-14', 'Available', 'QR_364_f53520a8f55edfac2bbfa349d7b77699', 7),
(365, 'B3-15', 'Available', 'QR_365_443b87109b838ac579971aadd0c362b3', 7),
(366, 'B3-16', 'Available', 'QR_366_955351ecbdebb1bb4676bb75369c4fd5', 7),
(367, 'B3-17', 'Available', 'QR_367_d6f7ae4c7ec545b3191a14588cc58c97', 7),
(368, 'B3-18', 'Available', 'QR_368_686cebc9a3165cc02e556fff5ebf33d2', 7),
(369, 'B3-19', 'Available', 'QR_369_d6b565c26adc5f03e4e5ddef75e1d18d', 7),
(370, 'B3-20', 'Available', 'QR_370_db821ef1510f727add3c0dd3f29cfffd', 7),
(371, 'B3-21', 'Available', 'QR_371_28d5a208997ee46a720fae7043cdf1bc', 7),
(372, 'B3-22', 'Available', 'QR_372_b959df102ad3eed054ba58dc0a1fbca2', 7),
(373, 'B3-23', 'Available', 'QR_373_9ae0fe30064eccda6b98b678ed88c6ce', 7),
(374, 'B3-24', 'Available', 'QR_374_5e307805600ec6129a5d62fb898dba50', 7),
(375, 'B3-25', 'Available', 'QR_375_bc32b8aecd7e51fea8296ba616855f3f', 7),
(376, 'B3-26', 'Available', 'QR_376_596c3d93faed8c3bdc633d8f313df1cb', 7),
(377, 'B3-27', 'Available', 'QR_377_edbf76484e2225b6e9fbfbaadd3ce3ea', 7),
(378, 'B3-28', 'Available', 'QR_378_e614fc16380b30509249f0f333fc87e8', 7),
(379, 'B3-29', 'Available', 'QR_379_170754b572af33caff77bd68bb0e1d93', 7),
(380, 'B3-30', 'Available', 'QR_380_d2471b6a437563117b73ac7fe1e4cdbe', 7);

-- --------------------------------------------------------

--
-- Table structure for table `security`
--

CREATE TABLE `security` (
  `staff_id` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `badge_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security`
--

INSERT INTO `security` (`staff_id`, `name`, `email`, `phone`, `badge_number`) VALUES
('ss23015', 'Ali Hassan', 'Hassan@security.fk.edu', '+60123456777', NULL),
('ss23021', 'Abi Hurairah', 'abi@security.fk.edu', '+6011334466', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `student_id` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`student_id`, `name`, `email`, `phone`) VALUES
('s23015', 'Aiman Nasir', 'Aiman@student.fk.edu', '+60123456788'),
('s23021', 'Jamal Gulung', 'jamal@student.fk.edu', '0129384765');

-- --------------------------------------------------------

--
-- Table structure for table `traffic_summon`
--

CREATE TABLE `traffic_summon` (
  `traffic_summonID` int(15) NOT NULL,
  `staff_id` varchar(255) NOT NULL,
  `student_id` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `violation_typeID` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `traffic_summon`
--

INSERT INTO `traffic_summon` (`traffic_summonID`, `staff_id`, `student_id`, `date`, `violation_typeID`) VALUES
(1, 'ss23015', 's23021', '2025-12-23', 1),
(2, 'ss23015', 's23015', '2025-12-23', 1),
(3, 'ss23015', 's23015', '2025-12-23', 3),
(4, 'ss23015', 's23015', '2025-12-23', 3),
(5, 'ss23015', 's23015', '2025-12-23', 2),
(6, 'ss23015', 's23015', '2025-12-23', 3);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle`
--

CREATE TABLE `vehicle` (
  `vehicle_id` int(15) NOT NULL,
  `plate_num` varchar(15) NOT NULL,
  `type` varchar(20) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `status` varchar(15) NOT NULL DEFAULT 'Pending',
  `registration_date` date NOT NULL,
  `student_id` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle`
--

INSERT INTO `vehicle` (`vehicle_id`, `plate_num`, `type`, `brand`, `status`, `registration_date`, `student_id`) VALUES
(9, 'ABC1234', 'Car', 'Perodua Myvi', 'Approved', '2025-12-20', 's23015'),
(10, 'ABC123', 'Motorcycle', 'Honda Wave', 'Approved', '2025-12-20', 's23015'),
(12, 'ABD234', 'Car', 'Perodua Axia', 'Approved', '2025-12-22', 's23021');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_approval`
--

CREATE TABLE `vehicle_approval` (
  `approval_id` int(15) NOT NULL,
  `approve_by` varchar(255) DEFAULT NULL,
  `status` varchar(15) NOT NULL DEFAULT 'Pending',
  `approval_date` datetime DEFAULT NULL,
  `grant_file` mediumblob DEFAULT NULL,
  `vehicle_id` int(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_approval`
--

INSERT INTO `vehicle_approval` (`approval_id`, `approve_by`, `status`, `approval_date`, `grant_file`, `vehicle_id`) VALUES
(1, 'ss23015', 'Approved', '2025-12-21 17:05:05', 0x75706c6f6164732f6772616e74732f6772616e745f7332333031355f313736363136333934345f65653762363137652e706e67, 9),
(2, 'ss23015', 'Approved', '2025-12-23 03:30:18', 0x75706c6f6164732f6772616e74732f6772616e745f7332333031355f313736363136343036315f31386333303963622e706e67, 10),
(3, 'ss23015', 'Approved', '2025-12-22 22:12:44', 0x75706c6f6164732f6772616e74732f6772616e745f7332333032315f313736363431323636385f35373839383862652e706e67, 12);

-- --------------------------------------------------------

--
-- Table structure for table `violation_type`
--

CREATE TABLE `violation_type` (
  `violation_typeID` int(15) NOT NULL,
  `Description` varchar(255) NOT NULL,
  `Point` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `violation_type`
--

INSERT INTO `violation_type` (`violation_typeID`, `Description`, `Point`) VALUES
(1, 'Parking Violation', 10),
(2, 'Not comply in campus traffic regulations', 15),
(3, 'Accident caused', 20);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`);

--
-- Indexes for table `area_closure`
--
ALTER TABLE `area_closure`
  ADD PRIMARY KEY (`closure_ID`),
  ADD KEY `idx_closure_admin` (`admin_id`),
  ADD KEY `idx_closure_area` (`area_ID`);

--
-- Indexes for table `booking`
--
ALTER TABLE `booking`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `idx_booking_student` (`student_id`),
  ADD KEY `idx_booking_space` (`space_id`);

--
-- Indexes for table `demerit_point`
--
ALTER TABLE `demerit_point`
  ADD PRIMARY KEY (`demerit_id`),
  ADD KEY `idx_dp_student` (`student_id`),
  ADD KEY `idx_dp_summon` (`traffic_summonID`);

--
-- Indexes for table `login`
--
ALTER TABLE `login`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `parking`
--
ALTER TABLE `parking`
  ADD PRIMARY KEY (`parking_id`),
  ADD KEY `idx_parking_booking` (`booking_id`);

--
-- Indexes for table `parking_area`
--
ALTER TABLE `parking_area`
  ADD PRIMARY KEY (`Area_ID`),
  ADD KEY `idx_area_admin` (`admin_id`);

--
-- Indexes for table `parking_space`
--
ALTER TABLE `parking_space`
  ADD PRIMARY KEY (`space_id`),
  ADD KEY `idx_space_area` (`area_ID`);

--
-- Indexes for table `security`
--
ALTER TABLE `security`
  ADD PRIMARY KEY (`staff_id`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`student_id`);

--
-- Indexes for table `traffic_summon`
--
ALTER TABLE `traffic_summon`
  ADD PRIMARY KEY (`traffic_summonID`),
  ADD KEY `idx_ts_staff` (`staff_id`),
  ADD KEY `idx_ts_student` (`student_id`),
  ADD KEY `idx_ts_violation` (`violation_typeID`);

--
-- Indexes for table `vehicle`
--
ALTER TABLE `vehicle`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `uq_vehicle_plate_num` (`plate_num`),
  ADD KEY `idx_vehicle_student` (`student_id`);

--
-- Indexes for table `vehicle_approval`
--
ALTER TABLE `vehicle_approval`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `idx_va_vehicle` (`vehicle_id`),
  ADD KEY `idx_va_staff` (`approve_by`);

--
-- Indexes for table `violation_type`
--
ALTER TABLE `violation_type`
  ADD PRIMARY KEY (`violation_typeID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `area_closure`
--
ALTER TABLE `area_closure`
  MODIFY `closure_ID` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `booking`
--
ALTER TABLE `booking`
  MODIFY `booking_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `demerit_point`
--
ALTER TABLE `demerit_point`
  MODIFY `demerit_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `parking`
--
ALTER TABLE `parking`
  MODIFY `parking_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `parking_area`
--
ALTER TABLE `parking_area`
  MODIFY `Area_ID` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `parking_space`
--
ALTER TABLE `parking_space`
  MODIFY `space_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=381;

--
-- AUTO_INCREMENT for table `traffic_summon`
--
ALTER TABLE `traffic_summon`
  MODIFY `traffic_summonID` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicle`
--
ALTER TABLE `vehicle`
  MODIFY `vehicle_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `vehicle_approval`
--
ALTER TABLE `vehicle_approval`
  MODIFY `approval_id` int(15) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `fk_admin_login_username` FOREIGN KEY (`admin_id`) REFERENCES `login` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `area_closure`
--
ALTER TABLE `area_closure`
  ADD CONSTRAINT `fk_closure_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_closure_area` FOREIGN KEY (`area_ID`) REFERENCES `parking_area` (`Area_ID`) ON UPDATE CASCADE;

--
-- Constraints for table `booking`
--
ALTER TABLE `booking`
  ADD CONSTRAINT `fk_booking_space` FOREIGN KEY (`space_id`) REFERENCES `parking_space` (`space_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_booking_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `demerit_point`
--
ALTER TABLE `demerit_point`
  ADD CONSTRAINT `fk_dp_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dp_summon` FOREIGN KEY (`traffic_summonID`) REFERENCES `traffic_summon` (`traffic_summonID`) ON UPDATE CASCADE;

--
-- Constraints for table `parking`
--
ALTER TABLE `parking`
  ADD CONSTRAINT `fk_parking_booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`booking_id`) ON UPDATE CASCADE;

--
-- Constraints for table `parking_area`
--
ALTER TABLE `parking_area`
  ADD CONSTRAINT `fk_parking_area_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON UPDATE CASCADE;

--
-- Constraints for table `parking_space`
--
ALTER TABLE `parking_space`
  ADD CONSTRAINT `fk_space_area` FOREIGN KEY (`area_ID`) REFERENCES `parking_area` (`Area_ID`) ON UPDATE CASCADE;

--
-- Constraints for table `security`
--
ALTER TABLE `security`
  ADD CONSTRAINT `fk_security_login_username` FOREIGN KEY (`staff_id`) REFERENCES `login` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student`
--
ALTER TABLE `student`
  ADD CONSTRAINT `fk_student_login_username` FOREIGN KEY (`student_id`) REFERENCES `login` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `traffic_summon`
--
ALTER TABLE `traffic_summon`
  ADD CONSTRAINT `fk_ts_staff` FOREIGN KEY (`staff_id`) REFERENCES `security` (`staff_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ts_student` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ts_violation` FOREIGN KEY (`violation_typeID`) REFERENCES `violation_type` (`violation_typeID`) ON UPDATE CASCADE;

--
-- Constraints for table `vehicle`
--
ALTER TABLE `vehicle`
  ADD CONSTRAINT `fk_vehicle_student_v2` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON UPDATE CASCADE;

--
-- Constraints for table `vehicle_approval`
--
ALTER TABLE `vehicle_approval`
  ADD CONSTRAINT `fk_va_staff_v2` FOREIGN KEY (`approve_by`) REFERENCES `security` (`staff_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_va_vehicle_v2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicle` (`vehicle_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
