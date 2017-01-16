<?php
/**
 * 基于PHP + Swlooe + MySQL创建的简易SMTP邮件接收服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   Simple SMTP Server 2017-1-17 13:21:33
 */

/**
 * 数据库配置信息
 */
$db_info = array(
    'hostname'  => '127.0.0.1',
    'username'  => 'root',
    'password'  => 'root',
    'dbname'    => 'smtp_mail'
);


// Server
class SMTP_Server
{
    private $debug = false; // 调试模式开关
    private $serv;      // 服务对象
    private $cli_pool;  // 客户端用户池
    private $db_info;   // 数据库连接信息
    private $link;      // 数据库连接

    // 初始化函数
    public function __construct($db_info, $is_run = false)
    {
        // 如果是 $is_run 则默认设置为 非调试模式
        $is_run && $this->debug = false;

        // 创建 Swoole 对象
        $this->serv = new swoole_server("0.0.0.0", 25);

        // 设置默认设置
        $this->serv->set(array(
            'debug_mode'               => 1,        // 调试模式
            'max_conn'                 => 1000,      // 最大连接数
            'daemonize'                => $is_run,    // 守护进程化 设置为 true 则后台运行
            'worker_num'               => 2,        // 工作进程数量
            'max_request'              => 10000,    // 设置worker进程的最大任务数
            'dispatch_mode'            => 2,        // 数据包分发策略
            'open_eof_check'           => true,     // 打开buffer
            'package_eof'              => "\r\n",   // 设置EOF
            'heartbeat_check_interval' => 30,       // 心跳包检测间隔
            'heartbeat_idle_time'      => 60,       // 最大闲置时间
            'log_file'                 => 'log_file.log',   // 日志文件
        ));

        // 保存数据连接到数据库
        $this->db_info = $db_info;
        $this->mysqlConnect();

        // 注册回调事件
        $this->serv->on('Start', array($this, 'onStart'));      // 服务器被启动
        $this->serv->on('WorkerStart', array($this, 'onWorkerStart'));      // 工作进程被启动
        $this->serv->on('Connect', array($this, 'onConnect'));  // 客户端有连接
        $this->serv->on('Receive', array($this, 'onReceive'));  // 收到客户端消息
        $this->serv->on('Close', array($this, 'onClose'));      // 客户端断开连接

        // 启动服务
        $this->serv->start();
    }

    // 连接到数据库
    public function mysqlConnect(){
        // 连接到数据库
        $this->link = new MySQLi($this->db_info['hostname'], $this->db_info['username'], $this->db_info['password'], $this->db_info['dbname']);

        // 连接失败则报错
        $this->link->connect_error && die("MySQLi Connect Error({$this->link->connect_errno}): {$this->link->connect_error}\n");
    }

    // 定时器 ping sql
    public function tickOnMysqlPing($serv){
        // ping检查是否连接中
        if(!$this->link->ping()){
            // 连接到数据库
            $this->mysqlConnect();
            // 显示重连成功
            echo "[tick] reload Link OK!\n";
        }
    }

    // 进程启动
    public function onStart($serv)
    {
        $timeStr = date('Y/m/d H:i:s');
        echo "[Start] {$timeStr} master_pid: {$serv->master_pid}\n";
    }

    // 工作进程启动
    public function onWorkerStart($serv, $worker_id)
    {
        // 并设置定时轮训保持数据库连接 5秒
        if($worker_id == 1)
            $serv->tick(1000 * 5, array($this, 'tickOnMysqlPing'));

        $timeStr = date('Y/m/d H:i:s');
        echo "[WorkerStart:{$worker_id}] {$timeStr} master_pid: {$serv->master_pid}\n";
    }

    // 有客户端连接
    public function onConnect($serv, $fd, $from_id)
    {
        // 获取客户端详细信息
        $cliInfo = $serv->connection_info($fd, $from_id);

        // 创建消息记录数组
        $this->cli_pool[$fd] = array(
            'username'    => "u_{$fd}", // 临时用户名
            'status'      => 'init',    // 操作状态
            'client_ip'   => $cliInfo['remote_ip'],     // 客户端IP
            'client_port' => $cliInfo['remote_port'],   // 客户端端口
            'client_from' => '',        // 客户端标识
            'mail_from'   => '',        // 发件人信息
            'mail_rect'   => array()    // 收件人地址
        );

        // 输出客户端信息
        echo "[Connect] {$this->cli_pool[$fd]['username']} {$this->cli_pool[$fd]['client_ip']}:{$this->cli_pool[$fd]['client_port']}\n";

        // 回复客户端可以继续
        $serv->send($fd, "220 Hello {$this->cli_pool[$fd]['username']}, Welcome! - qs5.org\r\n");
    }

