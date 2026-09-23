-- Fiktiver Dump der Alttabelle, nur für Tests
CREATE TABLE `Properties` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Contact Gender` varchar(20) DEFAULT NULL,
  `Contact First Name` varchar(100) DEFAULT NULL,
  `Contact Last Name` varchar(100) DEFAULT NULL,
  `Contact Telephone` varchar(40) DEFAULT NULL,
  `Contact Email` varchar(254) DEFAULT NULL,
  `Contact Street` varchar(150) DEFAULT NULL,
  `Contact Zip` varchar(10) DEFAULT NULL,
  `Contact City` varchar(100) DEFAULT NULL,
  `management_start` date DEFAULT NULL,
  `Year of Construction` int(11) DEFAULT NULL,
  `Street` varchar(150) DEFAULT NULL,
  `Zip` varchar(10) DEFAULT NULL,
  `City` varchar(100) DEFAULT NULL,
  `Source` varchar(50) DEFAULT NULL,
  `Created At` datetime DEFAULT NULL,
  `managementform_id` int(11) DEFAULT NULL,
  `private_managementcost_id` int(11) DEFAULT NULL,
  `commercial_managementcost_id` int(11) DEFAULT NULL,
  `parking_managementcost_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Properties` VALUES (201,'Frau','Anna','O\'Beispiel',NULL,'anna@example.org',NULL,NULL,NULL,'2025-01-01',1999,'Weg; mit Semikolon 3','41061','Musterstadt',NULL,'2024-05-06 07:08:09',1,NULL,NULL,NULL),(202,'Herr','Ben','Test','0000 111','ben@example.org','','','',NULL,NULL,'Straße (Hof) 4','41063','Musterstadt','portal','2024-06-01 10:00:00',2,NULL,NULL,NULL);
INSERT INTO `Properties` (`ID`, `managementform_id`, `City`, `Created At`) VALUES (203, 1, 'It''s Musterort', '2024-07-01 12:00:00');
