<?php

$db = $registry->get('db');
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query('DROP TABLE IF EXISTS `#__quran_ayah_translation`');
$db->query('DROP TABLE IF EXISTS `#__quran_ayah`');
$db->query('DROP TABLE IF EXISTS `#__quran_translation`');
$db->query('DROP TABLE IF EXISTS `#__quran_reciter`');
$db->query('DROP TABLE IF EXISTS `#__quran_surah`');
$db->query('SET FOREIGN_KEY_CHECKS = 1');
