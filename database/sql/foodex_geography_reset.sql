-- FOODEX Egypt Geography / Van Territory
-- Open Admin Data Egypt Administrative Divisions, last updated 2026-09-08.
-- License: CC-BY-4.0
-- https://github.com/open-admin-data/egypt-administrative-divisions
-- Parsed source: 27 governorates, 365 districts/markaz, 5716 areas/shiyakhas.
-- Delta operational detail: 9 governorates, 141 districts/markaz, 2942 areas.
-- Display Points use real source coordinates and are intentionally is_active=0.
-- Automatic GPS routing remains Polygon/MultiPolygon-only.

SET NAMES utf8mb4;
SET @OLD_SQL_SAFE_UPDATES := @@SQL_SAFE_UPDATES;
SET @OLD_FOREIGN_KEY_CHECKS := @@FOREIGN_KEY_CHECKS;
SET SQL_SAFE_UPDATES = 0;

-- ============================================================
-- RESET ONLY
-- ============================================================
START TRANSACTION;

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM address_territory_resolutions;
DELETE FROM territory_geometries;
DELETE FROM service_territories;
DELETE FROM geography_nodes;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

COMMIT;

SET SQL_SAFE_UPDATES = @OLD_SQL_SAFE_UPDATES;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

SELECT
    (SELECT COUNT(*) FROM geography_nodes) AS geography_nodes,
    (SELECT COUNT(*) FROM service_territories) AS service_territories,
    (SELECT COUNT(*) FROM territory_geometries) AS territory_geometries,
    (SELECT COUNT(*) FROM address_territory_resolutions) AS address_territory_resolutions;
