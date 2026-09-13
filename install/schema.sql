
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `composant_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `composant_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demandeurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `demandeurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service` varchar(100) NOT NULL,
  `fonction` varchar(100) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `code_personnel` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `entreprises_ext`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entreprises_ext` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) NOT NULL,
  `contact_nom` varchar(255) DEFAULT NULL,
  `telephone` varchar(50) DEFAULT NULL,
  `mail` varchar(255) DEFAULT NULL,
  `date_fin_pdp` date DEFAULT NULL,
  `statut` varchar(50) DEFAULT 'actif',
  `fichier_pdp` varchar(255) DEFAULT NULL,
  `type_pdp` varchar(20) DEFAULT 'Annuel',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiche_composants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiche_composants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `composant_type_id` int(11) DEFAULT NULL,
  `valeur` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_zone_composant` (`zone_id`,`composant_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=311 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiche_devis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiche_devis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `nom_original` varchar(255) DEFAULT NULL,
  `chemin` varchar(255) DEFAULT NULL,
  `taille` int(11) DEFAULT NULL,
  `type_fichier` varchar(20) DEFAULT NULL,
  `uploade_par` varchar(100) DEFAULT NULL,
  `uploade_le` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiche_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiche_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `nom_original` varchar(255) DEFAULT NULL,
  `chemin` varchar(255) DEFAULT NULL,
  `taille` int(11) DEFAULT NULL,
  `type_fichier` varchar(20) DEFAULT NULL,
  `uploade_par` varchar(100) DEFAULT NULL,
  `uploade_le` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiche_interventions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiche_interventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `date_saisie` datetime DEFAULT current_timestamp(),
  `auteur` varchar(100) DEFAULT NULL,
  `texte` text DEFAULT NULL,
  `date_intervention` date DEFAULT NULL,
  `technicien` varchar(150) DEFAULT '',
  `statut` varchar(20) DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiche_pannes_memo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiche_pannes_memo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `date_saisie` datetime DEFAULT current_timestamp(),
  `auteur` varchar(100) DEFAULT NULL,
  `constat` text DEFAULT NULL,
  `solution` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fiches_vie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiches_vie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_id` int(11) DEFAULT NULL,
  `ref_moteur` varchar(150) DEFAULT '',
  `ref_reducteur` varchar(150) DEFAULT '',
  `ref_courroie` varchar(150) DEFAULT '',
  `ref_roulement_avant` varchar(150) DEFAULT '',
  `ref_roulement_arriere` varchar(150) DEFAULT '',
  `repere_automate` varchar(150) DEFAULT '',
  `notes` text DEFAULT NULL,
  `maj_par` varchar(100) DEFAULT NULL,
  `maj_le` datetime DEFAULT NULL,
  `numero_moteur` varchar(150) DEFAULT '',
  `numero_cable` varchar(150) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `zone_id` (`zone_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `historique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historique` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` datetime DEFAULT current_timestamp(),
  `utilisateur` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `details` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=396 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idees_amelioration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `idees_amelioration` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service` varchar(255) DEFAULT NULL,
  `demandeur` varchar(255) DEFAULT NULL,
  `titre` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `categorie` varchar(50) DEFAULT NULL,
  `statut` varchar(30) DEFAULT 'Nouvelle',
  `date_creation` datetime DEFAULT current_timestamp(),
  `reponse_admin` text DEFAULT NULL,
  `date_reponse` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idees_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `idees_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idee_id` int(11) NOT NULL,
  `expediteur` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `lu` tinyint(1) DEFAULT 0,
  `date_envoi` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `interventions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `interventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `machine_id` int(11) DEFAULT NULL,
  `machine_nom` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `date_intervention` datetime DEFAULT current_timestamp(),
  `technicien` varchar(50) DEFAULT NULL,
  `statut` enum('A faire','En cours','Terminé') DEFAULT 'A faire',
  `date_creation` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `libelles_workflow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `libelles_workflow` (
  `bucket` varchar(20) NOT NULL,
  `dimension` varchar(10) DEFAULT NULL,
  `label` varchar(50) DEFAULT NULL,
  `couleur` varchar(20) DEFAULT NULL,
  `ordre` int(11) DEFAULT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) DEFAULT NULL,
  `date_tentative` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date_log` datetime DEFAULT current_timestamp(),
  `utilisateur` varchar(100) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3444 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `machines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `machines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom_machine` varchar(100) DEFAULT NULL,
  `emplacement` varchar(100) DEFAULT NULL,
  `date_achat` date DEFAULT NULL,
  `etat` enum('Opérationnelle','En panne','Maintenance prévue') DEFAULT 'Opérationnelle',
  `ordre` int(11) DEFAULT 0,
  `usine` varchar(100) DEFAULT 'Gel-Pam',
  `secteur` varchar(100) DEFAULT NULL,
  `ligne` varchar(100) DEFAULT NULL,
  `zone` varchar(100) DEFAULT NULL,
  `ordre_secteur` int(11) DEFAULT 0,
  `ordre_ligne` int(11) DEFAULT 0,
  `ordre_zone` int(11) DEFAULT 0,
  `type_equipement` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=465 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parametres_general`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parametres_general` (
  `cle` varchar(50) NOT NULL,
  `valeur` text DEFAULT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planning_astreintes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planning_astreintes` (
  `cle` varchar(20) NOT NULL,
  `label` varchar(50) DEFAULT NULL,
  `couleur` varchar(20) DEFAULT NULL,
  `ordre` int(11) DEFAULT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planning_fractionnement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planning_fractionnement` (
  `utilisateur` varchar(50) NOT NULL,
  `periode_debut` date NOT NULL,
  `jours` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`utilisateur`,`periode_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planning_objectifs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planning_objectifs` (
  `utilisateur` varchar(100) NOT NULL,
  `objectif_heures` decimal(7,2) DEFAULT NULL,
  PRIMARY KEY (`utilisateur`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planning_postes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planning_postes` (
  `cle` varchar(20) NOT NULL,
  `label` varchar(50) DEFAULT NULL,
  `couleur` varchar(20) DEFAULT NULL,
  `ordre` int(11) DEFAULT NULL,
  `categorie` varchar(20) NOT NULL DEFAULT 'poste',
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planning_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planning_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `utilisateur` varchar(100) NOT NULL,
  `jour` date NOT NULL,
  `poste` varchar(20) DEFAULT NULL,
  `astreinte` varchar(20) DEFAULT NULL,
  `heures` decimal(5,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `demi_conge` tinyint(1) NOT NULL DEFAULT 0,
  `jour_ferie` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_jour` (`utilisateur`,`jour`)
) ENGINE=InnoDB AUTO_INCREMENT=2119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pointages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pointages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(255) DEFAULT NULL,
  `tech` varchar(100) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  `hours` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=849 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preventif_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preventif_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cle` varchar(30) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icone` varchar(60) DEFAULT 'fa-gear',
  `couleur` varchar(20) DEFAULT '#3498db',
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle` (`cle`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preventif_checklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preventif_checklist` (
  `id` varchar(64) NOT NULL,
  `categorie` varchar(30) NOT NULL DEFAULT 'process',
  `saison` varchar(20) NOT NULL DEFAULT '',
  `equip` varchar(255) DEFAULT NULL,
  `descr` text DEFAULT NULL,
  `signale_par` varchar(100) DEFAULT NULL,
  `date_ajout` datetime DEFAULT current_timestamp(),
  `fait` tinyint(1) DEFAULT 0,
  `fait_par` varchar(100) DEFAULT NULL,
  `date_fait` datetime DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  `intervenant` varchar(150) DEFAULT NULL,
  `date_prevue` date DEFAULT NULL,
  `prio` varchar(20) DEFAULT 'Normal',
  `statut` varchar(20) DEFAULT 'a_faire',
  `usine` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preventif_checklist_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preventif_checklist_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(150) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preventif_regles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preventif_regles` (
  `id` varchar(64) NOT NULL,
  `equip` varchar(255) DEFAULT NULL,
  `descr` text DEFAULT NULL,
  `hours` varchar(20) DEFAULT NULL,
  `type_op` varchar(255) DEFAULT NULL,
  `prio` varchar(20) DEFAULT NULL,
  `mode_planif` varchar(20) DEFAULT 'frequence',
  `freq` varchar(20) DEFAULT NULL,
  `jours` varchar(40) DEFAULT NULL,
  `echeance` date DEFAULT NULL,
  `alerte_val` varchar(10) DEFAULT NULL,
  `alerte_unit` varchar(10) DEFAULT NULL,
  `usine` varchar(255) DEFAULT NULL,
  `secteur` varchar(255) DEFAULT NULL,
  `ligne` varchar(255) DEFAULT NULL,
  `zone` varchar(255) DEFAULT NULL,
  `impact` varchar(255) DEFAULT NULL,
  `arret_h` varchar(20) DEFAULT NULL,
  `intervenant` varchar(100) DEFAULT NULL,
  `declarant` varchar(100) DEFAULT NULL,
  `cause` text DEFAULT NULL,
  `pieces` text DEFAULT NULL,
  `commentaires` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'actif',
  `last_gen` datetime DEFAULT NULL,
  `last_gen_date` date DEFAULT NULL,
  `categorie` varchar(30) DEFAULT 'process',
  `auto_matin` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_categories_visuelles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_categories_visuelles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) DEFAULT NULL,
  `fill_color` varchar(20) DEFAULT '#3498db',
  `fill_color2` varchar(20) DEFAULT NULL,
  `gradient` tinyint(1) DEFAULT 0,
  `border_color` varchar(20) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  `effect` varchar(20) DEFAULT 'none',
  `shape_type` varchar(40) DEFAULT 'rect',
  `pos_w` decimal(6,3) DEFAULT NULL,
  `pos_h` decimal(6,3) DEFAULT NULL,
  `rotation` decimal(6,2) DEFAULT 0.00,
  `text_rotation` decimal(6,2) DEFAULT 0.00,
  `font_size` decimal(4,2) DEFAULT NULL,
  `text_color` varchar(20) DEFAULT NULL,
  `est_clickable` tinyint(1) DEFAULT 1,
  `gradient_angle` smallint(6) DEFAULT 135,
  `label` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `cle` varchar(60) NOT NULL,
  `fait_le` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schema_id` int(11) DEFAULT NULL,
  `zone_key` varchar(60) DEFAULT NULL,
  `label` varchar(150) DEFAULT NULL,
  `pos_x` decimal(5,2) DEFAULT NULL,
  `pos_y` decimal(5,2) DEFAULT NULL,
  `pos_w` decimal(5,2) DEFAULT NULL,
  `pos_h` decimal(5,2) DEFAULT NULL,
  `machine_id` int(11) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  `rotation` decimal(6,2) DEFAULT 0.00,
  `shape_type` varchar(40) DEFAULT 'rect',
  `fill_color` varchar(20) DEFAULT '#ffffff',
  `border_color` varchar(20) DEFAULT NULL,
  `est_clickable` tinyint(1) DEFAULT 1,
  `text_rotation` decimal(6,2) DEFAULT 0.00,
  `font_size` decimal(4,2) DEFAULT NULL,
  `text_color` varchar(20) DEFAULT NULL,
  `text_offset_x` decimal(6,2) DEFAULT 0.00,
  `text_offset_y` decimal(6,2) DEFAULT 0.00,
  `effect` varchar(20) DEFAULT 'none',
  `fill_color2` varchar(20) DEFAULT NULL,
  `gradient` tinyint(1) DEFAULT 0,
  `gradient_angle` smallint(6) DEFAULT 135,
  `categorie_visuelle_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_zone` (`schema_id`,`zone_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2073 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schemas_usine`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schemas_usine` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) DEFAULT NULL,
  `type` enum('code','image') DEFAULT 'image',
  `template_key` varchar(60) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  `geometrie_migree` tinyint(1) DEFAULT 0,
  `canvas_w` decimal(10,2) DEFAULT 12192.00,
  `canvas_h` decimal(10,2) DEFAULT 6858.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cle` varchar(60) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle` (`cle`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `taches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `taches` (
  `id` varchar(50) NOT NULL,
  `num_bi` varchar(20) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  `tech` varchar(100) DEFAULT NULL,
  `usine` varchar(100) DEFAULT NULL,
  `secteur` varchar(100) DEFAULT NULL,
  `zone` varchar(100) DEFAULT NULL,
  `equip` varchar(255) DEFAULT NULL,
  `statut` varchar(50) DEFAULT NULL,
  `prio` varchar(20) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `casse` tinyint(1) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `compte_rendu` text DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp(),
  `is_sous_traitant` tinyint(1) DEFAULT 0,
  `entreprise_ext_id` int(11) DEFAULT NULL,
  `demandeur` varchar(255) DEFAULT NULL,
  `rapport_intermediaire` text DEFAULT NULL,
  `st_sur_site` tinyint(1) DEFAULT 0,
  `verif_vis` tinyint(1) DEFAULT 0,
  `ligne` varchar(255) DEFAULT NULL,
  `rule_id` varchar(64) DEFAULT NULL,
  `motif_refus` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `taches_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `taches_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(255) DEFAULT NULL,
  `expediteur` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `date_envoi` datetime DEFAULT current_timestamp(),
  `lu` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `taches_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `taches_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` varchar(255) DEFAULT NULL,
  `nom_original` varchar(255) DEFAULT NULL,
  `chemin` varchar(255) DEFAULT NULL,
  `taille` int(11) DEFAULT NULL,
  `type_fichier` varchar(20) DEFAULT NULL,
  `uploade_par` varchar(100) DEFAULT NULL,
  `uploade_le` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuiles_couleurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tuiles_couleurs` (
  `href` varchar(100) NOT NULL,
  `couleur` varchar(20) DEFAULT NULL,
  `titre` varchar(60) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icone` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`href`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuiles_ordre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tuiles_ordre` (
  `utilisateur` varchar(100) NOT NULL,
  `ordre` text DEFAULT NULL,
  PRIMARY KEY (`utilisateur`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `type_equipement_composants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `type_equipement_composants` (
  `type_equipement_id` int(11) NOT NULL,
  `composant_type_id` int(11) NOT NULL,
  PRIMARY KEY (`type_equipement_id`,`composant_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `types_equipement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `types_equipement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) DEFAULT NULL,
  `icone` varchar(60) DEFAULT 'fa-gear',
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL DEFAULT 0,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('Technicien','Admin','Consultant') DEFAULT 'Technicien'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `utilisateurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `utilisateurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `fonction` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `ordre` int(11) DEFAULT 99,
  `actif` tinyint(1) DEFAULT 1,
  `code_personnel` varchar(50) DEFAULT NULL,
  `objectif_heures_annuel` decimal(6,2) DEFAULT 1607.00,
  `photo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identifiant` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

