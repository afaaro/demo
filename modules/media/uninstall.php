<?php

$db = $registry->get('db');
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query('DROP TABLE IF EXISTS `#__media_video_comments`');
$db->query('DROP TABLE IF EXISTS `#__media_video_reactions`');
$db->query('DROP TABLE IF EXISTS `#__media_videos`');
$db->query('DROP TABLE IF EXISTS `#__media_channel_staff`');
$db->query('DROP TABLE IF EXISTS `#__media_channels`');
$db->query('SET FOREIGN_KEY_CHECKS = 1');
