# Simple SMTP Server

基于Swoole开发的运行在PHP环境下的简易SMTP邮件服务器

青石 博客 http://www.qs5.org

## 使用方法
修改文件中的数据库配置信息

并且导入 mail_list.sql 数据库表结构

将域名mx解析到服务器上并开放25端口

即可接受服务器

没有任何验证，可能会收到大量垃圾邮件。

本程序需要Swoole支持

官方网站: http://www.swoole.com/

官方Github: https://github.com/swoole/swoole-src

PHP下可一键安装Swoole: pecl install swoole

