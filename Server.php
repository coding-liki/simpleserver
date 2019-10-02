<?php
namespace CodingLiki\SimpleServer;

use Closure;
use CodingLiki\Configs\Config;

/**
 * Простой сервер для сокетов, 
 * с возможностью обработки прочтённых данных 
 * и обработки отправки данных в ждущие сокеты
 */
class Server{

    private $debug = false;
    private $master;
    private $host;
    private $port;
    private $max_connections;
    private $clients = [];
    private $to_delete = [];
    protected $read_func;
    protected $send_func;

    public function __construct($config_file = "simple_server") {
        $this->master  = socket_create(AF_INET,SOCK_STREAM, 0);

        $config_array = Config::config($config_file);
        $this->host = $config_array['host'] ?? "0.0.0.0";
        $this->port = $config_array['port'] ?? "8080";
        $this->max_connections = $config_array['max_connections'] ?? 200;
        $this->debug = $config_array['debug'] ?? false;
        // Разрешаем повторное использование адреса
        if (!socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1)) { 
            echo socket_strerror(socket_last_error($this->master)); 
            exit;
        }

        $res  = true;

        // Подключаем прослушку порта
        $res &= @socket_bind($this->master, $this->host, $this->port);
        $res &= @socket_listen($this->master);

        // Умираем в случае ошибок
        if (!$res) {
            die ("Could_not bind and listen $this->host: $this->port\n");
        }
    }

    public function debugWrite($data){
        if($this->debug){
            echo $data;
        }
    }

    /**
     * Устанавливаем функцию, которая будет отрабатывать для каждый полученных данных
     *
     * @param Closure $read_func = function ( Server $server, $key, $data )
     * $server : объект запущенного сервера
     * $key: ключ клиента в массиве клиентов
     * $data: данные, полученные из сокета клиента
     * @return void
     */
    public function setReadFunction(Closure $read_func){
        $this->read_func = $read_func;
    }

    /**
     * Устанавливаем функию, которая будет отрабатывать для всех клиентов, которые ожидают отправки данных
     *
     * @param Closure $send_func = function (Server $server, $key)
     * $server : объект запущенного сервера
     * $key: ключ клиента в массиве клиентов
     * @return void
     */
    public function setSendFunction(Closure $send_func){
        $this->send_func = $send_func;
    }

    /**
     * Чтение данных из сокета до победного
     */
    public function readAllData($client) {
        $data = "";
        $buf = socket_read($client, 255);
        while(!empty($buf)) {
            $data .= $buf;
            $len = strlen($buf);
            if($len < 255) {
                break;
            }
            $buf = socket_read($client, 255);
        }

        return $data;
    }

    /**
     * Удаляем клиента и закрываем его сокет
     *
     * @param [type] $key
     * @return void
     */
    public function deleteClient($key){
        $client = $this->clients[$key] ?? null;
        if ($client != null) {
            socket_shutdown($client);
            unset($this->clients[$key]);
        }

        if(count($this->clients) == 0){
            $this->clients = [];
        }
    }
    public function checkDeleteClients(){
        foreach($this->to_delete as $delete_key){
            $this->deleteClient($delete_key);
        }

        $this->to_delete = [];
    }

    public function checkReadClients($read, $num_changed){
        $this->debugWrite("Сокетов для проверки $num_changed\n");
                

        /** Принимаем новое подключение */
        if (in_array($this->master, $read)) {
            if (count($this->clients) < $this->max_connections) {
                $this->clients[]= socket_accept($this->master);
                $this->debugWrite("Принято подключение (" . count($this->clients)  . " of ".$this->max_connections." clients)\n");
            }
        }

        /** Проверяем подключенных клиентов на необходимость чтения*/
        foreach ($this->clients as $key => $client) {
            if (in_array($client, $read)) {
                $data = $this->readAllData($client);
                if ($this->needToDelete($key, $data)) {
                    continue;
                }

                ($this->read_func)($this, $key, $data);
            }
        }
    }

    /**
     * Проверяем необходимость удаления
     *
     * @param [type] $key
     * @param [type] $data
     * @return bool
     */
    public function needToDelete($key, $data){
        if (empty($data)) {
            $this->to_delete[] = $key;
            return true;
        }
        return false;
    }

    /**
     * Обрабатываем клиентов, которым нужно послать данные
     *
     * @return void
     */
    public function checkSendClients(){
        foreach($this->clients as $key => $client) {
            ($this->send_func)($this, $key);
        }
    }

    /**
     * Запускаем простой сервер на сокете
     *
     * @return void
     */
    public function run(){
        $abort = false;
        $read = [$this->master];

        $NULL = NULL; // Так надо

        while (!$abort) {
            $num_changed = socket_select($read, $NULL, $NULL, 0, 10); // Количество изменений
            
            if ($num_changed > 0) {
                $this->checkDeleteClients();
                $this->checkReadClients($read, $num_changed);
            } else if(count($this->clients) > 0){
                $this->checkSendClients();
            }

            $read   = $this->clients;
            $read[] = $this->master;
        }
    }

    /**
     * Отсылаем данные клиенту
     *
     * @param [type] $data : Данные строкой
     * @param [type] $client_key : ключ в массиве клиентов
     * @return void
     */
    public function clientWrite($data, $client_key){
        $client = $this->clients[$client_key] ?? null;
        if ($client != null) {
            socket_write($client, $data);
        }
    }
    /** 
     * Закрываем сокет 
     * */
    public function destroy() {
        socket_shutdown($this->master);
    }
}