-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 29-05-2026 a las 17:50:56
-- Versión del servidor: 8.0.42-0ubuntu0.24.10.1
-- Versión de PHP: 8.4.7

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `tr_app_development`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `accesses`
--

CREATE TABLE `accesses` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ar_internal_metadata`
--

CREATE TABLE `ar_internal_metadata` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `audits`
--

CREATE TABLE `audits` (
  `id` bigint NOT NULL,
  `auditable_id` int DEFAULT NULL,
  `auditable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `associated_id` int DEFAULT NULL,
  `associated_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `user_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `audited_changes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `version` int DEFAULT '0',
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `remote_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `request_uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bornals`
--

CREATE TABLE `bornals` (
  `id` bigint NOT NULL,
  `transformer_id` int DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `num_bor` decimal(10,0) DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `chromatographicals`
--

CREATE TABLE `chromatographicals` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` datetime DEFAULT NULL,
  `num_hid` decimal(10,2) DEFAULT NULL,
  `num_oxi` decimal(15,2) DEFAULT NULL,
  `num_nit` decimal(15,2) DEFAULT NULL,
  `num_met` decimal(10,2) DEFAULT NULL,
  `num_mon` decimal(10,2) DEFAULT NULL,
  `num_dio` decimal(10,2) DEFAULT NULL,
  `num_eti` decimal(15,2) DEFAULT NULL,
  `num_eta` decimal(10,2) DEFAULT NULL,
  `num_ace` decimal(10,2) DEFAULT NULL,
  `diag_status` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `chromatographical_dga_diags`
--

CREATE TABLE `chromatographical_dga_diags` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `eval_first` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `eval_second` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `eval_third` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `eval_fourth` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `chromatographical_duvals`
--

CREATE TABLE `chromatographical_duvals` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `triangle_diag_first` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT 'PD',
  `triangle_diag_second` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `triangle_diag_third` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `pentagon_diag_first` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT 'PD',
  `pentagon_diag_second` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `pentagon_diag_third` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `comment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comments`
--

CREATE TABLE `comments` (
  `id` bigint NOT NULL,
  `post_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `rate` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `conmutation_types`
--

CREATE TABLE `conmutation_types` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `connection_types`
--

CREATE TABLE `connection_types` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `countries`
--

CREATE TABLE `countries` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `short_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `customers`
--

CREATE TABLE `customers` (
  `id` bigint NOT NULL,
  `country_id` bigint DEFAULT NULL,
  `num_doc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `contact_info` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `avatar` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `db_system_id` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `customer_areas`
--

CREATE TABLE `customer_areas` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `customer_location_id` bigint DEFAULT NULL,
  `customer_id` bigint DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `customer_locations`
--

CREATE TABLE `customer_locations` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `customer_id` bigint DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `customer_substations`
--

CREATE TABLE `customer_substations` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `customer_area_id` bigint DEFAULT NULL,
  `customer_id` bigint DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanados`
--

CREATE TABLE `devanados` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanado_details`
--

CREATE TABLE `devanado_details` (
  `id` bigint NOT NULL,
  `devanado_id` bigint DEFAULT NULL,
  `devanado_flow_id` bigint DEFAULT NULL,
  `date_devanado` date DEFAULT NULL,
  `col1_val` decimal(10,5) DEFAULT NULL,
  `col2_val` decimal(10,5) DEFAULT NULL,
  `col3_val` decimal(10,5) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanado_flows`
--

CREATE TABLE `devanado_flows` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanado_templates`
--

CREATE TABLE `devanado_templates` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `unit_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanado_template_details`
--

CREATE TABLE `devanado_template_details` (
  `id` bigint NOT NULL,
  `devanado_template_id` bigint DEFAULT NULL,
  `devanado_flow_id` bigint DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `devanado_template_transformers`
--

CREATE TABLE `devanado_template_transformers` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `devanado_template_id` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `duvals`
--

CREATE TABLE `duvals` (
  `id` bigint NOT NULL,
  `duval_type_id` int DEFAULT NULL,
  `graph_type` int DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `short_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `image_duval` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `electricals`
--

CREATE TABLE `electricals` (
  `id` bigint NOT NULL,
  `transformer_id` int DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `val_a1` decimal(10,0) DEFAULT NULL,
  `va_a2` decimal(10,0) DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factors`
--

CREATE TABLE `factors` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `num_fac` decimal(10,5) DEFAULT NULL,
  `diag_status` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `furanos`
--

CREATE TABLE `furanos` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` datetime DEFAULT NULL,
  `num_fal` decimal(10,5) DEFAULT NULL,
  `num_hme` decimal(10,5) DEFAULT NULL,
  `num_ace` decimal(10,5) DEFAULT NULL,
  `num_mfu` decimal(10,5) DEFAULT NULL,
  `num_fua` decimal(10,5) DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gas`
--

CREATE TABLE `gas` (
  `id` bigint NOT NULL,
  `transformer_test_id` bigint DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `short_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gas_keys`
--

CREATE TABLE `gas_keys` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `state` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ieee_diags`
--

CREATE TABLE `ieee_diags` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `state` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `month_period` int DEFAULT NULL,
  `ppm_hid` int DEFAULT NULL,
  `ppm_met` int DEFAULT NULL,
  `ppm_mon` int DEFAULT NULL,
  `ppm_dio` int DEFAULT NULL,
  `ppm_eti` int DEFAULT NULL,
  `ppm_eta` int DEFAULT NULL,
  `ppm_ace` int DEFAULT NULL,
  `ratio` decimal(10,2) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ieee_diag_details`
--

CREATE TABLE `ieee_diag_details` (
  `id` bigint NOT NULL,
  `chromatographical_id` bigint DEFAULT NULL,
  `ieee_diag_id` bigint DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `import_cromas`
--

CREATE TABLE `import_cromas` (
  `id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `date_rehearsal` date DEFAULT NULL,
  `num_serie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_hid` decimal(10,2) DEFAULT NULL,
  `num_oxi` decimal(15,2) DEFAULT NULL,
  `num_nit` decimal(15,2) DEFAULT NULL,
  `num_met` decimal(10,2) DEFAULT NULL,
  `num_mon` decimal(10,2) DEFAULT NULL,
  `num_dio` decimal(10,2) DEFAULT NULL,
  `num_eti` decimal(15,2) DEFAULT NULL,
  `num_eta` decimal(10,2) DEFAULT NULL,
  `num_ace` decimal(10,2) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `was_upload` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `import_transformers`
--

CREATE TABLE `import_transformers` (
  `id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `customer_substation_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_serie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_tag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `age` int DEFAULT NULL,
  `num_fas` int DEFAULT NULL,
  `num_vol` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_pot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `mark_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `oil_type_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `transformer_type_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `conmutation_type_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `transformer_preservation_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `connection_type_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `was_upload` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `marks`
--

CREATE TABLE `marks` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `meetings`
--

CREATE TABLE `meetings` (
  `id` bigint NOT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `meet_events`
--

CREATE TABLE `meet_events` (
  `id` bigint NOT NULL,
  `transformer_id` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `newspapers`
--

CREATE TABLE `newspapers` (
  `id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `state` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `oil_types`
--

CREATE TABLE `oil_types` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `option_posts`
--

CREATE TABLE `option_posts` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `physicals`
--

CREATE TABLE `physicals` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `date_rehearsal` datetime DEFAULT NULL,
  `num_acid` decimal(10,5) DEFAULT NULL,
  `num_pot` decimal(10,5) DEFAULT NULL,
  `num_pot2` decimal(10,5) DEFAULT NULL,
  `num_rig` decimal(10,5) DEFAULT NULL,
  `num_rig2` decimal(10,5) DEFAULT NULL,
  `num_ten` decimal(10,5) DEFAULT NULL,
  `num_wat` decimal(10,5) DEFAULT NULL,
  `diag_status` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `physical_comments`
--

CREATE TABLE `physical_comments` (
  `id` bigint NOT NULL,
  `physical_post_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `rate` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `physical_posts`
--

CREATE TABLE `physical_posts` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `physical_id` bigint DEFAULT NULL,
  `type_comment_id` bigint DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `rate` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `physical_trials`
--

CREATE TABLE `physical_trials` (
  `id` bigint NOT NULL,
  `transformer_test_id` bigint DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `short_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `unit_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `posts`
--

CREATE TABLE `posts` (
  `id` bigint NOT NULL,
  `transformer_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `chromatographical_id` bigint DEFAULT NULL,
  `type_comment_id` bigint DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `rate` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profiles`
--

CREATE TABLE `profiles` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `country_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profile_accesses`
--

CREATE TABLE `profile_accesses` (
  `id` bigint NOT NULL,
  `access_id` bigint DEFAULT NULL,
  `profile_id` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profile_countries`
--

CREATE TABLE `profile_countries` (
  `id` int NOT NULL,
  `profile_id` int DEFAULT NULL,
  `country_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `reports`
--

CREATE TABLE `reports` (
  `id` bigint NOT NULL,
  `customer_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `sheet1` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet2` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet3` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet4` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet5` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet6` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `sheet7` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `avatar` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `report_details`
--

CREATE TABLE `report_details` (
  `id` bigint NOT NULL,
  `report_id` int DEFAULT NULL,
  `transformer_id` int DEFAULT NULL,
  `ia_generated_at` datetime DEFAULT NULL,
  `ia_comment` longtext COLLATE utf8mb4_spanish_ci,
  `comment_cro` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `comment_fiq` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `conclusion_basic` longtext COLLATE utf8mb4_spanish_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `search_transformer_per_users`
--

CREATE TABLE `search_transformer_per_users` (
  `id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `transformer_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transformers`
--

CREATE TABLE `transformers` (
  `id` bigint NOT NULL,
  `customer_substation_id` bigint DEFAULT NULL,
  `transformer_type_id` bigint DEFAULT NULL,
  `connection_type_id` bigint DEFAULT NULL,
  `conmutation_type_id` bigint DEFAULT NULL,
  `transformer_preservation_id` bigint DEFAULT NULL,
  `oil_type_id` bigint DEFAULT NULL,
  `mark_id` bigint DEFAULT NULL,
  `num_serie` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_tag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_vol` decimal(10,5) DEFAULT NULL,
  `num_pot` decimal(10,5) DEFAULT NULL,
  `age` int DEFAULT NULL,
  `num_fas` int DEFAULT NULL,
  `num_tap` int DEFAULT NULL,
  `num_health` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `state_health` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `color_health` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `con_cro` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `rec_cro` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `con_fiq` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `rec_fiq` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `duval_triangle_first_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `duval_pentagon_first_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `has_special_testing_cro` int DEFAULT '0',
  `has_special_testing_fiq` int DEFAULT '0',
  `has_special_testing_fur` int DEFAULT '0',
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transformer_graphs`
--

CREATE TABLE `transformer_graphs` (
  `id` int NOT NULL,
  `code_graph` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `url_graph_maker` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `date_url_created` date DEFAULT NULL,
  `day_expiration` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transformer_preservations`
--

CREATE TABLE `transformer_preservations` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transformer_tests`
--

CREATE TABLE `transformer_tests` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `transformer_types`
--

CREATE TABLE `transformer_types` (
  `id` bigint NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `type_comments`
--

CREATE TABLE `type_comments` (
  `id` bigint NOT NULL,
  `option_post_id` bigint DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `num_doc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `lastname1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `lastname2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `cellphone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `real_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `hashed_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `salt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `profile_id` bigint DEFAULT NULL,
  `customer_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci,
  `country_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `password_reset_token_date` datetime DEFAULT NULL,
  `password_reset_change_date` datetime DEFAULT NULL,
  `password_reset_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `password_expires_after` datetime DEFAULT NULL,
  `authentication_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `last_signed_in_on` datetime DEFAULT NULL,
  `signed_up_on` datetime DEFAULT NULL,
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `state` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_customers`
--

CREATE TABLE `user_customers` (
  `id` bigint NOT NULL,
  `customer_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_notifications`
--

CREATE TABLE `user_notifications` (
  `id` bigint NOT NULL,
  `transformer_id` int DEFAULT NULL,
  `user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `date_notification` date DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `state` int DEFAULT NULL,
  `deleted` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_1_transformer_id_6` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_per_at_1` varchar(38)
,`dif_per_at_2` varchar(38)
,`dif_per_at_3` varchar(38)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_1_transformer_id_9` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_per_at_1` varchar(38)
,`dif_per_at_2` varchar(38)
,`dif_per_at_3` varchar(38)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_1_transformer_id_75` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_per_at_1` varchar(38)
,`dif_per_at_2` varchar(38)
,`dif_per_at_3` varchar(38)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_1_transformer_id_147` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_per_at_1` varchar(38)
,`dif_per_at_2` varchar(38)
,`dif_per_at_3` varchar(38)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_1_transformer_id_501` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_per_at_1` varchar(38)
,`dif_per_at_2` varchar(38)
,`dif_per_at_3` varchar(38)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_2_transformer_id_6` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_per_bt1_1` varchar(38)
,`dif_per_bt1_2` varchar(38)
,`dif_per_bt1_3` varchar(38)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_2_transformer_id_9` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_per_bt1_1` varchar(38)
,`dif_per_bt1_2` varchar(38)
,`dif_per_bt1_3` varchar(38)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_2_transformer_id_75` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_per_bt1_1` varchar(38)
,`dif_per_bt1_2` varchar(38)
,`dif_per_bt1_3` varchar(38)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_2_transformer_id_147` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_per_bt1_1` varchar(38)
,`dif_per_bt1_2` varchar(38)
,`dif_per_bt1_3` varchar(38)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_2_transformer_id_501` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_per_bt1_1` varchar(38)
,`dif_per_bt1_2` varchar(38)
,`dif_per_bt1_3` varchar(38)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_3_transformer_id_6` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_per_bt2_1` varchar(38)
,`dif_per_bt2_2` varchar(38)
,`dif_per_bt2_3` varchar(38)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_3_transformer_id_9` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_per_bt2_1` varchar(38)
,`dif_per_bt2_2` varchar(38)
,`dif_per_bt2_3` varchar(38)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_3_transformer_id_75` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_per_bt2_1` varchar(38)
,`dif_per_bt2_2` varchar(38)
,`dif_per_bt2_3` varchar(38)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_3_transformer_id_147` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_per_bt2_1` varchar(38)
,`dif_per_bt2_2` varchar(38)
,`dif_per_bt2_3` varchar(38)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_3_transformer_id_501` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_per_bt2_1` varchar(38)
,`dif_per_bt2_2` varchar(38)
,`dif_per_bt2_3` varchar(38)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_4_transformer_id_6` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`dif_per_bt3_1` varchar(38)
,`dif_per_bt3_2` varchar(38)
,`dif_per_bt3_3` varchar(38)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_4_transformer_id_9` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`dif_per_bt3_1` varchar(38)
,`dif_per_bt3_2` varchar(38)
,`dif_per_bt3_3` varchar(38)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_4_transformer_id_75` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`dif_per_bt3_1` varchar(38)
,`dif_per_bt3_2` varchar(38)
,`dif_per_bt3_3` varchar(38)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_4_transformer_id_147` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`dif_per_bt3_1` varchar(38)
,`dif_per_bt3_2` varchar(38)
,`dif_per_bt3_3` varchar(38)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_devanado_flow_id_4_transformer_id_501` (
`date_devanado` date
,`devanado_id` bigint
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`dif_per_bt3_1` varchar(38)
,`dif_per_bt3_2` varchar(38)
,`dif_per_bt3_3` varchar(38)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_transformer_id_6`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_transformer_id_6` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_transformer_id_9`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_transformer_id_9` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_transformer_id_75`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_transformer_id_75` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_transformer_id_147`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_transformer_id_147` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `view_devanado_details_by_transformer_id_501`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `view_devanado_details_by_transformer_id_501` (
`date_devanado` date
,`devanado_id` bigint
,`dif_at_1` decimal(11,5)
,`dif_at_2` decimal(11,5)
,`dif_at_3` decimal(11,5)
,`dif_bt1_1` decimal(11,5)
,`dif_bt1_2` decimal(11,5)
,`dif_bt1_3` decimal(11,5)
,`dif_bt2_1` decimal(11,5)
,`dif_bt2_2` decimal(11,5)
,`dif_bt2_3` decimal(11,5)
,`dif_bt3_1` decimal(11,5)
,`dif_bt3_2` decimal(11,5)
,`dif_bt3_3` decimal(11,5)
,`per_at_1` decimal(19,4)
,`per_at_2` decimal(19,4)
,`per_at_3` decimal(19,4)
,`per_bt1_1` decimal(19,4)
,`per_bt1_2` decimal(19,4)
,`per_bt1_3` decimal(19,4)
,`per_bt2_1` decimal(19,4)
,`per_bt2_2` decimal(19,4)
,`per_bt2_3` decimal(19,4)
,`per_bt3_1` decimal(19,4)
,`per_bt3_2` decimal(19,4)
,`per_bt3_3` decimal(19,4)
,`transformer_id` bigint
);

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 1) and (`devanados`.`transformer_id` = 6))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_at_1`,round(max(`c`.`diffPercent1`),4) AS `per_at_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_at_1`,max(`c`.`diff2`) AS `dif_at_2`,round(max(`c`.`diffPercent2`),4) AS `per_at_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_at_2`,max(`c`.`diff3`) AS `dif_at_3`,round(max(`c`.`diffPercent3`),4) AS `per_at_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_at_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 1) and (`devanados`.`transformer_id` = 9))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_at_1`,round(max(`c`.`diffPercent1`),4) AS `per_at_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_at_1`,max(`c`.`diff2`) AS `dif_at_2`,round(max(`c`.`diffPercent2`),4) AS `per_at_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_at_2`,max(`c`.`diff3`) AS `dif_at_3`,round(max(`c`.`diffPercent3`),4) AS `per_at_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_at_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 1) and (`devanados`.`transformer_id` = 75))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_at_1`,round(max(`c`.`diffPercent1`),4) AS `per_at_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_at_1`,max(`c`.`diff2`) AS `dif_at_2`,round(max(`c`.`diffPercent2`),4) AS `per_at_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_at_2`,max(`c`.`diff3`) AS `dif_at_3`,round(max(`c`.`diffPercent3`),4) AS `per_at_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_at_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 1) and (`devanados`.`transformer_id` = 147))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_at_1`,round(max(`c`.`diffPercent1`),4) AS `per_at_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_at_1`,max(`c`.`diff2`) AS `dif_at_2`,round(max(`c`.`diffPercent2`),4) AS `per_at_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_at_2`,max(`c`.`diff3`) AS `dif_at_3`,round(max(`c`.`diffPercent3`),4) AS `per_at_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_at_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 1) and (`devanados`.`transformer_id` = 501))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_at_1`,round(max(`c`.`diffPercent1`),4) AS `per_at_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_at_1`,max(`c`.`diff2`) AS `dif_at_2`,round(max(`c`.`diffPercent2`),4) AS `per_at_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_at_2`,max(`c`.`diff3`) AS `dif_at_3`,round(max(`c`.`diffPercent3`),4) AS `per_at_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_at_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 2) and (`devanados`.`transformer_id` = 6))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt1_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt1_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt1_1`,max(`c`.`diff2`) AS `dif_bt1_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt1_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt1_2`,max(`c`.`diff3`) AS `dif_bt1_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt1_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt1_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 2) and (`devanados`.`transformer_id` = 9))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt1_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt1_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt1_1`,max(`c`.`diff2`) AS `dif_bt1_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt1_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt1_2`,max(`c`.`diff3`) AS `dif_bt1_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt1_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt1_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 2) and (`devanados`.`transformer_id` = 75))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt1_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt1_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt1_1`,max(`c`.`diff2`) AS `dif_bt1_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt1_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt1_2`,max(`c`.`diff3`) AS `dif_bt1_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt1_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt1_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 2) and (`devanados`.`transformer_id` = 147))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt1_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt1_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt1_1`,max(`c`.`diff2`) AS `dif_bt1_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt1_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt1_2`,max(`c`.`diff3`) AS `dif_bt1_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt1_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt1_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 2) and (`devanados`.`transformer_id` = 501))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt1_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt1_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt1_1`,max(`c`.`diff2`) AS `dif_bt1_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt1_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt1_2`,max(`c`.`diff3`) AS `dif_bt1_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt1_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt1_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 3) and (`devanados`.`transformer_id` = 6))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt2_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt2_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt2_1`,max(`c`.`diff2`) AS `dif_bt2_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt2_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt2_2`,max(`c`.`diff3`) AS `dif_bt2_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt2_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt2_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 3) and (`devanados`.`transformer_id` = 9))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt2_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt2_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt2_1`,max(`c`.`diff2`) AS `dif_bt2_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt2_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt2_2`,max(`c`.`diff3`) AS `dif_bt2_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt2_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt2_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 3) and (`devanados`.`transformer_id` = 75))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt2_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt2_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt2_1`,max(`c`.`diff2`) AS `dif_bt2_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt2_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt2_2`,max(`c`.`diff3`) AS `dif_bt2_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt2_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt2_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 3) and (`devanados`.`transformer_id` = 147))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt2_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt2_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt2_1`,max(`c`.`diff2`) AS `dif_bt2_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt2_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt2_2`,max(`c`.`diff3`) AS `dif_bt2_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt2_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt2_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 3) and (`devanados`.`transformer_id` = 501))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt2_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt2_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt2_1`,max(`c`.`diff2`) AS `dif_bt2_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt2_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt2_2`,max(`c`.`diff3`) AS `dif_bt2_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt2_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt2_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 4) and (`devanados`.`transformer_id` = 6))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt3_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt3_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt3_1`,max(`c`.`diff2`) AS `dif_bt3_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt3_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt3_2`,max(`c`.`diff3`) AS `dif_bt3_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt3_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt3_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 4) and (`devanados`.`transformer_id` = 9))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt3_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt3_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt3_1`,max(`c`.`diff2`) AS `dif_bt3_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt3_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt3_2`,max(`c`.`diff3`) AS `dif_bt3_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt3_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt3_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 4) and (`devanados`.`transformer_id` = 75))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt3_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt3_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt3_1`,max(`c`.`diff2`) AS `dif_bt3_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt3_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt3_2`,max(`c`.`diff3`) AS `dif_bt3_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt3_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt3_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 4) and (`devanados`.`transformer_id` = 147))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt3_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt3_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt3_1`,max(`c`.`diff2`) AS `dif_bt3_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt3_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt3_2`,max(`c`.`diff3`) AS `dif_bt3_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt3_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt3_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`
--
DROP TABLE IF EXISTS `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`  AS WITH     `cte` as (select `devanados`.`transformer_id` AS `transformer_id`,`a`.`devanado_id` AS `devanado_id`,`a`.`date_devanado` AS `date_devanado`,`a`.`col1_val` AS `col1_val`,`a`.`col2_val` AS `col2_val`,`a`.`col3_val` AS `col3_val`,row_number() OVER (PARTITION BY `a`.`date_devanado` ORDER BY `a`.`date_devanado` desc,`a`.`id` )  AS `rn` from (`devanado_details` `a` join `devanados` on((`devanados`.`id` = `a`.`devanado_id`))) where ((`a`.`devanado_flow_id` = 4) and (`devanados`.`transformer_id` = 501))) select `c`.`transformer_id` AS `transformer_id`,`c`.`devanado_id` AS `devanado_id`,`c`.`date_devanado` AS `date_devanado`,max(`c`.`diff1`) AS `dif_bt3_1`,round(max(`c`.`diffPercent1`),4) AS `per_bt3_1`,concat(max(`c`.`diff1`),' (',round(max(`c`.`diffPercent1`),4),'%)') AS `dif_per_bt3_1`,max(`c`.`diff2`) AS `dif_bt3_2`,round(max(`c`.`diffPercent2`),4) AS `per_bt3_2`,concat(max(`c`.`diff2`),' (',round(max(`c`.`diffPercent2`),4),'%)') AS `dif_per_bt3_2`,max(`c`.`diff3`) AS `dif_bt3_3`,round(max(`c`.`diffPercent3`),4) AS `per_bt3_3`,concat(max(`c`.`diff3`),' (',round(max(`c`.`diffPercent3`),4),'%)') AS `dif_per_bt3_3` from (select `b`.`transformer_id` AS `transformer_id`,`b`.`devanado_id` AS `devanado_id`,`b`.`date_devanado` AS `date_devanado`,(`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) AS `diff1`,(((`b`.`col1_val` - coalesce(lead(`b`.`col1_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col1_val`)) / `b`.`col1_val`) * 100) AS `diffPercent1`,(`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) AS `diff2`,(((`b`.`col2_val` - coalesce(lead(`b`.`col2_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col2_val`)) / `b`.`col2_val`) * 100) AS `diffPercent2`,(`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) AS `diff3`,(((`b`.`col3_val` - coalesce(lead(`b`.`col3_val`) OVER (PARTITION BY `b`.`rn` ORDER BY `b`.`date_devanado` desc ) ,`b`.`col3_val`)) / `b`.`col3_val`) * 100) AS `diffPercent3` from (`cte` `b` join `devanados` on((`devanados`.`id` = `b`.`devanado_id`)))) `c` group by `c`.`date_devanado`,`c`.`devanado_id` order by `c`.`date_devanado` desc  ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_transformer_id_6`
--
DROP TABLE IF EXISTS `view_devanado_details_by_transformer_id_6`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_transformer_id_6`  AS SELECT `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`transformer_id` AS `transformer_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`devanado_id` AS `devanado_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`date_devanado` AS `date_devanado`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`dif_at_1` AS `dif_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`per_at_1` AS `per_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`dif_at_2` AS `dif_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`per_at_2` AS `per_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`dif_at_3` AS `dif_at_3`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`per_at_3` AS `per_at_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`dif_bt1_1` AS `dif_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`per_bt1_1` AS `per_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`dif_bt1_2` AS `dif_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`per_bt1_2` AS `per_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`dif_bt1_3` AS `dif_bt1_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`per_bt1_3` AS `per_bt1_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`dif_bt2_1` AS `dif_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`per_bt2_1` AS `per_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`dif_bt2_2` AS `dif_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`per_bt2_2` AS `per_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`dif_bt2_3` AS `dif_bt2_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`per_bt2_3` AS `per_bt2_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`dif_bt3_1` AS `dif_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`per_bt3_1` AS `per_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`dif_bt3_2` AS `dif_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`per_bt3_2` AS `per_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`dif_bt3_3` AS `dif_bt3_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`per_bt3_3` AS `per_bt3_3` FROM (((`view_devanado_details_by_devanado_flow_id_1_transformer_id_6` join `view_devanado_details_by_devanado_flow_id_2_transformer_id_6` on((`view_devanado_details_by_devanado_flow_id_2_transformer_id_6`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_3_transformer_id_6` on((`view_devanado_details_by_devanado_flow_id_3_transformer_id_6`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_4_transformer_id_6` on((`view_devanado_details_by_devanado_flow_id_4_transformer_id_6`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_6`.`date_devanado`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_transformer_id_9`
--
DROP TABLE IF EXISTS `view_devanado_details_by_transformer_id_9`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_transformer_id_9`  AS SELECT `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`transformer_id` AS `transformer_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`devanado_id` AS `devanado_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`date_devanado` AS `date_devanado`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`dif_at_1` AS `dif_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`per_at_1` AS `per_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`dif_at_2` AS `dif_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`per_at_2` AS `per_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`dif_at_3` AS `dif_at_3`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`per_at_3` AS `per_at_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`dif_bt1_1` AS `dif_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`per_bt1_1` AS `per_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`dif_bt1_2` AS `dif_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`per_bt1_2` AS `per_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`dif_bt1_3` AS `dif_bt1_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`per_bt1_3` AS `per_bt1_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`dif_bt2_1` AS `dif_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`per_bt2_1` AS `per_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`dif_bt2_2` AS `dif_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`per_bt2_2` AS `per_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`dif_bt2_3` AS `dif_bt2_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`per_bt2_3` AS `per_bt2_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`dif_bt3_1` AS `dif_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`per_bt3_1` AS `per_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`dif_bt3_2` AS `dif_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`per_bt3_2` AS `per_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`dif_bt3_3` AS `dif_bt3_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`per_bt3_3` AS `per_bt3_3` FROM (((`view_devanado_details_by_devanado_flow_id_1_transformer_id_9` join `view_devanado_details_by_devanado_flow_id_2_transformer_id_9` on((`view_devanado_details_by_devanado_flow_id_2_transformer_id_9`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_3_transformer_id_9` on((`view_devanado_details_by_devanado_flow_id_3_transformer_id_9`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_4_transformer_id_9` on((`view_devanado_details_by_devanado_flow_id_4_transformer_id_9`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_9`.`date_devanado`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_transformer_id_75`
--
DROP TABLE IF EXISTS `view_devanado_details_by_transformer_id_75`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_transformer_id_75`  AS SELECT `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`transformer_id` AS `transformer_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`devanado_id` AS `devanado_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`date_devanado` AS `date_devanado`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`dif_at_1` AS `dif_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`per_at_1` AS `per_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`dif_at_2` AS `dif_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`per_at_2` AS `per_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`dif_at_3` AS `dif_at_3`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`per_at_3` AS `per_at_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`dif_bt1_1` AS `dif_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`per_bt1_1` AS `per_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`dif_bt1_2` AS `dif_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`per_bt1_2` AS `per_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`dif_bt1_3` AS `dif_bt1_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`per_bt1_3` AS `per_bt1_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`dif_bt2_1` AS `dif_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`per_bt2_1` AS `per_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`dif_bt2_2` AS `dif_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`per_bt2_2` AS `per_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`dif_bt2_3` AS `dif_bt2_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`per_bt2_3` AS `per_bt2_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`dif_bt3_1` AS `dif_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`per_bt3_1` AS `per_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`dif_bt3_2` AS `dif_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`per_bt3_2` AS `per_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`dif_bt3_3` AS `dif_bt3_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`per_bt3_3` AS `per_bt3_3` FROM (((`view_devanado_details_by_devanado_flow_id_1_transformer_id_75` join `view_devanado_details_by_devanado_flow_id_2_transformer_id_75` on((`view_devanado_details_by_devanado_flow_id_2_transformer_id_75`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_3_transformer_id_75` on((`view_devanado_details_by_devanado_flow_id_3_transformer_id_75`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_4_transformer_id_75` on((`view_devanado_details_by_devanado_flow_id_4_transformer_id_75`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_75`.`date_devanado`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_transformer_id_147`
--
DROP TABLE IF EXISTS `view_devanado_details_by_transformer_id_147`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_transformer_id_147`  AS SELECT `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`transformer_id` AS `transformer_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`devanado_id` AS `devanado_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`date_devanado` AS `date_devanado`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`dif_at_1` AS `dif_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`per_at_1` AS `per_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`dif_at_2` AS `dif_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`per_at_2` AS `per_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`dif_at_3` AS `dif_at_3`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`per_at_3` AS `per_at_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`dif_bt1_1` AS `dif_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`per_bt1_1` AS `per_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`dif_bt1_2` AS `dif_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`per_bt1_2` AS `per_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`dif_bt1_3` AS `dif_bt1_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`per_bt1_3` AS `per_bt1_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`dif_bt2_1` AS `dif_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`per_bt2_1` AS `per_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`dif_bt2_2` AS `dif_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`per_bt2_2` AS `per_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`dif_bt2_3` AS `dif_bt2_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`per_bt2_3` AS `per_bt2_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`dif_bt3_1` AS `dif_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`per_bt3_1` AS `per_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`dif_bt3_2` AS `dif_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`per_bt3_2` AS `per_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`dif_bt3_3` AS `dif_bt3_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`per_bt3_3` AS `per_bt3_3` FROM (((`view_devanado_details_by_devanado_flow_id_1_transformer_id_147` join `view_devanado_details_by_devanado_flow_id_2_transformer_id_147` on((`view_devanado_details_by_devanado_flow_id_2_transformer_id_147`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_3_transformer_id_147` on((`view_devanado_details_by_devanado_flow_id_3_transformer_id_147`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_4_transformer_id_147` on((`view_devanado_details_by_devanado_flow_id_4_transformer_id_147`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_147`.`date_devanado`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `view_devanado_details_by_transformer_id_501`
--
DROP TABLE IF EXISTS `view_devanado_details_by_transformer_id_501`;

CREATE ALGORITHM=UNDEFINED DEFINER=`xwcamor`@`%` SQL SECURITY DEFINER VIEW `view_devanado_details_by_transformer_id_501`  AS SELECT `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`transformer_id` AS `transformer_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`devanado_id` AS `devanado_id`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`date_devanado` AS `date_devanado`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`dif_at_1` AS `dif_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`per_at_1` AS `per_at_1`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`dif_at_2` AS `dif_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`per_at_2` AS `per_at_2`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`dif_at_3` AS `dif_at_3`, `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`per_at_3` AS `per_at_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`dif_bt1_1` AS `dif_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`per_bt1_1` AS `per_bt1_1`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`dif_bt1_2` AS `dif_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`per_bt1_2` AS `per_bt1_2`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`dif_bt1_3` AS `dif_bt1_3`, `view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`per_bt1_3` AS `per_bt1_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`dif_bt2_1` AS `dif_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`per_bt2_1` AS `per_bt2_1`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`dif_bt2_2` AS `dif_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`per_bt2_2` AS `per_bt2_2`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`dif_bt2_3` AS `dif_bt2_3`, `view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`per_bt2_3` AS `per_bt2_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`dif_bt3_1` AS `dif_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`per_bt3_1` AS `per_bt3_1`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`dif_bt3_2` AS `dif_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`per_bt3_2` AS `per_bt3_2`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`dif_bt3_3` AS `dif_bt3_3`, `view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`per_bt3_3` AS `per_bt3_3` FROM (((`view_devanado_details_by_devanado_flow_id_1_transformer_id_501` join `view_devanado_details_by_devanado_flow_id_2_transformer_id_501` on((`view_devanado_details_by_devanado_flow_id_2_transformer_id_501`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_3_transformer_id_501` on((`view_devanado_details_by_devanado_flow_id_3_transformer_id_501`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`date_devanado`))) join `view_devanado_details_by_devanado_flow_id_4_transformer_id_501` on((`view_devanado_details_by_devanado_flow_id_4_transformer_id_501`.`date_devanado` = `view_devanado_details_by_devanado_flow_id_1_transformer_id_501`.`date_devanado`))) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `accesses`
--
ALTER TABLE `accesses`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ar_internal_metadata`
--
ALTER TABLE `ar_internal_metadata`
  ADD PRIMARY KEY (`key`);

--
-- Indices de la tabla `audits`
--
ALTER TABLE `audits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `auditable_index` (`auditable_type`,`auditable_id`,`version`),
  ADD KEY `associated_index` (`associated_type`,`associated_id`),
  ADD KEY `user_index` (`user_id`,`user_type`),
  ADD KEY `index_audits_on_request_uuid` (`request_uuid`),
  ADD KEY `index_audits_on_created_at` (`created_at`);

--
-- Indices de la tabla `bornals`
--
ALTER TABLE `bornals`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `chromatographicals`
--
ALTER TABLE `chromatographicals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_819f2d7613` (`transformer_id`);

--
-- Indices de la tabla `chromatographical_dga_diags`
--
ALTER TABLE `chromatographical_dga_diags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_f676d80ec4` (`transformer_id`);

--
-- Indices de la tabla `chromatographical_duvals`
--
ALTER TABLE `chromatographical_duvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_transformer` (`transformer_id`);

--
-- Indices de la tabla `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_2fd19c0db7` (`post_id`),
  ADD KEY `fk_rails_03de2dc08c` (`user_id`);

--
-- Indices de la tabla `conmutation_types`
--
ALTER TABLE `conmutation_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `connection_types`
--
ALTER TABLE `connection_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_595506fbcf` (`country_id`);

--
-- Indices de la tabla `customer_areas`
--
ALTER TABLE `customer_areas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_fb52620c9f` (`customer_location_id`),
  ADD KEY `fk_rails_a23977b727` (`customer_id`);

--
-- Indices de la tabla `customer_locations`
--
ALTER TABLE `customer_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_00c1342968` (`customer_id`);

--
-- Indices de la tabla `customer_substations`
--
ALTER TABLE `customer_substations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_110eafad42` (`customer_area_id`),
  ADD KEY `fk_rails_f7f406f755` (`customer_id`);

--
-- Indices de la tabla `devanados`
--
ALTER TABLE `devanados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_feae7970d8` (`transformer_id`);

--
-- Indices de la tabla `devanado_details`
--
ALTER TABLE `devanado_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_631ed67cfc` (`devanado_id`),
  ADD KEY `fk_rails_ec7895e681` (`devanado_flow_id`);

--
-- Indices de la tabla `devanado_flows`
--
ALTER TABLE `devanado_flows`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `devanado_templates`
--
ALTER TABLE `devanado_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `devanado_template_details`
--
ALTER TABLE `devanado_template_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_9d6c6bbb4d` (`devanado_template_id`),
  ADD KEY `fk_rails_1109da5ad5` (`devanado_flow_id`);

--
-- Indices de la tabla `devanado_template_transformers`
--
ALTER TABLE `devanado_template_transformers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_3fb4b294c3` (`transformer_id`),
  ADD KEY `fk_rails_690c52ec80` (`devanado_template_id`);

--
-- Indices de la tabla `duvals`
--
ALTER TABLE `duvals`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `electricals`
--
ALTER TABLE `electricals`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `factors`
--
ALTER TABLE `factors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_99e62751f2` (`transformer_id`);

--
-- Indices de la tabla `furanos`
--
ALTER TABLE `furanos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_d0cb305158` (`transformer_id`);

--
-- Indices de la tabla `gas`
--
ALTER TABLE `gas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_c01e9e8363` (`transformer_test_id`);

--
-- Indices de la tabla `gas_keys`
--
ALTER TABLE `gas_keys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_4c606cfdc1` (`transformer_id`);

--
-- Indices de la tabla `ieee_diags`
--
ALTER TABLE `ieee_diags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_57488fb01b` (`transformer_id`);

--
-- Indices de la tabla `ieee_diag_details`
--
ALTER TABLE `ieee_diag_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_c3103ea36f` (`ieee_diag_id`),
  ADD KEY `fk_rails_05faad3b59` (`chromatographical_id`);

--
-- Indices de la tabla `import_cromas`
--
ALTER TABLE `import_cromas`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `import_transformers`
--
ALTER TABLE `import_transformers`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `marks`
--
ALTER TABLE `marks`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `meet_events`
--
ALTER TABLE `meet_events`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `newspapers`
--
ALTER TABLE `newspapers`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `oil_types`
--
ALTER TABLE `oil_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `option_posts`
--
ALTER TABLE `option_posts`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `physicals`
--
ALTER TABLE `physicals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_1c5b61bf9a` (`transformer_id`);

--
-- Indices de la tabla `physical_comments`
--
ALTER TABLE `physical_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_fdf0ed924e` (`physical_post_id`),
  ADD KEY `fk_rails_02e5c07fc6` (`user_id`);

--
-- Indices de la tabla `physical_posts`
--
ALTER TABLE `physical_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_541b517cfe` (`transformer_id`),
  ADD KEY `fk_rails_a8b00533c7` (`user_id`),
  ADD KEY `fk_rails_eaa4313563` (`physical_id`),
  ADD KEY `fk_rails_9de74a2f5d` (`type_comment_id`);

--
-- Indices de la tabla `physical_trials`
--
ALTER TABLE `physical_trials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_93adfab70b` (`transformer_test_id`);

--
-- Indices de la tabla `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_549c81f91d` (`transformer_id`),
  ADD KEY `fk_rails_5b5ddfd518` (`user_id`),
  ADD KEY `fk_rails_0c9b76ecea` (`chromatographical_id`),
  ADD KEY `fk_rails_dea45cb0f4` (`type_comment_id`);

--
-- Indices de la tabla `profiles`
--
ALTER TABLE `profiles`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `profile_accesses`
--
ALTER TABLE `profile_accesses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_bac9a50fe4` (`profile_id`),
  ADD KEY `fk_rails_7a9c39b6a9` (`access_id`);

--
-- Indices de la tabla `profile_countries`
--
ALTER TABLE `profile_countries`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `report_details`
--
ALTER TABLE `report_details`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`version`);

--
-- Indices de la tabla `search_transformer_per_users`
--
ALTER TABLE `search_transformer_per_users`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `transformers`
--
ALTER TABLE `transformers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_61066423e7` (`customer_substation_id`),
  ADD KEY `fk_rails_6eebe53c91` (`transformer_type_id`),
  ADD KEY `fk_rails_7998b4091a` (`connection_type_id`),
  ADD KEY `fk_rails_1e558ddeca` (`conmutation_type_id`),
  ADD KEY `fk_rails_8ead232334` (`transformer_preservation_id`),
  ADD KEY `fk_rails_413364a911` (`oil_type_id`),
  ADD KEY `fk_rails_bb3d49e7df` (`mark_id`);

--
-- Indices de la tabla `transformer_graphs`
--
ALTER TABLE `transformer_graphs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `transformer_preservations`
--
ALTER TABLE `transformer_preservations`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `transformer_tests`
--
ALTER TABLE `transformer_tests`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `transformer_types`
--
ALTER TABLE `transformer_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `type_comments`
--
ALTER TABLE `type_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_9abf045418` (`option_post_id`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_a8794354f0` (`profile_id`);

--
-- Indices de la tabla `user_customers`
--
ALTER TABLE `user_customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rails_52f4ac6e57` (`user_id`),
  ADD KEY `fk_rails_c50b290356` (`customer_id`);

--
-- Indices de la tabla `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `accesses`
--
ALTER TABLE `accesses`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `audits`
--
ALTER TABLE `audits`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `bornals`
--
ALTER TABLE `bornals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `chromatographicals`
--
ALTER TABLE `chromatographicals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `chromatographical_dga_diags`
--
ALTER TABLE `chromatographical_dga_diags`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `chromatographical_duvals`
--
ALTER TABLE `chromatographical_duvals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comments`
--
ALTER TABLE `comments`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `conmutation_types`
--
ALTER TABLE `conmutation_types`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `connection_types`
--
ALTER TABLE `connection_types`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `countries`
--
ALTER TABLE `countries`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `customer_areas`
--
ALTER TABLE `customer_areas`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `customer_locations`
--
ALTER TABLE `customer_locations`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `customer_substations`
--
ALTER TABLE `customer_substations`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanados`
--
ALTER TABLE `devanados`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanado_details`
--
ALTER TABLE `devanado_details`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanado_flows`
--
ALTER TABLE `devanado_flows`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanado_templates`
--
ALTER TABLE `devanado_templates`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanado_template_details`
--
ALTER TABLE `devanado_template_details`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `devanado_template_transformers`
--
ALTER TABLE `devanado_template_transformers`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `duvals`
--
ALTER TABLE `duvals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `electricals`
--
ALTER TABLE `electricals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `factors`
--
ALTER TABLE `factors`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `furanos`
--
ALTER TABLE `furanos`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `gas`
--
ALTER TABLE `gas`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `gas_keys`
--
ALTER TABLE `gas_keys`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ieee_diags`
--
ALTER TABLE `ieee_diags`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ieee_diag_details`
--
ALTER TABLE `ieee_diag_details`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `import_cromas`
--
ALTER TABLE `import_cromas`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `import_transformers`
--
ALTER TABLE `import_transformers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `marks`
--
ALTER TABLE `marks`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `meet_events`
--
ALTER TABLE `meet_events`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `newspapers`
--
ALTER TABLE `newspapers`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `oil_types`
--
ALTER TABLE `oil_types`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `option_posts`
--
ALTER TABLE `option_posts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `physicals`
--
ALTER TABLE `physicals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `physical_comments`
--
ALTER TABLE `physical_comments`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `physical_posts`
--
ALTER TABLE `physical_posts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `physical_trials`
--
ALTER TABLE `physical_trials`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `posts`
--
ALTER TABLE `posts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profiles`
--
ALTER TABLE `profiles`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profile_accesses`
--
ALTER TABLE `profile_accesses`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profile_countries`
--
ALTER TABLE `profile_countries`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `reports`
--
ALTER TABLE `reports`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `report_details`
--
ALTER TABLE `report_details`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `search_transformer_per_users`
--
ALTER TABLE `search_transformer_per_users`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `transformers`
--
ALTER TABLE `transformers`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `transformer_graphs`
--
ALTER TABLE `transformer_graphs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `transformer_preservations`
--
ALTER TABLE `transformer_preservations`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `transformer_tests`
--
ALTER TABLE `transformer_tests`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `transformer_types`
--
ALTER TABLE `transformer_types`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `type_comments`
--
ALTER TABLE `type_comments`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_customers`
--
ALTER TABLE `user_customers`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_notifications`
--
ALTER TABLE `user_notifications`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `chromatographicals`
--
ALTER TABLE `chromatographicals`
  ADD CONSTRAINT `fk_rails_819f2d7613` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `chromatographical_dga_diags`
--
ALTER TABLE `chromatographical_dga_diags`
  ADD CONSTRAINT `fk_rails_f676d80ec4` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `chromatographical_duvals`
--
ALTER TABLE `chromatographical_duvals`
  ADD CONSTRAINT `fk_transformer` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `fk_rails_03de2dc08c` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rails_2fd19c0db7` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`);

--
-- Filtros para la tabla `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_rails_595506fbcf` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`);

--
-- Filtros para la tabla `customer_areas`
--
ALTER TABLE `customer_areas`
  ADD CONSTRAINT `fk_rails_a23977b727` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_rails_fb52620c9f` FOREIGN KEY (`customer_location_id`) REFERENCES `customer_locations` (`id`);

--
-- Filtros para la tabla `customer_locations`
--
ALTER TABLE `customer_locations`
  ADD CONSTRAINT `fk_rails_00c1342968` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Filtros para la tabla `customer_substations`
--
ALTER TABLE `customer_substations`
  ADD CONSTRAINT `fk_rails_110eafad42` FOREIGN KEY (`customer_area_id`) REFERENCES `customer_areas` (`id`),
  ADD CONSTRAINT `fk_rails_f7f406f755` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Filtros para la tabla `devanados`
--
ALTER TABLE `devanados`
  ADD CONSTRAINT `fk_rails_feae7970d8` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `devanado_details`
--
ALTER TABLE `devanado_details`
  ADD CONSTRAINT `fk_rails_631ed67cfc` FOREIGN KEY (`devanado_id`) REFERENCES `devanados` (`id`),
  ADD CONSTRAINT `fk_rails_ec7895e681` FOREIGN KEY (`devanado_flow_id`) REFERENCES `devanado_flows` (`id`);

--
-- Filtros para la tabla `devanado_template_details`
--
ALTER TABLE `devanado_template_details`
  ADD CONSTRAINT `fk_rails_1109da5ad5` FOREIGN KEY (`devanado_flow_id`) REFERENCES `devanado_flows` (`id`),
  ADD CONSTRAINT `fk_rails_9d6c6bbb4d` FOREIGN KEY (`devanado_template_id`) REFERENCES `devanado_templates` (`id`);

--
-- Filtros para la tabla `devanado_template_transformers`
--
ALTER TABLE `devanado_template_transformers`
  ADD CONSTRAINT `fk_rails_3fb4b294c3` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`),
  ADD CONSTRAINT `fk_rails_690c52ec80` FOREIGN KEY (`devanado_template_id`) REFERENCES `devanado_templates` (`id`);

--
-- Filtros para la tabla `factors`
--
ALTER TABLE `factors`
  ADD CONSTRAINT `fk_rails_99e62751f2` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `furanos`
--
ALTER TABLE `furanos`
  ADD CONSTRAINT `fk_rails_d0cb305158` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `gas`
--
ALTER TABLE `gas`
  ADD CONSTRAINT `fk_rails_c01e9e8363` FOREIGN KEY (`transformer_test_id`) REFERENCES `transformer_tests` (`id`);

--
-- Filtros para la tabla `gas_keys`
--
ALTER TABLE `gas_keys`
  ADD CONSTRAINT `fk_rails_4c606cfdc1` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `ieee_diags`
--
ALTER TABLE `ieee_diags`
  ADD CONSTRAINT `fk_rails_57488fb01b` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `ieee_diag_details`
--
ALTER TABLE `ieee_diag_details`
  ADD CONSTRAINT `fk_rails_05faad3b59` FOREIGN KEY (`chromatographical_id`) REFERENCES `chromatographicals` (`id`),
  ADD CONSTRAINT `fk_rails_c3103ea36f` FOREIGN KEY (`ieee_diag_id`) REFERENCES `ieee_diags` (`id`);

--
-- Filtros para la tabla `physicals`
--
ALTER TABLE `physicals`
  ADD CONSTRAINT `fk_rails_1c5b61bf9a` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`);

--
-- Filtros para la tabla `physical_comments`
--
ALTER TABLE `physical_comments`
  ADD CONSTRAINT `fk_rails_02e5c07fc6` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rails_fdf0ed924e` FOREIGN KEY (`physical_post_id`) REFERENCES `physical_posts` (`id`);

--
-- Filtros para la tabla `physical_posts`
--
ALTER TABLE `physical_posts`
  ADD CONSTRAINT `fk_rails_541b517cfe` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`),
  ADD CONSTRAINT `fk_rails_9de74a2f5d` FOREIGN KEY (`type_comment_id`) REFERENCES `type_comments` (`id`),
  ADD CONSTRAINT `fk_rails_a8b00533c7` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rails_eaa4313563` FOREIGN KEY (`physical_id`) REFERENCES `physicals` (`id`);

--
-- Filtros para la tabla `physical_trials`
--
ALTER TABLE `physical_trials`
  ADD CONSTRAINT `fk_rails_93adfab70b` FOREIGN KEY (`transformer_test_id`) REFERENCES `transformer_tests` (`id`);

--
-- Filtros para la tabla `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `fk_rails_0c9b76ecea` FOREIGN KEY (`chromatographical_id`) REFERENCES `chromatographicals` (`id`),
  ADD CONSTRAINT `fk_rails_549c81f91d` FOREIGN KEY (`transformer_id`) REFERENCES `transformers` (`id`),
  ADD CONSTRAINT `fk_rails_5b5ddfd518` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rails_dea45cb0f4` FOREIGN KEY (`type_comment_id`) REFERENCES `type_comments` (`id`);

--
-- Filtros para la tabla `profile_accesses`
--
ALTER TABLE `profile_accesses`
  ADD CONSTRAINT `fk_rails_7a9c39b6a9` FOREIGN KEY (`access_id`) REFERENCES `accesses` (`id`),
  ADD CONSTRAINT `fk_rails_bac9a50fe4` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`id`);

--
-- Filtros para la tabla `transformers`
--
ALTER TABLE `transformers`
  ADD CONSTRAINT `fk_rails_1e558ddeca` FOREIGN KEY (`conmutation_type_id`) REFERENCES `conmutation_types` (`id`),
  ADD CONSTRAINT `fk_rails_413364a911` FOREIGN KEY (`oil_type_id`) REFERENCES `oil_types` (`id`),
  ADD CONSTRAINT `fk_rails_61066423e7` FOREIGN KEY (`customer_substation_id`) REFERENCES `customer_substations` (`id`),
  ADD CONSTRAINT `fk_rails_6eebe53c91` FOREIGN KEY (`transformer_type_id`) REFERENCES `transformer_types` (`id`),
  ADD CONSTRAINT `fk_rails_7998b4091a` FOREIGN KEY (`connection_type_id`) REFERENCES `connection_types` (`id`),
  ADD CONSTRAINT `fk_rails_8ead232334` FOREIGN KEY (`transformer_preservation_id`) REFERENCES `transformer_preservations` (`id`),
  ADD CONSTRAINT `fk_rails_bb3d49e7df` FOREIGN KEY (`mark_id`) REFERENCES `marks` (`id`);

--
-- Filtros para la tabla `type_comments`
--
ALTER TABLE `type_comments`
  ADD CONSTRAINT `fk_rails_9abf045418` FOREIGN KEY (`option_post_id`) REFERENCES `option_posts` (`id`);

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_rails_a8794354f0` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`id`);

--
-- Filtros para la tabla `user_customers`
--
ALTER TABLE `user_customers`
  ADD CONSTRAINT `fk_rails_52f4ac6e57` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rails_c50b290356` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