    // 收到消息
    public function onReceive(swoole_server $serv, $fd, $from_id, $datas)
    {
        // 始终假设用户发来的多行数据
        $dataArr = explode("\r\n", rtrim($datas, "\r\n"));

        // 循环每一行数据
        $isClose = false;
        foreach ($dataArr as $data) {
            // 空行跳过
            if($data == '' || $isClose) continue;
            // 交给处理方法
            $retInfo = $this->mailResolve($serv, $fd, "{$data}\r\n");
            // 返回处理结果
            $retInfo['status'] && $serv->send($fd, "{$retInfo['code']} {$retInfo['msg']}\r\n");
            // 如果有返回断开连接则断开
            if(!empty($retInfo['close'])){
                $isClose = true;
                $serv->close($fd);
            }
        }
        return;
    }

    // 断开连接
    public function onClose($serv, $fd, $from_id)
    {
        echo "[Close] {$fd}\n";

        // 删除消息记录
        unset($this->cli_pool[$fd]);
    }

    public function mailResolve(swoole_server $serv, $fd, $data)
    {
        // 判断是否在 getData 阶段
        if($this->cli_pool[$fd]['status'] == 'getData'){
            // 先把收到的消息保存的缓冲区
            $this->cli_pool[$fd]['buffer'].= $data;
            // 如果消息没到换行就继续接收
            if(!preg_match("#\r\n\.\r\n$#", $this->cli_pool[$fd]['buffer']))
                return array('status' => false);

            // 退出接受消息状态
            $this->cli_pool[$fd]['status'] = '';

            // 获取数据并清空缓存区
            $dataBody = $this->cli_pool[$fd]['buffer'];
            $this->cli_pool[$fd]['buffer'] = '';

            // 输出获取到的消息内容
            // echo "[Data:{$fd}]\n{$dataBody}\n==============\n";

            // 走保存邮件流程
            $saveRet = $this->mailSave(
                $this->cli_pool[$fd]['mail_from'],
                $this->cli_pool[$fd]['mail_rect'],
                $dataBody,
                $this->cli_pool[$fd]['client_ip'],
                $this->cli_pool[$fd]['client_from']
            );

            // 输出保存结果
            if($saveRet){
                echo "[mailSave:{$fd}] Ok!\n";
            } else {
                echo "[mailSave:{$fd}] Error!\n";
            }

            // 返回处理结果
            $ret_info = array(
                'status' => true,
                'code'   => $saveRet ? 250 : 554,
                'msg'    => $saveRet ? 'Ok' : 'Error',
            );
        } else {
            // 获取数据并清空缓存区
            $msgBody = rtrim($data, "\r\n");    // 删除末尾的换行

            // 输出获取到的消息内容
            if($this->debug)
                echo "[Get:{$fd}] {$msgBody}\n";

            // 获取消息格式
            if(!preg_match('#^(?<cmd>[^\s]+)(\s(?<msg>.*?))?$#', $msgBody, $msgInfo)){
                return array(
                    'status' => true,
                    'code'   => 500,
                    'msg'    => 'Msg Error!'
                );
            }

            // 判断消息应该给谁处理
            $cmd = strtoupper(trim($msgInfo['cmd']));
            switch ($cmd) {
                // 返回一个肯定的消息
                case 'NOOP':
                    $ret_info = array(
                        'status' => true,
                        'code'   => 250,
                        'msg'    => 'Ok'
                    );
                    break;

                // 握手表明身份
                case 'HELO':
                    // 保存客户端标识
                    $this->cli_pool[$fd]['client_from'] = $msgInfo['msg'];
                    // 输出客户端信息
                    echo "[HELO] {$msgInfo['msg']}\n";
                    $ret_info = array(
                        'status' => true,
                        'code'   => 250,
                        'msg'    => "{$this->cli_pool[$fd]['username']}"
                    );
                    break;

                // 开始新的发送请求 设置发件人信息
                case 'MAIL':
                    // 获取发件人地址
                    if(!preg_match('#^FROM:\s*<(?<mail>[^>]+)>#i', $msgInfo['msg'], $mailAddr)){
                        // 获取发件人地址失败
                        $ret_info = array(
                            'status' => true,
                            'code'   => 501,
                            'msg'    => 'Error!'
                        );
                    } else {
                        // 获取发件人地址成功
                        echo "[Mail From] {$mailAddr['mail']}\n";
                        // 保存发件人信息
                        $this->cli_pool[$fd]['mail_from'] = trim($mailAddr['mail']);
                        // 重置收件人列表
                        $this->cli_pool[$fd]['mail_rect'] = array();
                        // 回复客户端
                        $ret_info = array(
                            'status' => true,
                            'code'   => 250,
                            'msg'    => 'Ok'
                        );
                    }
                    break;

                // 设置收件人信息
                case 'RCPT':
                    // 获取收件人地址
                    if(!preg_match('#^TO:\s*<(?<mail>[^>]+)>#i', $msgInfo['msg'], $mailAddr)){
                        // 获取收件人地址失败
                        $ret_info = array(
                            'status' => true,
                            'code'   => 501,
                            'msg'    => 'Error!'
                        );
                    } else {
                        // 获取收件人地址成功
                        echo "[Rect To] {$mailAddr['mail']}\n";
                        // 保存收件人信息
                        $this->cli_pool[$fd]['mail_rect'][] = trim($mailAddr['mail']);
                        // 回复客户端
                        $ret_info = array(
                            'status' => true,
                            'code'   => 250,
                            'msg'    => 'Ok'
                        );
                    }
                    break;

                // 获取data内容
                case 'DATA':
                    // 设置命令状态
                    $this->cli_pool[$fd]['status'] = 'getData';
                    // 设置或清空缓冲区
                    $this->cli_pool[$fd]['buffer'] = '';
                    $ret_info = array(
                        'status' => true,
                        'code'   => 354,
                        'msg'    => 'End data with <CR><LF>.<CR><LF>'
                    );
                    break;

                // 退出
                case 'QUIT':
                    $ret_info = array(
                        'status' => true,
                        'close'  => true,
                        'code'   => 221,
                        'msg'    => 'Bye'
                    );
                    break;

                // 其他命令
                default:
                    $ret_info = array(
                        'status' => true,
                        'code'   => 502,
                        'msg'    => "Error: command \"{$cmd}\" not implemented"
                    );
                    break;
            }
        }
        return $ret_info;
    }

