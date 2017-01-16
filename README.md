# Simple SMTP Server
基于Swoole开发的运行在PHP环境下的简易SMTP邮件服务器

## 简易邮件接收服务器
创建一个可以接受任意邮件的邮件服务器。

只需将域名mx解析到服务器上并开放25端口即可接受服务器。

没有任何验证，可能会收到大量垃圾邮件。

本程序需要Swoole支持

官方网站: http://www.swoole.com/

官方Github: https://github.com/swoole/swoole-src

PHP下可一键安装Swoole: pecl install swoole
