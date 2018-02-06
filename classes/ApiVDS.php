<?php
namespace Classes;

/**
 * Управление VDS-машинами
 */
class ApiVDS
{
    // методы запросов
    const METHOD_GET    = 'GET';
    const METHOD_POST   = 'POST';
    const METHOD_PUT    = 'PUT';
    const METHOD_DELETE = 'DELETE';

    const CONNECTION_TIMEOUT = 10;
    
    protected $host;
    protected $port;
    protected $timeout;

    public function __construct($host, $port) 
    {
        $this->host = $host;
        $this->port = $port;
        
        $this->timeout = self::CONNECTION_TIMEOUT;
    }

    public function setTimeout($timeout) 
    {
        $this->timeout = $timeout;
    }

    /**
     * получение строки запроса с модификацией массива параметров
     * @param $command - команда, например: distributives, domain/show/name
     *
     * пример получаемой строки:
     * https://some.host:443/api/vds/show/some-name
     *
     * @return string
     */
    protected function buildRequest($command) 
    {
        return "https://{$this->host}:{$this->port}/api/{$command}";
    }

    /**
     * выполнение запроса
     *
     * @param string $command        - тип команды
     * @param string $method         - метод запроса
     * @param array  $params         - параметры
     * @return mixed
     * @throws SystemException
     */
    protected function sendRequest($command, $method, $params = []) 
    {
        // собираем URL для запроса
        $request_url = $this->buildRequest($command);
        
        if (!isset($params['async'])) {
            // все операции по умолчанию асинхронные
            $params['async'] = true;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_USERAGENT, __CLASS__);
        
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ch_errno = curl_errno($ch);
        $ch_error = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200) {
            $result = json_decode($resp, true);
            return $result['data'];
        } else {
            // сбор ошибки
            $error = [];
            $virctlError = [];
            $result = json_decode($resp, true);

            if (isset($result['error'])) {
                $error['code'] = (int)$result['error']['code'];
                $error['error'] = $result['error']['name'];
                $error['message'] = $result['error']['message'];
            } elseif ($http_code === 500) {
                $error['code'] = $http_code;
                $error['error'] = "HTTP Error";
            } else {
                $error['code'] = $ch_errno;
                $error['error'] = $ch_error;
            }

            $this->throwException(implode(' ', $error), $error['code'], $command, $params);
        }
    }
    
    /**
     * статус асинхронной операции
     * 
     * @param string $id - идентификатор, полученный при запросе на выполнение асинхронной операции
     * @return mixed
     */
    public function status($id) 
    {
        return $this->sendRequest("task/status/{$id}", self::METHOD_GET, [
            'async' => false
        ]);
    }

    /**
     * Добавление новой VDS
     *
     * @param string $name         - имя
     * @param string $description  - описание
     * @param string $distributive - дистрибутив
     * @param string $arch         - архитектура
     * @param array  $quota        - {cpu_count: int, // кол-во CPU
     *                                hdd_quota: int, // объем диска, байт
     *                                memory: int}    // озу, байт
     * @return mixed
     */
    public function add($name, $description, $distributive, $arch, array $quota) 
    {
        $params = [
            'description'  => $description,
            'distributive' => $distributive,
            'cpu-count'    => $quota['cpu_count'],
            'hdd-quota'    => $quota['hdd_quota'],
            'memory'       => $quota['memory'],
        ];

        return $this->sendRequest("vds/add/base/{$name}", self::METHOD_POST, $params);
    }

    /**
     * переустановка VDS
     *
     * @param string $name         - имя машины
     * @param string $distributive - дистрибутив
     * @param string $arch         - архитектура
     * @param null   $pubkey
     * @return mixed
     */
    public function reinstall($name, $distributive, $arch) 
    {
        return $this->sendRequest("vds/reinstall/{$name}", self::METHOD_PUT, [
            'distributive' => $distributive, 
            'arch' => $arch,
        ]);
    }

    /**
     * изменение параметров VDS
     *
     * @param string $name      - имя
     * @param array $params     - параметры для изменения, допускается передача от одного до всех параметров одновременно
     *                          [
     *                              'memory'        => {integer} байт,
     *                              'hdd_quota'     => {integer} байт,
     *                              'cpu_count'     => {integer},
     *                              'root_password' => {string},
     *                              'vnc_password'  => {string}
     *                          ]
     * @return mixed
     */
    public function modify($name, array $params) 
    {
        return $this->sendRequest("vds/modify/{$name}", self::METHOD_PUT, $params);
    }
    
    /**
     * запуск VDS
     *
     * @param string $name - имя
     *
     * @return mixed
     */
    public function start($name) 
    {
        return $this->sendRequest("vds/start/{$name}", self::METHOD_PUT);
    }
    
    /**
     * остановка VDS
     *
     * @param string $name - имя
     *
     * @return mixed
     */
    public function stop($name) 
    {
        return $this->sendRequest("vds/stop/{$name}", self::METHOD_PUT);
    }

    /**
     * перезапуск VDS
     *
     * @param string $name - имя
     * @param bool   $force - принудительно
     * @return mixed
     */
    public function restart($name, $force = false) 
    {
        return $this->sendRequest("vds/restart/{$name}", self::METHOD_PUT, [
            'force' => $force
        ]);
    }

    /**
     * удаление VDS
     
     * @param string $name  - имя
     * @return mixed
     * @throws Exception
     */
    public function delete($name) 
    {
        try {
            return $this->sendRequest("vds/delete/{$name}", self::METHOD_DELETE);
        } catch (Exception $e) {
            if ($e->getCode() != self::ERROR_NOT_EXISTS) throw $e;
        }
    }
    
    /**
     * подробная информация
     *
     * @param $name - имя
     *
     * @return mixed
     * @throws Exception
     */
    public function show($name) 
    {
        try {
            return $this->sendRequest("vds/show/{$name}", self::METHOD_GET, [
                'async' => false,
            ]);
        } catch (Exception $e) {
            if ($e->getCode() == self::ERROR_NOT_EXISTS) {
                // если vds не существует - вернем null без исключения
                return null;
            } else {
                throw $e;
            }
        }
    }

    /**
     * ОБРАБОТКА ОШИБОК
     *
     * @param       $msg     - описание ошибки
     * @param       $code    - код ошибки
     * @param       $command - команда
     * @param       $params  - параметры запроса
     *
     * @throws Exception
     */
    protected function throwException($msg, $code, $command = null, $params = null) 
    {
        switch ($code) {
            case 1:
                $code = self::ERROR_CRITICAL;
                break;
            case 2:
                $code = self::ERROR_VALIDATION;
                break;
            case 3:
                $code = self::ERROR_NOT_EXISTS;
                break;
            case 4:
                $code = self::ERROR_ALREADY_EXISTS;
                break;
            case 5:
                $code = self::ERROR_PROCESS_TIMEOUT;
                break;
            case 7:
                $code = self::ERROR_JSON_DECODE;
                break;
            case 8:
                $code = self::ERROR_SYSTEM_USER;
                break;
            case 9:
                $code = self::ERROR_UNAVAILABLE;
                break;
        }
        
        error_log('Error: ' . $code . ' ' . $msg);
        
        if (isset($command)) error_log($command);
        if (isset($params)) error_log(print_r($params, true));                

        throw new Exception($msg, $code);
    }
}