    // 保存邮件信息
    public function mailSave($mail_from, $mail_rect, $mail_data, $client_ip, $client_from)
    {
        // 获取当前时间戳
        $timeStr = date('Ymd');

        // 邮件内容编码
        $mail_data   = $this->link->real_escape_string($mail_data);
        $client_from = $this->link->real_escape_string($client_from);

        // SQL语句
        $sql = "INSERT INTO `mail_list` (`mail_id`, `from`, `from_ip`,`client_from`, `rect`, `body`) VALUES \n";

        // 循环每个收件人
        $sql_value = array();
        foreach ($mail_rect as $rect) {
            // 邮件hash
            $mail_hash = substr(md5("{$mail_from}_{$rect}"), 6, 16);
            // 随机唯一ID
            $randid = substr(md5(uniqid(mt_rand(), true)), 10, 16);
            // 邮件ID
            $mail_id = "d{$timeStr}_{$mail_hash}_{$randid}";

            // 转义邮件信息
            $mail_from = $this->link->real_escape_string($mail_from);
            $rect      = $this->link->real_escape_string($rect);

            // 值数组
            $sql_value[] = "('{$mail_id}', '{$mail_from}', '{$client_ip}','{$client_from}', '{$rect}', '{$mail_data}')";
        }

        // 拼接数组
        $sql_valueStr = implode(",\n", $sql_value);

        // 拼接 sql语句
        $sql.= $sql_valueStr . ';';

        // 插入到数据库
        $ret = $this->link->query($sql);

        // 如果出错是因为超时
        if(!$ret && $this->link->errno == 2006){
            // 连接到数据库
            $this->mysqlConnect();
            // 插入到数据库
            $ret = $this->link->query($sql);
        }

        // 返回状态
        return $ret;
    }
}

// 判断是否 cli 运行
if(php_sapi_name() != 'cli') die('Is cli Run!');

// 判断是否传递后台运行命令
$isRun = !empty($argv['1']) && $argv['1'] == 'run';

// 启动服务器
$server = new SMTP_Server($db_info, $isRun);
