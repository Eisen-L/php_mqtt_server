<?php

class Server
{
    private $serv;
    private $mysql;
    private $msgid = 1;
    public $topics = array();
    public function __construct() {
        $this->serv = new swoole_server("0.0.0.0", 9502);
        $this->serv->set(array(
            'worker_num' => 8,
            'daemonize' => true,
            'max_request' => 10000,
            'open_mqtt_protocol' => true,
            'dispatch_mode' => 2,
            'debug_mode'=> 1,
            'task_worker_num'=>4
        ));
        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Close', array($this, 'onClose'));
        $this->serv->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->serv->on('task',function($serv, $task_id, $from_id, $data){

            return 'success';
        });
        //处理异步任务的结果
        $this->serv->on('finish', function ($serv, $task_id, $data) {
            // var_dump($task_id);
        });

        $this->serv->start();
    }
    public function onStart( $serv ) {
        $this->debug("Swoole Server Start");
    }

    public function onWorkerStart($serv,$worker_id){
//        if ($worker_id == 1)
//        {
//            $serv->tick(1000, function () {
//                echo 'test';
//            });
//        }
    }


    public function onConnect( $serv, $fd, $from_id ) {
        // $resp = chr(32) . chr(2) . chr(0) . chr(0);
        // $resp=bindec(23456);
        // $serv->send( $fd,$resp);
        // swoole_timer_tick(1000, function(){
        //     echo "timeout\n";
        // });
    }
    public function onReceive( swoole_server $serv, $fd, $from_id, $data ){
        // echo "Get Message From Client {$fd}:{$data}\n";
        // $res=$this->send_publish("abcd","243");
        // $serv->send($fd,$res);


        $this->decode_mqtt($data,$serv,$fd);
    }
    public function onClose( $serv, $fd, $from_id ) {
        $this->debug("Client {$fd} close connection");
        // $client_id = $this->redis_get("client_".$fd);
        // $this->redis_delete("client_".$fd);
        // $this->redis_delete("fd_".$client_id);
        $this->debug("delete client redis data");
    }
    //执行renwu
    public function onTack($serv, $task_id, $from_id, $data){
        foreach($serv->connections as $fd1)
        {
            $serv->send($fd1, $data);
        }
        return 'success';
    }
    public function decode_mqtt($data,$serv,$fd)
    {
        $this->printstr($data);

        $data_len_byte = 1;
        $fix_header['data_len'] = $this->getmsglength($data,$data_len_byte);
        $this->debug($fix_header['data_len'],"get msg length");
        $byte = ord($data[0]);
        $fix_header['type'] = ($byte & 0xF0) >> 4;
        $fix_header['dup'] = ($byte & 0x08) >> 3;
        $fix_header['qos'] = ($byte & 0x06) >> 1;
        $fix_header['retain'] = $byte & 0x01;

        switch ($fix_header['type'])
        {
            case 1:
                $this->debug("CONNECT");
                $resp = chr(32) . chr(2) . chr(0) . chr(0);//转换为二进制返回应该使用chr
                $client_info = $this->get_connect_info(substr($data, 2));
                $client_id = $client_info['clientId'];
                var_dump($client_info);
                $serv->send($fd, $resp);
                $this->debug("Send CONNACK");
                break;
            case 3:
                var_dump($fix_header);
                $this->debug("PUBLISH");

                $offset = strlen($data)-$fix_header['data_len'];
                $topic = $this->decodeString(substr($data, $offset));
                $offset += strlen($topic) + 2;



                $msg = substr($data, $offset);

                if($fix_header['qos']>0){
                    $msb=ord($msg{0});
                    $lsb=ord($msg{1});
                    if($fix_header['qos']==1){
                        $resp = chr(0x40).chr(2).chr($msb).chr($lsb);
                        $serv->send($fd,$resp);
                    }
                    if($fix_header['qos']==2){
                        $resp = chr(0x50).chr(2).chr($msb).chr($lsb);
                        $serv->send($fd,$resp);
                    }
                    $msg = substr($data, $offset+2);
                }

                // $msg_id1 = ord($data[2]);
                // $msg_id2 = ord($data[3]);


                //异步执行任务
                // $serv->task($data);

                foreach($serv->connections as $fd1)
                {
                    $serv->send($fd1, $data);
                }


                echo "client msg: $topic\n---------------------------------\n$msg\n---------------------------------\n";
                // $client_id = $this->redis_get("client_".$fd);
                break;
            case 4:
                break;
            case 5:
                $msb = ord($data[2]);
                $lsb = ord($data[3]);
                $resp = chr(0x60).chr(2).chr($msb).chr($lsb);
                $serv->send($fd,$resp);
                break;
            case 6:
                $msb = ord($data[2]);
                $lsb = ord($data[3]);
                $resp = chr(0x70).chr(2).chr($msb).chr($lsb);
                $serv->send($fd,$resp);
                break;
            case 7:
                break;
            case 8:
                $this->debug("SUBSCRIBE");
                //id有可能是两个字节的,这个需要更多的测试
//                $msg_id = ord($data[2]);
//                $msg_id = ord($data[3]);
                $msg_id1 = ord($data[2]);
                $msg_id2 = ord($data[3]);
                $fix_header['sign'] = ($byte & 0x02) >> 1;
                $qos = ord($data[$fix_header['data_len']+1]);
                if($fix_header['sign']==1)
                {
                    echo "this is subscribe message!!!!\n";
                    $this->debug($msg_id1.$msg_id2,"msg id");
                    $this->debug($qos,"QOS");
                    //这里没有从协议中读取topic的长度,按照固定的写做6
                    $offset=strlen($data)-$fix_header['data_len']+4;
                    $topic = substr($data,$offset,$fix_header['data_len']-1);
                    var_dump($offset);
                    $this->debug($topic,"topic");
                }
                $this->topics[]=$topic;
                //订阅后返回
                $resp = chr(0x90).chr(3).chr($msg_id1).chr($msg_id2).chr(0);
                $this->printstr($resp);
                $serv->send($fd,$resp);
                $this->debug("send SUBACK");
                break;
            case 10:
                $this->debug("UNSUBSCRIBE");
                break;
            case 12:
                $this->debug("PINGREQ");
                $resp = chr(0xd0) . chr(0);//转换为二进制返回应该使用chr
                //保存最后ping的时间
                $serv->send($fd, $resp);
                $this->debug("Send PINGRESP");
                break;
            case 14:
                $this->debug("DISCONNECT");
                break;
        }
    }
    public function decodeValue($data)
    {
        return 256 * ord($data[0]) + ord($data[1]);
    }
    public function decodeString($data)
    {
        $length = $this->decodeValue($data);
        return substr($data, 2, $length);
    }
    public function get_connect_info($data)
    {
        $connect_info['protocol_name'] = $this->decodeString($data);
        $offset = strlen($connect_info['protocol_name']) + 2;
        $connect_info['version'] = ord(substr($data, $offset, 1));
        $offset += 1;
        $byte = ord($data[$offset]);
        $connect_info['willRetain'] = ($byte & 0x20 == 0x20);
        $connect_info['willQos'] = ($byte & 0x18 >> 3);
        $connect_info['willFlag'] = ($byte & 0x04 == 0x04);
        $connect_info['cleanStart'] = ($byte & 0x02 == 0x02);
        $offset += 1;
        $connect_info['keepalive'] = $this->decodeValue(substr($data, $offset, 2));
        $offset += 2;
        $connect_info['clientId'] = $this->decodeString(substr($data, $offset));
        $offset += strlen($connect_info['clientId']);
        $offset+=2;
        $connect_info['username'] = $this->decodeString(substr($data, $offset));
        $offset += strlen($connect_info['username']);
        $offset+=2;
        $connect_info['password'] = $this->decodeString(substr($data, $offset));
        return $connect_info;
    }
    public function debug($str,$title = "Debug")
    {
        echo "-------------------------------\n";
        echo '[' . date("Y-m-d H:i:s",time()). "] ".$title .':['. $str . "]\n";
        echo "-------------------------------\n";
    }
    public function printstr($string){
        $strlen = strlen($string);
        for($j=0;$j<$strlen;$j++){
            $num = ord($string{$j});
            if($num > 31)
                $chr = $string{$j}; else $chr = " ";
            printf("%4d: %08b : 0x%02x : %s \n",$j,$num,$num,$chr);
        }
    }
    /* getmsglength: */
    public function getmsglength(&$msg, &$i){
        $multiplier = 1;
        $value = 0 ;
        do{
            $digit = ord($msg{$i});
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $i++;
        }while (($digit & 128) != 0);
        return $value;
    }
    /* setmsglength: */
    public function setmsglength($len){
        $string = "";
        do{
            $digit = $len % 128;
            $len = $len >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ( $len > 0 )
                $digit = ($digit | 0x80);
            $string .= chr($digit);
        }while ( $len > 0 );
        return $string;
    }
    /* strwritestring: writes a string to a buffer */
    public function strwritestring($str, &$i){
        $ret = " ";
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        $ret = chr($msb);
        $ret .= chr($lsb);
        $ret .= $str;
        $i += ($len+2);
        return $ret;
    }
    /* publish: publishes $content on a $topic */
    function send_publish($topic, $content, $qos = 0, $retain = 0)
    {
        $i = 0;
        $buffer = "";

        $buffer .= $this->strwritestring($topic,$i);

        //$buffer .= $this->strwritestring($content,$i);

        if($qos){
            $lsbmsb=Cache::get('lsbmsb')?:1;
            if($lsbmsb>65535){
                $lsbmsb=1;
            }else{
                $lsbmsb+=1;
            }
            Cache::set('lsbmsb',$lsbmsb);
            $buffer .= chr($lsbmsb >> 8);  $i++;
            $buffer .= chr($lsbmsb % 256);  $i++;
        }

        $buffer .= $content;
        $i+=strlen($content);


        $head = " ";
        $cmd = 0x30;
        if($qos) $cmd += $qos << 1;
        if($retain) $cmd += 1;

        $head{0} = chr($cmd);
        $head .= $this->setmsglength($i);

        // $buffer = "";
        // $buffer .= $topic;
        // if($qos>0){
        //   $lsbmsb=Cache::get('lsbmsb')?:1;
        //   if($lsbmsb>65535){
        //      $lsbmsb=1;
        //   }
        //   Cache::set('lsbmsb',$lsbmsb+1);
        //             var_dump($lsbmsb);
        //   if($lsbmsb<=255){
        //     $lsbmsb=chr(0).chr($lsbmsb);
        //   }else{
        //     $lsbmsb=dechex($lsbmsb);
        //     $lsbmsb=str_pad($lsbmsb,4,'0',STR_PAD_LEFT);
        //     $lsbmsb=chr(hexdec($lsbmsb{0}.$lsbmsb{1})).chr(hexdec($lsbmsb{2}.$lsbmsb{3}));
        //   }  
        //   $buffer .=$lsbmsb;
        // }
        // $buffer .= $content;
        // $head = " ";
        //  //todo qos
        // switch ($qos*2+$retain) {
        //     case 1:
        //         $cmd = 0x31;
        //         break;
        //     case 2:
        //         $cmd = 0x32;
        //         break;
        //     case 3:
        //         $cmd = 0x33;
        //         break;
        //     case 4:
        //         $cmd = 0x34;
        //         break;
        //     case 5:
        //         $cmd = 0x35;
        //         break;
        //     default:
        //         $cmd = 0x30;
        //         break;
        // }
        // $head{0} = chr($cmd);
        // $head .= $this->setmsglength(strlen($topic)+strlen($content)+2);     

        echo "+++++++++++++++++++++++++++\n";
        $this->printstr($head.chr(0).chr(0x04).$buffer);
        echo "+++++++++++++++++++++++++++\n";
        //todo topic lenth
        return $head.chr(0).$this->setmsglength(strlen($topic)).$buffer;
    }
}

$server = new Server();


