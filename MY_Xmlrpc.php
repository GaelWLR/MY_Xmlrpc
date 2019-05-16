<?php

/**
 * Class MY_Xmlrpc
 *
 * Modifie CI_Xmlrpc afin d'ajouter la gestion du protocole https en changeant le client.
 */
class MY_Xmlrpc extends CI_Xmlrpc
{
    /**
     * Parse server URL
     *
     * @param string $url
     * @param int $port
     * @param string|bool $proxy
     * @param int $proxy_port
     * @return void
     */
    public function server($url, $port = 80, $proxy = false, $proxy_port = 8080)
    {
        if (stripos($url, 'http') !== 0) {
            $url = 'http://' . $url;
        }

        $parts = parse_url($url);

        if (isset($parts['user'], $parts['pass'])) {
            $parts['host'] = $parts['user'] . ':' . $parts['pass'] . '@' . $parts['host'];
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';

        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        // Utilisation de MY_XML_RPC_Client à la place de XML_RPC_Client
        $this->client = new MY_XML_RPC_Client($path, $parts['host'], $port, $proxy, $proxy_port);
    }
}

/**
 * Class MY_XML_RPC_Client
 *
 * Modifie XML_RPC_Client afin d'ajouter la gestion du protocole https en ajoutant le protocole ssl.
 */
class MY_XML_RPC_Client extends XML_RPC_Client
{
    /**
     * Send payload
     *
     * @param object $msg
     * @return object
     */
    public function sendPayload($msg)
    {
        if ($this->proxy === false) {
            $server = $this->server;
            $port = $this->port;
        } else {
            $server = $this->proxy;
            $port = $this->proxy_port;
        }

        // Ajout de la gestion du port 443
        if ((int)$port === 443) {
            $server = 'ssl://' . $server;
        }

        $fp = @fsockopen($server, $port, $this->errno, $this->errstring, $this->timeout);

        if (!is_resource($fp)) {
            error_log($this->xmlrpcstr['http_error']);
            return new XML_RPC_Response(0, $this->xmlrpcerr['http_error'], $this->xmlrpcstr['http_error']);
        }

        if (empty($msg->payload)) {
            // $msg = XML_RPC_Messages
            $msg->createPayload();
        }

        $r = "\r\n";
        $op = 'POST ' . $this->path . ' HTTP/1.0' . $r
            . 'Host: ' . $this->server . $r
            . 'Content-Type: text/xml' . $r
            . (isset($this->username, $this->password) ? 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . $r : '')
            . 'User-Agent: ' . $this->xmlrpcName . $r
            . 'Content-Length: ' . strlen($msg->payload) . $r . $r
            . $msg->payload;

        stream_set_timeout($fp, $this->timeout); // set timeout for subsequent operations

        for ($written = $timestamp = 0, $length = strlen($op); $written < $length; $written += $result) {
            if (($result = fwrite($fp, substr($op, $written))) === false) {
                break;
            }

            if ($result === 0) {
                if ($timestamp === 0) {
                    $timestamp = time();
                } elseif ($timestamp < (time() - $this->timeout)) {
                    $result = false;
                    break;
                }
            } else {
                $timestamp = 0;
            }
        }

        if ($result === false) {
            error_log($this->xmlrpcstr['http_error']);
            return new XML_RPC_Response(0, $this->xmlrpcerr['http_error'], $this->xmlrpcstr['http_error']);
        }

        $resp = $msg->parseResponse($fp);
        fclose($fp);
        return $resp;
    }
}