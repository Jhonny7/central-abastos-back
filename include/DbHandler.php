<?php
/**
 *
 * @About:      Database connection manager class
 * @File:       Database.php
 * @Date:       $Date:$ Nov-2015
 * @Version:    $Rev:$ 1.0
 * @Developer:  Federico Guzman (federicoguzman@gmail.com)
 **/
class DbHandler {
 
    private $conn;
 
    function __construct() {
        require_once dirname(__FILE__) . '/DbConnect.php';//asi en el server
        //require_once dirname(__FILE__) . './DbConnect.php';// asi en desarrollo
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
    }

    function getConnection(){
        return $this->conn;
    }
 
    public function createAuto($array)
    {
        //aqui puede incluir la logica para insertar el nuevo auto a tu base de datos
    }

    function getRadios() {
    $sql = 'SELECT * FROM radio_configuracion';
    $resultado = $this->conn->query($sql);
    $my_array = array();

    $array = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    return $array;
}
 
}
 
?>