-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `mail_list`;
CREATE TABLE `mail_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `mail_id` varchar(64) NOT NULL COMMENT '邮件唯一ID',
  `from` varchar(128) NOT NULL COMMENT '发件人地址',
  `from_ip` varchar(15) NOT NULL COMMENT '发件人IP',
  `client_from` varchar(32) NOT NULL COMMENT '发信服务器标识',
  `rect` varchar(128) NOT NULL COMMENT '收件人地址',
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '创建时间',
  `body` text NOT NULL COMMENT '邮件正文内容',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件列表';


-- 2017-01-17 05:11:59
