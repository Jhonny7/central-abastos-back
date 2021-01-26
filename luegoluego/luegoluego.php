        <?php

        ini_set('display_errors','1');
        error_reporting(E_ALL);

        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Allow: GET, POST, OPTIONS, PUT, DELETE");

        header("Expires: 0");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        $method = $_SERVER['REQUEST_METHOD'];
        if ($method == "OPTIONS") {
            die();
        }

        require_once '../include/DbHandler.php';

        ///Clases de notificación
        require_once '../services/fcm_service.php';
        require_once '../services/fcm_service_proveedor.php';
        require_once '../services/fcm_service_transportista.php';
        ////////////////////////

        require_once '../luegoluego/Encrypt.php';
        require '../libs/Slim/Slim.php';

        require '../libs/PHPMailer/src/PHPMailer.php';
        require '../libs/PHPMailer/src/SMTP.php';
        require '../libs/PHPMailer/src/Exception.php';
        require_once '../stripe/stripe/init.php';
        /*
        require("/home/site/libs/PHPMailer-master/src/PHPMailer.php");
        require("/home/site/libs/PHPMailer-master/src/SMTP.php");

        $mail = new PHPMailer\PHPMailer\PHPMailer();
         */

        \Slim\Slim::registerAutoloader();
        $app = new \Slim\Slim();

        $app->post('/fcm', function () use ($app) {
            $response = array();
            $body = $app->request->getBody();
            $data = json_decode($body, true);
            try {
                $fcm = new FCMNotification();
                $return = $fcm->sendDataJSON($data);
                $response["status"] = true;
                $response["description"] = "SUCCESSFUL-FCM";
                $response["idTransaction"] = time();
                $response["parameters"] = [];
                $response["timeRequest"] = date("Y-m-d H:i:s");
                echoResponse(200, $response);
            } catch (Exception $e) {
                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");
                echoResponse(400, $response);
            }
        });

        $app->post('/register', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $body = $app->request->getBody();
            $data = json_decode($body, true);
            try {

                $login = $data['login'];
                $email = $data['email'];
                $firstName = $data['firstName'];
                $lastName = $data['lastName'];
                $motherLastName = $data['motherLastName'];
                $telefono = $data['telefono'];
                $fechaNacimiento = $data['fechaNacimiento'];
                $genero = $data['genero'];
                $password = $data['password'];
                $activated = $data['activated'];
                $file = $data['adjunto'];
                $tipoUsuario = $data['tipoUsuario'];
                $tipoPersona = null;
                $domicilio = null;
                $razonSocial = null;

                //Id de inserción
                $idAdjuntoTmp = null;
                $idUserTmp = null;
                $idDomicilioTmp = null;
                $idProveedorTmp = null;
                //////////////////
                $sqlSearch = "SELECT * FROM jhi_user WHERE login = ?";
                $sthSearch = $db->prepare($sqlSearch);
                $sthSearch->bindParam(1, $login, PDO::PARAM_STR);
                $sthSearch->execute();
                $rows = $sthSearch->fetchAll(PDO::FETCH_ASSOC);

                if ($rows && $rows[0]) {
                    $response["status"] = true;
                    $response["description"] = "Correo ya registrado";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");
                    echoResponse(400, $response);
                } else {
                    if ($tipoUsuario > 2) {
                        $tipoPersona = $data['tipoPersona'];

                        $razonSocial = $data['razonSocial'];
                    }
                    if ($tipoUsuario == 3) {
                        $domicilio = $data['direccion'];
                    }
                    //Agregar adjunto

                    if ($file != null && $file != "null") {
                        $separado = explode("base64,", $file["file"])[1];
                        $blobData = base64_decode($separado);

                        $sqlBlob = "INSERT INTO adjunto (content_type, size, file_name, file_content_type, file)
                            VALUES (?,?,?,?,?)";
                        $sthBlob = $db->prepare($sqlBlob);
                        $sthBlob->bindParam(1, $file["contentType"], PDO::PARAM_STR);
                        $sthBlob->bindParam(2, $file["size"], PDO::PARAM_STR);
                        $sthBlob->bindParam(3, $file["fileName"], PDO::PARAM_STR);
                        $sthBlob->bindParam(4, $file["contentType"], PDO::PARAM_STR);

                        $sthBlob->bindParam(5, $blobData, PDO::PARAM_LOB);
                        $sthBlob->execute();
                        $idAdjuntoTmp = $db->lastInsertId();
                    }
                    /////

                    //Agregar domicilio
                    if ($domicilio != null && $domicilio != "null" && $tipoUsuario == 3) {
                        $sqlDireccion = "INSERT INTO direccion (direccion, codigo_postal, latitud, longitud, fecha_alta)
                                         VALUES (?, ?, ?, ?, now())";
                        $sthDireccion = $db->prepare($sqlDireccion);
                        $sthDireccion->bindParam(1, $domicilio["direccion"], PDO::PARAM_STR);
                        $sthDireccion->bindParam(2, $domicilio["codigoPostal"], PDO::PARAM_STR);
                        $sthDireccion->bindParam(3, $domicilio["latitud"], PDO::PARAM_STR);
                        $sthDireccion->bindParam(4, $domicilio["longitud"], PDO::PARAM_STR);
                        $sthDireccion->execute();
                        $idDomicilioTmp = $db->lastInsertId();
                    }
                    ///

                    $passwordEncriptado = dec_enc("encrypt", $password);
                    $sqlUser = "INSERT INTO jhi_user (login, password_hash, first_name, last_name, mother_last_name,
                                telefono, genero, fecha_nacimiento, email, image_url, activated, lang_key, tipo_persona_id,
                                created_by, created_date, adjunto_id, tipo_usuario_id)
                                VALUES (?, MD5(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'es', ?, 'anonymousUser', now(), ?, ?)";

                    $activo = $activated ? 1 : 0;

                    $tipoPersonaGenerico = $tipoUsuario > 2 ? $tipoPersona : 2;

                    $sthUser = $db->prepare($sqlUser);
                    $sthUser->bindParam(1, $login, PDO::PARAM_STR);
                    $sthUser->bindParam(2, $passwordEncriptado, PDO::PARAM_STR);
                    $sthUser->bindParam(3, $firstName, PDO::PARAM_STR);
                    $sthUser->bindParam(4, $lastName, PDO::PARAM_STR);
                    $sthUser->bindParam(5, $motherLastName, PDO::PARAM_STR);
                    $sthUser->bindParam(6, $telefono, PDO::PARAM_STR);
                    $sthUser->bindParam(7, $genero, PDO::PARAM_STR);
                    $sthUser->bindParam(8, $fechaNacimiento, PDO::PARAM_STR);
                    $sthUser->bindParam(9, $email, PDO::PARAM_STR);
                    $sthUser->bindParam(10, $file["file"], PDO::PARAM_STR);
                    $sthUser->bindParam(11, $activo, PDO::PARAM_INT);
                    $sthUser->bindParam(12, $tipoPersonaGenerico, PDO::PARAM_INT);
                    $sthUser->bindParam(13, $idAdjuntoTmp, PDO::PARAM_INT);
                    $sthUser->bindParam(14, $tipoUsuario, PDO::PARAM_INT);

                    $sthUser->execute();
                    $idUserTmp = $db->lastInsertId();

                    //Se verifica si es proveedor
                    if ($tipoUsuario == 3) {
                        //Automaticamente aqui se agrega proveedor
                        $sqlProveedor = "INSERT INTO proveedor (usuario_id, empresa_id, direccion_id, nombre, fecha_alta,
                                         fecha_modificacion, transportista_id)
                                         VALUES (?, 1, ?, ?, now(), now(), 1)";

                        $sthProveedor = $db->prepare($sqlProveedor);
                        $sthProveedor->bindParam(1, $idUserTmp, PDO::PARAM_INT);
                        $sthProveedor->bindParam(2, $idDomicilioTmp, PDO::PARAM_INT);
                        $sthProveedor->bindParam(3, $razonSocial, PDO::PARAM_INT);

                        $sthProveedor->execute();
                        $idProveedorTmp = $db->lastInsertId();
                        ///////
                    }

                    //Se verifica si es transportista
                    if ($tipoUsuario == 4) {
                        //Automaticamente aqui se agrega proveedor
                        $sqlTransportista = "INSERT INTO transportista (usuario_id, empresa_id, nombre, fecha_alta,
                                         fecha_modificacion)
                                         VALUES (?, 1, ?, now(), now())";

                        $sthTransportista = $db->prepare($sqlTransportista);
                        $sthTransportista->bindParam(1, $idUserTmp, PDO::PARAM_INT);
                        $sthTransportista->bindParam(2, $razonSocial, PDO::PARAM_INT);

                        $sthTransportista->execute();
                        $idTransportistaTmp = $db->lastInsertId();
                        ///////
                    }

                    $db->commit();

                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");
                    echoResponse(201, $response);
                }
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");
                echoResponse(400, $response);
            }
        });

        $app->post('/authenticate', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $body = $app->request->getBody();
            $data = json_decode($body, true);

            $rows = null;
            $passwordEncriptado = null;
            try {

                $password = $data['password'];
                $username = $data['username'];

                $passwordEncriptado = dec_enc("encrypt", $password);
                //Busqueda de usuario
                $sqlSearch = "SELECT * FROM jhi_user WHERE password_hash = MD5(?) AND `login` = ?";
                $sthSearch = $db->prepare($sqlSearch);
                $sthSearch->bindParam(1, $passwordEncriptado, PDO::PARAM_STR);
                $sthSearch->bindParam(2, $username, PDO::PARAM_STR);
                $sthSearch->execute();
                $rows = $sthSearch->fetchAll(PDO::FETCH_ASSOC);

                if ($rows && $rows[0]) {
                    $objetoReturn = array();
                    /*
                    email: "sarre@gmail.com"
                    id_token: "."
                    nombre: "Juan Lopez  Sarre"
                    parametros: {pantalla_proveedores: "S"}
                    pantalla_proveedores: "S"
                    tipo_usuario: 3
                    username: "sarre@gmail.com"
                     */
                    $sqlParams = "SELECT clave,descripcion FROM parametros_aplicacion";
                    $sthParams = $db->prepare($sqlParams);
                    $sthParams->execute();
                    $rowsParams = $sthParams->fetchAll(PDO::FETCH_ASSOC);

                    $parametros = array();
                    for ($i = 0; $i < sizeof($rowsParams); $i++) {
                        # code... Ejemplo: $rowsParams[0],  $rowsParams[0]["clave"],
                        $objetoReturn[$rowsParams[0]["clave"]] = $rowsParams[0]["descripcion"];
                    }

                    $parametros["email"] = $rows[0]["email"];
                    $parametros["id_token"] = "tmp";
                    $parametros["nombre"] = $rows[0]["first_name"] . " " . $rows[0]["last_name"] . " " . $rows[0]["mother_last_name"];
                    $parametros["parametros"] = $objetoReturn;
                    $parametros["tipo_usuario"] = $rows[0]["tipo_usuario_id"];
                    $parametros["username"] = $rows[0]["login"];

                    echoResponse(200, $parametros);
                } else {
                    $response["status"] = true;
                    $response["description"] = "Usuario no encontrado";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");
                    echoResponse(400, $response);
                }
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");
                echoResponse(400, $response);
            }
        });

        $app->post('/generic-querie', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            $sqlTotal = $data['query'];
            $push = $data['push'];
            $token = $data['token'];
            $retorna = $data['retorna'];
            $rowsUser = null;
            $query = null;
            $step = 0;
            $rows = null;
            $ultimo = null;
            try {

                $query = returnD($sqlTotal);
                if (strpos(strtoupper($sqlTotal), "DELETE") === true) {
                    $response["status"] = false;
                    $response["description"] = "Imposible borrar información";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(400, $response);

                } else {

                    $step = 1;
                    $sthUsuario = $db->prepare($query);
                    $sthUsuario->execute();
                    if ($retorna == 1) {
                        $rows = $sthUsuario->fetchAll(PDO::FETCH_ASSOC);
                        $db->commit();
                        $aRetornar = $rows;
                    } else if ($retorna == 2) {
                        //$sqlTotal = "SELECT * FROM user WHERE uid = '1'";

                        //$sthUsuario = $db->prepare($sqlTotal);
                        //$sthUsuario->bindParam(1, $uid, PDO::PARAM_STR);
                        //$sthUsuario->execute();
                        $rows = $sthUsuario->fetchAll(PDO::FETCH_ASSOC);
                        $db->commit();
                        $aRetornar = $rows;
                    } else if ($retorna == 3) {
                        $ultimo = $db->lastInsertId();
                        $db->commit();
                        $aRetornar = $ultimo;
                    } else {
                        $db->commit();
                        $aRetornar = [];
                    }

                    if ($push !== null && $push !== "null") {
                        $fcm = new FCMNotification();
                        $title = "Menu Vid!";
                        $data_body = array(
                            'view' => 1,
                        );

                        $body = $push;
                        $notification = array(
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                            'click_action' => 'FCM_PLUGIN_ACTIVITY');

                        $arrayToSend = array(
                            'to' => $token,
                            'notification' => $notification,
                            'data' => $data_body,
                            'priority' => 'high');

                        if ($token !== "tokenFake") {
                            $return = $fcm->sendData($arrayToSend);
                        }
                    }

                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $aRetornar;
                    //$response["parameters2"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $response);
                }

            } catch (Exception $e) {
                if ($retorna) {
                    $db->rollBack();
                }

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["p2"] = $query;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/promociones', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                //$uid = $app->request()->params('uid');

                $query = "SELECT
                titulo,
                descripcion,
                link,
                adjunto_id as adjuntoId,
                fecha_alta as fechaAlta,
                fecha_modificacion as fechaModificacion,
                usuario_modificacion_id as usuarioModificacionId
                FROM abastos.promocion";
                $sth = $db->prepare($query);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                $response["status"] = true;
                $response["description"] = "SUCCESSFUL";
                $response["idTransaction"] = time();
                $response["parameters"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $rows);

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/proveedor-productos/home/:idSeccion', function ($idSeccion) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                //$uid = $app->request()->params('uid');

                $query = "SELECT * FROM categoria WHERE seccion_id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $idSeccion, PDO::PARAM_INT);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
                //$rows = convertKeysToCamelCase($rows);
                if ($rows && $rows[0]) {
                    $productosCategoria = array();
                    $k = 0;
                    foreach ($rows as $key => $cat) {
                        $cat = convertKeysToCamelCase($cat);
                        # code...
                        //Buscar seccion de categoria

                        /*
                        adjunto: null
                        adjuntoId: 52
                        descripcion: null
                        empresa: {id: 1, nombre: "Central de Abastos"}
                        empresaId: 1
                        fechaAlta: null
                        fechaModificacion: null
                        icono: null
                        id: 1
                        nombre: "Compras"
                        usuarioAltaId: null
                        usuarioModificacionId: null
                         */
                        $querySeccion = "SELECT *
                         FROM seccion WHERE id = ?";
                        $sthSeccion = $db->prepare($querySeccion);
                        $sthSeccion->bindParam(1, $idSeccion, PDO::PARAM_INT);
                        $sthSeccion->execute();
                        $rowsSeccion = $sthSeccion->fetchAll(PDO::FETCH_ASSOC);
                        //$rowsSeccion = convertKeysToCamelCase($rowsSeccion);
                        $cat["seccion"] = convertKeysToCamelCase($rowsSeccion[0]);
                        if ($rowsSeccion && $rowsSeccion[0]) {
                            $queryEmpresa = "SELECT * FROM empresa WHERE id = ?";
                            $sthEmpresa = $db->prepare($queryEmpresa);
                            $sthEmpresa->bindParam(1, $cat["seccion"]["empresaId"], PDO::PARAM_INT);
                            $sthEmpresa->execute();
                            $rowsEmpresa = $sthEmpresa->fetchAll(PDO::FETCH_ASSOC);
                            //$rowsEmpresa = convertKeysToCamelCase($rowsEmpresa);
                            $cat["seccion"]["empresa"] = convertKeysToCamelCase($rowsEmpresa[0]);
                        }
                        //
                        $productosCategoria[$k]["categoria"] = $cat;

                        /** Buscar productos por categoria  **/
                        $queryProductos = "SELECT pp.* FROM producto_proveedor pp
                        INNER JOIN producto p
                        ON (p.id = pp.producto_id)
                        INNER JOIN tipo_articulo ta
                        ON (ta.id = p.tipo_articulo_id)
                        INNER JOIN categoria c
                        ON (c.id = ta.categoria_id)
                        WHERE ta.categoria_id = ?";
                        $sthProductos = $db->prepare($queryProductos);
                        $sthProductos->bindParam(1, $cat["id"], PDO::PARAM_INT);
                        $sthProductos->execute();
                        $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);
                        //$rowsProductos = convertKeysToCamelCase($rowsProductos);
                        if ($rowsProductos) {
                            for ($i = 0; $i < sizeof($rowsProductos); $i++) {
                                # code...
                                $rowsProductos[$i] = convertKeysToCamelCase($rowsProductos[$i]);
                                if ($rowsProductos[$i]["productoId"]) {
                                    $user = "SELECT * FROM producto WHERE id = ?";
                                    $sthUser = $db->prepare($user);
                                    $sthUser->bindParam(1, $rowsProductos[$i]["productoId"], PDO::PARAM_INT);
                                    $sthUser->execute();
                                    $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
                                    //$rowsUser = convertKeysToCamelCase($rowsUser);
                                    if ($rowsUser && $rowsUser[0]) {
                                        $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                                        $rowsProductos[$i]["producto"] = $rowsUser[0];

                                        if ($rowsUser[0]["estatusId"]) {
                                            $estatus = "SELECT * FROM estatus WHERE id = ?";
                                            $sthEstatus = $db->prepare($estatus);
                                            $sthEstatus->bindParam(1, $rowsUser[0]["estatusId"], PDO::PARAM_INT);
                                            $sthEstatus->execute();
                                            $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                                            //$rowsEstatus = convertKeysToCamelCase($rowsEstatus);

                                            if ($rowsEstatus && $rowsEstatus[0]) {
                                                $rowsProductos[$i]["producto"]["estatus"] = convertKeysToCamelCase($rowsEstatus[0]);
                                            } else {
                                                $rowsProductos[$i]["producto"]["estatus"] = null;
                                            }
                                        }

                                        if ($rowsUser[0]["unidadMedidaId"]) {
                                            $unidad = "SELECT * FROM unidad_medida WHERE id = ?";
                                            $sthUnidad = $db->prepare($unidad);
                                            $sthUnidad->bindParam(1, $rowsUser[0]["unidadMedidaId"], PDO::PARAM_INT);
                                            $sthUnidad->execute();
                                            $rowsUnidad = $sthUnidad->fetchAll(PDO::FETCH_ASSOC);
                                            //$rowsUnidad = convertKeysToCamelCase($rowsUnidad);
                                            if ($rowsUnidad && $rowsUnidad[0]) {
                                                $rowsProductos[$i]["producto"]["unidadMedida"] = convertKeysToCamelCase($rowsUnidad[0]);
                                            } else {
                                                $rowsProductos[$i]["producto"]["unidadMedida"] = null;
                                            }
                                        }

                                        if ($rowsUser[0]["tipoArticuloId"]) {
                                            $articulo = "SELECT * FROM tipo_articulo WHERE id = ?";
                                            $sthArticulo = $db->prepare($articulo);
                                            $sthArticulo->bindParam(1, $rowsUser[0]["tipoArticuloId"], PDO::PARAM_INT);
                                            $sthArticulo->execute();
                                            $rowsArticulo = $sthArticulo->fetchAll(PDO::FETCH_ASSOC);

                                            //$rowsArticulo = convertKeysToCamelCase($rowsArticulo);
                                            if ($rowsArticulo && $rowsArticulo[0]) {
                                                $rowsArticulo[0] = convertKeysToCamelCase($rowsArticulo[0]);
                                                $rowsProductos[$i]["producto"]["tipoArticulo"] = $rowsArticulo[0];

                                                if ($rowsArticulo[0]["categoriaId"]) {
                                                    $categoria = "SELECT * FROM categoria WHERE id = ?";
                                                    $sthCategoria = $db->prepare($categoria);
                                                    $sthCategoria->bindParam(1, $rowsArticulo[0]["categoriaId"], PDO::PARAM_INT);
                                                    $sthCategoria->execute();
                                                    $rowsCategoria = $sthCategoria->fetchAll(PDO::FETCH_ASSOC);
                                                    //$rowsCategoria = convertKeysToCamelCase($rowsCategoria);
                                                    if ($rowsCategoria && $rowsCategoria[0]) {
                                                        $rowsCategoria[0] = convertKeysToCamelCase($rowsCategoria[0]);
                                                        $rowsProductos[$i]["producto"]["tipoArticulo"]["categoria"] = $rowsCategoria[0];

                                                        if ($rowsCategoria[0]["seccionId"]) {
                                                            $seccion = "SELECT * FROM seccion WHERE id = ?";
                                                            $sthSeccion = $db->prepare($seccion);
                                                            $sthSeccion->bindParam(1, $rowsCategoria[0]["seccionId"], PDO::PARAM_INT);
                                                            $sthSeccion->execute();
                                                            $rowsSeccion = $sthSeccion->fetchAll(PDO::FETCH_ASSOC);
                                                            //$rowsSeccion = convertKeysToCamelCase($rowsSeccion);
                                                            if ($rowsSeccion && $rowsSeccion[0]) {
                                                                $rowsProductos[$i]["producto"]["tipoArticulo"]["categoria"]["seccion"] = convertKeysToCamelCase($rowsSeccion[0]);
                                                            } else {
                                                                $rowsProductos[$i]["producto"]["tipoArticulo"]["categoria"]["seccion"] = null;
                                                            }
                                                        }
                                                    } else {
                                                        $rowsProductos[$i]["producto"]["tipoArticulo"]["unidadMedida"] = null;
                                                    }
                                                }
                                            } else {
                                                $rowsProductos[$i]["producto"]["tipoArticulo"] = null;
                                            }
                                        }
                                    } else {
                                        $rowsProductos[$i]["producto"] = null;
                                    }

                                    $estatus = "SELECT * FROM estatus WHERE id = ?";
                                    $sthEstatus = $db->prepare($estatus);
                                    $sthEstatus->bindParam(1, $rowsProductos[$i]["estatusId"], PDO::PARAM_INT);
                                    $sthEstatus->execute();
                                    $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                                    if ($rowsEstatus && $rowsEstatus[0]) {
                                        $rowsProductos[$i]["estatus"] = convertKeysToCamelCase($rowsEstatus[0]);
                                    } else {
                                        $rowsProductos[$i]["estatus"] = null;
                                    }

                                }
                            }
                        }
                        $productosCategoria[$k]["productos"] = $rowsProductos;
                        $k++;
                    }
                    $response["productosCategoria"] = $productosCategoria;
                    echoResponse(200, $response);

                } else {
                    $response["status"] = true;
                    $response["message"] = "No hay categorías registradas";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");
                    echoResponse(400, $response);
                }

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/proveedor-productos/categoria/:categoriaId', function ($categoriaId) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {
                //Articulos encontrados por categoria
                $query = "SELECT * FROM tipo_articulo WHERE categoria_id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $categoriaId, PDO::PARAM_INT);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);


                $all = array();
                foreach ($rows as $key => $tipo) {
                    //Buscar'productos por tipo de articulo
                    $queryProducto = "SELECT * FROM producto WHERE tipo_articulo_id = ?";
                    $sthProducto = $db->prepare($queryProducto);
                    $sthProducto->bindParam(1, $tipo["id"], PDO::PARAM_INT);
                    $sthProducto->execute();
                    $rowsProductos = $sthProducto->fetchAll(PDO::FETCH_ASSOC);

                    $response["tipoArticulo"] = $tipo;
                    $response["productos"] = desgloceProductoByTipoArticulo($tipo["id"],$db);

                    array_push($all, $response);
                }

                return echoResponse(400, $all);

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = [];
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/proveedores', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                //$uid = $app->request()->params('uid');

                $query = "SELECT * FROM proveedor";
                $sth = $db->prepare($query);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                if ($rows) {
                    /**Añadir usuario a cada proveedor */

                    for ($i = 0; $i < sizeof($rows); $i++) {
                        # code...
                        $user = "SELECT * FROM jhi_user WHERE id = ?";
                        $sthUser = $db->prepare($user);
                        $sthUser->bindParam(1, $rows[$i]["usuario_id"], PDO::PARAM_INT);
                        $sthUser->execute();
                        $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                        $rows[$i]["usuario"] = $rowsUser[0];

                        //transportista
                        if ($rows[$i]["transportista_id"]) {
                            $user = "SELECT * FROM transportista WHERE id = ?";
                            $sthUser = $db->prepare($user);
                            $sthUser->bindParam(1, $rows[$i]["transportista_id"], PDO::PARAM_INT);
                            $sthUser->execute();
                            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                            //$rows[0]["transportista"] = $rowsUser[0];

                            if ($rowsUser[0]) {
                                $rows[$i]["transportista"] = $rowsUser[0];

                                $user = "SELECT * FROM jhi_user WHERE id = ?";
                                $sthUser = $db->prepare($user);
                                $sthUser->bindParam(1, $rowsUser[0]["usuario_id"], PDO::PARAM_INT);
                                $sthUser->execute();
                                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                                if ($rowsUser && $rowsUser[0]) {
                                    $rows[$i]["transportista"]["usuario"] = $rowsUser[0];
                                } else {
                                    $rows[$i]["transportista"]["usuario"] = null;
                                }
                            } else {
                                $rows[$i]["transportista"] = null;
                            }
                        }

                        if ($rows[$i]["empresa_id"]) {
                            $user = "SELECT * FROM empresa WHERE id = ?";
                            $sthUser = $db->prepare($user);
                            $sthUser->bindParam(1, $rows[$i]["empresa_id"], PDO::PARAM_INT);
                            $sthUser->execute();
                            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                            if ($rowsUser && $rowsUser[0]) {
                                $rows[$i]["empresa"] = $rowsUser[0];
                            } else {
                                $rows[$i]["empresa"] = null;
                            }
                        } else {
                            $rows[$i]["empresa"] = null;
                        }

                    }

                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $rows);
                } else {
                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $response);
                }

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/proveedor-productos/proveedor/:id', function ($id) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                //$uid = $app->request()->params('uid');

                $query = "SELECT * FROM producto_proveedor WHERE proveedor_id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $id, PDO::PARAM_INT);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                if ($rows) {
                    /**Añadir usuario a cada proveedor */

                    for ($i = 0; $i < sizeof($rows); $i++) {
                        # code...
                        if ($rows[$i]["producto_id"]) {
                            $user = "SELECT * FROM producto WHERE id = ?";
                            $sthUser = $db->prepare($user);
                            $sthUser->bindParam(1, $rows[$i]["producto_id"], PDO::PARAM_INT);
                            $sthUser->execute();
                            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                            if ($rowsUser && $rowsUser[0]) {
                                $rows[$i]["producto"] = $rowsUser[0];

                                if ($rowsUser[0]["estatus_id"]) {
                                    $estatus = "SELECT * FROM estatus WHERE id = ?";
                                    $sthEstatus = $db->prepare($estatus);
                                    $sthEstatus->bindParam(1, $rowsUser[0]["estatus_id"], PDO::PARAM_INT);
                                    $sthEstatus->execute();
                                    $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                                    if ($rowsEstatus && $rowsEstatus[0]) {
                                        $rows[$i]["producto"]["estatus"] = $rowsEstatus[0];
                                    } else {
                                        $rows[$i]["producto"]["estatus"] = null;
                                    }
                                }

                                if ($rowsUser[0]["unidad_medida_id"]) {
                                    $unidad = "SELECT * FROM unidad_medida WHERE id = ?";
                                    $sthUnidad = $db->prepare($unidad);
                                    $sthUnidad->bindParam(1, $rowsUser[0]["unidad_medida_id"], PDO::PARAM_INT);
                                    $sthUnidad->execute();
                                    $rowsUnidad = $sthUnidad->fetchAll(PDO::FETCH_ASSOC);
                                    if ($rowsUnidad && $rowsUnidad[0]) {
                                        $rows[$i]["producto"]["unidadMedida"] = $rowsUnidad[0];
                                    } else {
                                        $rows[$i]["producto"]["unidadMedida"] = null;
                                    }
                                }

                                if ($rowsUser[0]["tipo_articulo_id"]) {
                                    $articulo = "SELECT * FROM tipo_articulo WHERE id = ?";
                                    $sthArticulo = $db->prepare($articulo);
                                    $sthArticulo->bindParam(1, $rowsUser[0]["tipo_articulo_id"], PDO::PARAM_INT);
                                    $sthArticulo->execute();
                                    $rowsArticulo = $sthArticulo->fetchAll(PDO::FETCH_ASSOC);
                                    if ($rowsArticulo && $rowsArticulo[0]) {
                                        $rows[$i]["producto"]["tipoArticulo"] = $rowsArticulo[0];

                                        if ($rowsArticulo[0]["categoria_id"]) {
                                            $categoria = "SELECT * FROM categoria WHERE id = ?";
                                            $sthCategoria = $db->prepare($categoria);
                                            $sthCategoria->bindParam(1, $rowsArticulo[0]["categoria_id"], PDO::PARAM_INT);
                                            $sthCategoria->execute();
                                            $rowsCategoria = $sthCategoria->fetchAll(PDO::FETCH_ASSOC);
                                            if ($rowsCategoria && $rowsCategoria[0]) {
                                                $rows[$i]["producto"]["tipoArticulo"]["categoria"] = $rowsCategoria[0];

                                                if ($rowsCategoria[0]["seccion_id"]) {
                                                    $seccion = "SELECT * FROM seccion WHERE id = ?";
                                                    $sthSeccion = $db->prepare($seccion);
                                                    $sthSeccion->bindParam(1, $rowsCategoria[0]["seccion_id"], PDO::PARAM_INT);
                                                    $sthSeccion->execute();
                                                    $rowsSeccion = $sthSeccion->fetchAll(PDO::FETCH_ASSOC);
                                                    if ($rowsSeccion && $rowsSeccion[0]) {
                                                        $rows[$i]["producto"]["tipoArticulo"]["categoria"]["seccion"] = $rowsSeccion[0];
                                                    } else {
                                                        $rows[$i]["producto"]["tipoArticulo"]["categoria"]["seccion"] = null;
                                                    }
                                                }
                                            } else {
                                                $rows[$i]["producto"]["tipoArticulo"]["unidadMedida"] = null;
                                            }
                                        }
                                    } else {
                                        $rows[$i]["producto"]["tipoArticulo"] = null;
                                    }
                                }
                            } else {
                                $rows[$i]["producto"] = null;
                            }

                            $estatus = "SELECT * FROM estatus WHERE id = ?";
                            $sthEstatus = $db->prepare($estatus);
                            $sthEstatus->bindParam(1, $rows[$i]["estatus_id"], PDO::PARAM_INT);
                            $sthEstatus->execute();
                            $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                            if ($rowsEstatus && $rowsEstatus[0]) {
                                $rows[$i]["estatus"] = $rowsEstatus[0];
                            } else {
                                $rows[$i]["estatus"] = null;
                            }

                        }

                        ///Se setea objeto de proveedor
                        $proveedor = "SELECT * FROM proveedor WHERE id = ?";
                        $sthProveedor = $db->prepare($proveedor);
                        $sthProveedor->bindParam(1, $rows[$i]["proveedor_id"], PDO::PARAM_INT);
                        $sthProveedor->execute();
                        $rowsProveedor = $sthProveedor->fetchAll(PDO::FETCH_ASSOC);

                        $rows[$i]["proveedor"] = $rowsProveedor[0];

                        if ($rowsProveedor && $rowsProveedor[0]) {
                            $user = "SELECT * FROM jhi_user WHERE id = ?";
                            $sthUser = $db->prepare($user);
                            $sthUser->bindParam(1, $rowsProveedor[0]["usuario_id"], PDO::PARAM_INT);
                            $sthUser->execute();
                            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                            $rows[$i]["proveedor"]["usuario"] = $rowsUser[0];

                            //transportista
                            if ($rows[$i]["proveedor"]["transportista_id"]) {
                                $user = "SELECT * FROM transportista WHERE id = ?";
                                $sthUser = $db->prepare($user);
                                $sthUser->bindParam(1, $rows[$i]["proveedor"]["transportista_id"], PDO::PARAM_INT);
                                $sthUser->execute();
                                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                                //$rows[0]["transportista"] = $rowsUser[0];

                                if ($rowsUser[0]) {
                                    $rows[$i]["proveedor"]["transportista"] = $rowsUser[0];

                                    $user = "SELECT * FROM jhi_user WHERE id = ?";
                                    $sthUser = $db->prepare($user);
                                    $sthUser->bindParam(1, $rowsUser[0]["usuario_id"], PDO::PARAM_INT);
                                    $sthUser->execute();
                                    $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                                    if ($rowsUser && $rowsUser[0]) {
                                        $rows[$i]["proveedor"]["transportista"]["usuario"] = $rowsUser[0];
                                    } else {
                                        $rows[$i]["proveedor"]["transportista"]["usuario"] = null;
                                    }
                                } else {
                                    $rows[$i]["proveedor"]["transportista"] = null;
                                }
                            }

                            if ($rows[$i]["proveedor"]["empresa_id"]) {
                                $user = "SELECT * FROM empresa WHERE id = ?";
                                $sthUser = $db->prepare($user);
                                $sthUser->bindParam(1, $rows[$i]["proveedor"]["empresa_id"], PDO::PARAM_INT);
                                $sthUser->execute();
                                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                                if ($rowsUser && $rowsUser[0]) {
                                    $rows[$i]["proveedor"]["empresa"] = $rowsUser[0];
                                } else {
                                    $rows[$i]["proveedor"]["empresa"] = null;
                                }
                            } else {
                                $rows[$i]["proveedor"]["empresa"] = null;
                            }
                        }

                    }

                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $rows);
                } else {
                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $response);
                }

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/proveedor-productos/:id', function ($id) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                $email = $app->request()->params('email');

                if (!$email) {
                    $queryProductos = "SELECT pp.* FROM producto_proveedor pp
                        INNER JOIN producto p
                        ON (p.id = pp.producto_id)
                        INNER JOIN tipo_articulo ta
                        ON (ta.id = p.tipo_articulo_id)
                        INNER JOIN categoria c
                        ON (c.id = ta.categoria_id)
                        WHERE p.id = ?";
                    $sthProductos = $db->prepare($queryProductos);
                    $sthProductos->bindParam(1, $id, PDO::PARAM_INT);
                    $sthProductos->execute();
                    $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);
                    //$rowsProductos = convertKeysToCamelCase($rowsProductos);
                    if ($rowsProductos) {
                        # code...
                        $rowsProductos[0] = convertKeysToCamelCase($rowsProductos[0]);
                        if ($rowsProductos[0]["productoId"]) {
                            $rowsProductos[0]["producto"] = desgloceProducto($rowsProductos[0]["productoId"]);
                        }

                        if ($rowsProductos[0]["proveedorId"]) {
                            $rowsProductos[0]["proveedor"] = desgloceProveedor($rowsProductos[0]["proveedorId"]);
                        }

                        $rowsProductos[0]["imagenes"] = imagenesProvedorProducto($rowsProductos[0]["id"]);
                    }
                    echoResponse(200, $rowsProductos[0]);
                } else {
                    $query = "SELECT * FROM producto_proveedor WHERE proveedor_id = ?";
                    $sth = $db->prepare($query);
                    $sth->bindParam(1, $id, PDO::PARAM_INT);
                    $sth->execute();
                    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                    $response["status"] = true;
                    $response["description"] = "SUCCESSFUL";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $rows;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $response);
                }

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/get-prueba', function () use ($app) {

            $response = array();
            $response["status"] = true;
            $response["description"] = "SUCCESSFUL-Local";
            $response["idTransaction"] = time();
            //$response["parameters"] = $decrypt->{'number'};
            $response["parameters"] = [];
            $response["parameters-2"] = null;
            $response["timeRequest"] = date("Y-m-d H:i:s");

            echoResponse(200, $response);
        });

        $app->post('/save-photos', function () use ($app) {
            $target_dir = $_SERVER["DOCUMENT_ROOT"] . "/api_luegoluego/luegoluego/";
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $body = $app->request->getBody();
            $data = json_decode($body, true);
            $rows = [];
            try {

                $photos = $data['photos'];
                $idMenu = $data['id_menu'];

                if (sizeof($photos) > 0) {
                    $sqlPhoto = "DELETE FROM photos WHERE id_menu = ?";
                    $sthUsuarioVerify = $db->prepare($sqlPhoto);
                    $sthUsuarioVerify->bindParam(1, $idMenu, PDO::PARAM_INT);
                    $sthUsuarioVerify->execute();
                    $imgs = [];

                    $inti = 0;
                    foreach ($photos as &$photo) {
                        $imgs[$inti]["img"] = $photo["img"];
                        $imgs[$inti]["url"] = $photo["url"];
                        $inti++;
                    }
                    $target_dir .= $idMenu . "/";
                    if (!file_exists($target_dir)) {
                        mkdir($target_dir, 0755, true);
                    }
                    /* crea fotos*/
                    $contents = [];
                    $sqlPhoto = "INSERT INTO photos (`id_menu`, `path`) VALUES ";
                    $armado = "http://" . $_SERVER["SERVER_NAME"] . "/api_menu_vid/menus/" . $idMenu . "/";
                    for ($i = 0; $i < sizeof($imgs); $i++) {
                        if ($imgs[$i]["url"] == true && $imgs[$i]["url"] == "true") {
                            $sqlPhoto .= "(" . $idMenu . ", '" . $imgs[$i]["img"] . "'),";
                        } else {
                            $data = explode(',', $imgs[$i]["img"]);
                            $contents[$i] = base64_decode($data[1]);
                            $output_file = $target_dir . $i . ".png";

                            $sqlPhoto .= "(" . $idMenu . ", '" . $armado . $i . ".png" . "'),";

                            $file = fopen($output_file, "wb");
                            fwrite($file, $contents[$i]);
                            fclose($file);
                        }
                    }
                    //$content = base64_decode($base64_string);

                    $sqlPhotoExecute = substr_replace($sqlPhoto, "", -1);
                    $sthPhoto = $db->prepare($sqlPhotoExecute);
                    $sthPhoto->execute();
                    $db->commit();
                    $sqlPhoto9 = "SELECT * FROM photos WHERE id_menu = ?";
                    $sthUsuarioVerify9 = $db->prepare($sqlPhoto9);
                    $sthUsuarioVerify9->bindParam(1, $idMenu, PDO::PARAM_INT);
                    $sthUsuarioVerify9->execute();
                    $rows = $sthUsuarioVerify9->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $sqlPhoto = "DELETE FROM photos WHERE id_menu = ?";
                    $sthUsuarioVerify = $db->prepare($sqlPhoto);
                    $sthUsuarioVerify->bindParam(1, $idMenu, PDO::PARAM_INT);
                    $sthUsuarioVerify->execute();
                    $db->commit();
                }

                $response["status"] = true;
                $response["description"] = "SUCCESSFUL";
                $response["idTransaction"] = time();
                $response["parameters"] = $rows;
                $response["parameters2"] = $sqlPhoto;
                $response["parameters3"] = $idMenu;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $response);

            } catch (Exception $e) {
                //$db->rollBack();
                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->post('/create-inventario', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);
            $step = 0;
            try {
                $sku = $data['sku'];
                $idPP = $data['idPP'];
                $nombre = $data['nombre'];
                $description = $data['description'];
                $precio = $data['precio'];
                $total = $data['total'];
                $minimo = $data['minimo'];
                $maximo = $data['maximo'];

                $articulo = $data['articulo'];
                $medida = $data['medida'];
                $estatus = $data['estatus'];

                $id = $data['id_proveedor'];

                $idPro = $data['idPro'];
                $idInv = $data['idInv'];

                $peso = $data['peso'];
                $largo = $data['largo'];
                $alto = $data['alto'];
                $ancho = $data['ancho'];

                $q1 = "SELECT * FROM producto_proveedor pp
                       INNER JOIN producto p
                       ON(p.id = pp.producto_id)
                       WHERE pp.proveedor_id = ? AND p.sku = ?";
                $sth1 = $db->prepare($q1);
                $sth1->bindParam(1, $id, PDO::PARAM_INT);
                $sth1->bindParam(2, $sku, PDO::PARAM_STR);
                $sth1->execute();
                $rows = $sth1->fetchAll(PDO::FETCH_ASSOC);

                if ($rows && $rows[0] && !$idPP) {
                    $response["status"] = false;
                    $response["description"] = "Ya tienes un producto con el sku " . $sku;
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(400, $response);
                } else {
                    if (!$idPP) {
                        //continua flujo
                        $q2 = "INSERT INTO producto
                               (`sku`, `nombre`, `descripcion`, `caracteristicas`, `precio_sin_iva`, `precio`, `fecha_alta`, `tipo_articulo_id`, `estatus_id`, `unidad_medida_id`)
                                VALUES (?, ?, ?, ?, ?, ?, now(), ?, ?, ?)";
                        $sth2 = $db->prepare($q2);
                        $sth2->bindParam(1, $sku, PDO::PARAM_STR);
                        $sth2->bindParam(2, $nombre, PDO::PARAM_STR);
                        $sth2->bindParam(3, $description, PDO::PARAM_STR);
                        $sth2->bindParam(4, $description, PDO::PARAM_STR);
                        $sth2->bindParam(5, $precio, PDO::PARAM_INT);
                        $sth2->bindParam(6, $precio, PDO::PARAM_INT);
                        $sth2->bindParam(7, $articulo, PDO::PARAM_INT);
                        $sth2->bindParam(8, $estatus, PDO::PARAM_INT);
                        $sth2->bindParam(9, $medida, PDO::PARAM_INT);
                        $sth2->execute();

                        //Último producto insertado
                        $idProducto = $db->lastInsertId();

                        //Inserción de producto proveedor
                        $q3 = "INSERT INTO producto_proveedor (producto_id, proveedor_id, estatus_id, precio_sin_iva, precio, fecha_alta)
                        VALUES (?,?,?,?,?, now())";
                        $sth3 = $db->prepare($q3);
                        $sth3->bindParam(1, $idProducto, PDO::PARAM_INT);
                        $sth3->bindParam(2, $id, PDO::PARAM_INT);
                        $sth3->bindParam(3, $estatus, PDO::PARAM_INT);
                        $sth3->bindParam(4, $precio, PDO::PARAM_INT);
                        $sth3->bindParam(5, $precio, PDO::PARAM_INT);
                        $sth3->execute();
                        //Último proveedor_producto insertado
                        $idProveedorProducto = $db->lastInsertId();
                        ///
                        //Inserción de inventario
                        $q4 = "INSERT INTO `abastos`.`inventario` (`producto_proveedor_id`, `total`, `inventario_minimo`, `inventario_maximo`, `peso`, `largo`, `alto`, `ancho`)
                               VALUES (?,?,?,?, ?, ? ,? ,?)";
                        $sth4 = $db->prepare($q4);
                        $sth4->bindParam(1, $idProveedorProducto, PDO::PARAM_INT);
                        $sth4->bindParam(2, $total, PDO::PARAM_INT);
                        $sth4->bindParam(3, $minimo, PDO::PARAM_INT);
                        $sth4->bindParam(4, $maximo, PDO::PARAM_INT);

                        $sth4->bindParam(5, $peso, PDO::PARAM_INT);
                        $sth4->bindParam(6, $largo, PDO::PARAM_INT);
                        $sth4->bindParam(7, $alto, PDO::PARAM_INT);
                        $sth4->bindParam(8, $ancho, PDO::PARAM_INT);

                        $sth4->execute();
                        //Último inventario insertado
                        $idInventario = $db->lastInsertId();
                        ///
                        $db->commit();
                        $response["status"] = false;
                        $response["description"] = "SUCCESSFUL";
                        $response["idTransaction"] = time();
                        $response["parameters"] = $idInventario;
                        $response["timeRequest"] = date("Y-m-d H:i:s");

                        echoResponse(200, $response);
                    } else {
                        //Actualizar datos de inventario, producto y proveedor
                        $q5 = "UPDATE producto
                               SET sku = ?, nombre = ?, descripcion = ?, caracteristicas = ?, precio_sin_iva = ?, precio = ?,
                               fecha_modificacion = now(), tipo_articulo_id = ?, estatus_id = ?, unidad_medida_id = ? WHERE id = ?";
                        $sth5 = $db->prepare($q5);
                        $sth5->bindParam(1, $sku, PDO::PARAM_STR);
                        $sth5->bindParam(2, $nombre, PDO::PARAM_STR);
                        $sth5->bindParam(3, $description, PDO::PARAM_STR);
                        $sth5->bindParam(4, $description, PDO::PARAM_STR);
                        $sth5->bindParam(5, $precio, PDO::PARAM_INT);
                        $sth5->bindParam(6, $precio, PDO::PARAM_INT);
                        $sth5->bindParam(7, $articulo, PDO::PARAM_INT);
                        $sth5->bindParam(8, $estatus, PDO::PARAM_INT);
                        $sth5->bindParam(9, $medida, PDO::PARAM_INT);
                        $sth5->bindParam(10, $idPro, PDO::PARAM_INT);
                        $sth5->execute();
                        ///
                        $q6 = "UPDATE producto_proveedor SET producto_id = ?, proveedor_id = ?, estatus_id = ?, precio_sin_iva = ?, precio = ? WHERE id = ?";
                        $sth6 = $db->prepare($q6);
                        $sth6->bindParam(1, $idPro, PDO::PARAM_INT);
                        $sth6->bindParam(2, $id, PDO::PARAM_INT);
                        $sth6->bindParam(3, $estatus, PDO::PARAM_INT);
                        $sth6->bindParam(4, $precio, PDO::PARAM_INT);
                        $sth6->bindParam(5, $precio, PDO::PARAM_INT);
                        $sth6->bindParam(6, $idPP, PDO::PARAM_INT);
                        $sth6->execute();
                        ///////
                        $q7 = "UPDATE inventario SET producto_proveedor_id = ?, total = ?, inventario_minimo = ?, inventario_maximo = ?,
                        peso = ?, largo = ?, alto = ?, ancho = ? WHERE id = ?";
                        $sth7 = $db->prepare($q7);
                        $sth7->bindParam(1, $idPP, PDO::PARAM_INT);
                        $sth7->bindParam(2, $total, PDO::PARAM_INT);
                        $sth7->bindParam(3, $minimo, PDO::PARAM_INT);
                        $sth7->bindParam(4, $maximo, PDO::PARAM_INT);

                        $sth4->bindParam(5, $peso, PDO::PARAM_INT);
                        $sth4->bindParam(6, $largo, PDO::PARAM_INT);
                        $sth4->bindParam(7, $alto, PDO::PARAM_INT);
                        $sth4->bindParam(8, $ancho, PDO::PARAM_INT);

                        $sth7->bindParam(9, $idInv, PDO::PARAM_INT);
                        $sth7->execute();

                        $db->commit();
                        $response["status"] = false;
                        $response["description"] = "SUCCESSFUL";
                        $response["idTransaction"] = time();
                        $response["parameters"] = [];
                        $response["timeRequest"] = date("Y-m-d H:i:s");

                        echoResponse(200, $response);
                    }
                }

            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->delete('/delete-inventario', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            $idPro = $app->request()->params('idPro'); //$data['idPro'];
            $idInv = $app->request()->params('idInv'); //$data['idInv'];
            $idPP = $app->request()->params('idPP'); //$data['idPP'];

            //$app->request()->params('id_suite');
            $db->beginTransaction();
            try {
                //inventario, producto_proveedor, producto
                $s1 = "DELETE FROM inventario WHERE id = ?";
                $s2 = "DELETE FROM producto_proveedor WHERE id = ?";
                $s3 = "DELETE FROM producto WHERE id = ?";

                $sth1 = $db->prepare($s1);
                $sth1->bindParam(1, $idInv, PDO::PARAM_INT);
                $sth1->execute();

                $sth2 = $db->prepare($s2);
                $sth2->bindParam(1, $idPP, PDO::PARAM_INT);
                $sth2->execute();

                $sth3 = $db->prepare($s3);
                $sth3->bindParam(1, $idPro, PDO::PARAM_INT);
                $sth3->execute();

                $db->commit();

                $response["status"] = false;
                $response["description"] = "SUCCESSFUL";
                $response["idTransaction"] = time();
                $response["parameters"] = [];
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $response);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->post('/asignacion', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $sql = $data['sql'];
                $idProveedor = $data['id_proveedor'];

                $q1 = "DELETE FROM proveedor_transportista
                       WHERE id_proveedor = ?";
                $sth1 = $db->prepare($q1);
                $sth1->bindParam(1, $idProveedor, PDO::PARAM_INT);
                $sth1->execute();

                if ($sql) {
                    $sth2 = $db->prepare($sql);
                    $sth2->execute();
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->put('/proveedor/pedido-proveedores', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $pedidoProveedorId = $data['pedidoProveedorId'];
                $estatusId = $data['estatusId'];
                $email = $data['email'];
                $transportistaId = $data['transportistaId'];
                $fcmData = $data['fcmData'];

                $qUser = "SELECT * FROM jhi_user
                       WHERE email = ?";
                $sthUser = $db->prepare($qUser);
                $sthUser->bindParam(1, $email, PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                $userId = $rowsUser[0]["id"];

                $q1 = "SELECT * FROM pedido_proveedor
                       WHERE id = ?";
                $sth1 = $db->prepare($q1);
                $sth1->bindParam(1, $pedidoProveedorId, PDO::PARAM_INT);
                $sth1->execute();
                $rows = $sth1->fetchAll(PDO::FETCH_ASSOC);
                if ($rows && $rows[0]) {
                    $sqlPedidoDetalle = "UPDATE pedido_detalle SET estatus_id = ? WHERE pedido_proveedor_id = ?";
                    $sthPedidoDetalle = $db->prepare($sqlPedidoDetalle);
                    $sthPedidoDetalle->bindParam(1, $estatusId, PDO::PARAM_INT);
                    $sthPedidoDetalle->bindParam(2, $pedidoProveedorId, PDO::PARAM_INT);
                    $sthPedidoDetalle->execute();

                    $sqlPedidoProveedor = "UPDATE pedido_proveedor SET estatus_id = ?, usuario_modificacion_id = ?, transportista_id = ?, fecha_modificacion = now()
                                           WHERE id = ?";
                    $sthPedidoProveedor = $db->prepare($sqlPedidoProveedor);
                    $sthPedidoProveedor->bindParam(1, $estatusId, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(2, $userId, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(3, $transportistaId, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(4, $rows[0]["id"], PDO::PARAM_INT);
                    $sthPedidoProveedor->execute();

                    $sqlPedidoProveedorHistorico = "INSERT INTO pedido_proveedor_historico (pedido_proveedor_id, estatus_id, usuario_id, fecha)
                                           VALUES (?,?,?,now())";
                    $sthPedidoProveedorHistorico = $db->prepare($sqlPedidoProveedorHistorico);
                    $sthPedidoProveedorHistorico->bindParam(1, $rows[0]["id"], PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->bindParam(2, $estatusId, PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->bindParam(3, $userId, PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->execute();

                    //pedido_id
                    $qPedidoU = "UPDATE pedido SET estatus_id = ?
                        WHERE id = ?";
                    $sthPedidoU = $db->prepare($qPedidoU);
                    $sthPedidoU->bindParam(1, $estatusId, PDO::PARAM_INT);
                    $sthPedidoU->bindParam(2, $rows[0]["pedido_id"], PDO::PARAM_INT);
                    $sthPedidoU->execute();
                    $db->commit();

                    $fcm = new FCMNotification();
                    $return = $fcm->sendDataJSON($fcmData);

                    $response["status"] = true;
                    $response["description"] = "El pedido se ha enviado al transportista";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(200, $response);
                } else {
                    $response["status"] = false;
                    $response["description"] = "Pedido no encontrado";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(400, $response);
                }
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });
        //"data:image/png;base64,'.base64_encode($blob_data).'"

        $app->get('/adjuntos/download/:id', function ($id) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                //$uid = $app->request()->params('uid');

                $query = "SELECT file FROM adjunto WHERE id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $id, PDO::PARAM_INT);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                if ($rows && $rows[0]) {
                    //$arc = file_put_contents('filename.jpg', );

                    // Obtain the original content (usually binary data)
                    $b64 = "data:image/png;base64," . base64_encode($rows[0]["file"]);

                    echo ($rows[0]["file"]);
                } else {
                    echoResponse(400, null);
                }
            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->post('/photos-producto', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $files = $data['files'];
                $idProductoProveedor = $data['id_producto_proveedor'];

                $q1 = "DELETE FROM producto_imagen WHERE producto_proveedor_id = ?";
                $sth1 = $db->prepare($q1);
                $sth1->bindParam(1, $idProductoProveedor, PDO::PARAM_INT);
                $sth1->execute();

                if (sizeof($files) > 0) {
                    foreach ($files as &$file) {
                        $separado = explode("base64,", $file["base64"])[1];
                        $blobData = base64_decode($separado);

                        $sqlBlob = "INSERT INTO adjunto (content_type, size, file_name, file_content_type, file)
                        VALUES (?,?,?,?,?)";
                        $sthBlob = $db->prepare($sqlBlob);
                        $sthBlob->bindParam(1, $file["content_type"], PDO::PARAM_STR);
                        $sthBlob->bindParam(2, $file["size"], PDO::PARAM_STR);
                        $sthBlob->bindParam(3, $file["file_name"], PDO::PARAM_STR);
                        $sthBlob->bindParam(4, $file["file_content_type"], PDO::PARAM_STR);

                        $sthBlob->bindParam(5, $blobData, PDO::PARAM_LOB);
                        $sthBlob->execute();

                        $idAdjuntoTmp = $db->lastInsertId();

                        $sqlProductoImagen = "INSERT INTO producto_imagen (producto_proveedor_id, adjunto_id, fecha_alta) VALUES (?,?,now())";
                        $sthImg = $db->prepare($sqlProductoImagen);
                        $sthImg->bindParam(1, $idProductoProveedor, PDO::PARAM_INT);
                        $sthImg->bindParam(2, $idAdjuntoTmp, PDO::PARAM_INT);
                        $sthImg->execute();
                    }
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/carrito-compras-proveedor', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                $email = $app->request()->params('email');

                $query = "SELECT * FROM carrito_compra WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
                $check = 0;
                if ($rows && $rows[0]) {

                    $objRetorno = array();
                    $objRetorno["listCarritoProveedores"] = [];
                    $objRetorno["total"] = 0; //
                    $objRetorno["totalProductos"] = 0; //
                    $objRetorno["totalComisionTransporte"] = 0;
                    $objRetorno["comisionStripe"] = 0;
                    $objRetorno["totalSinComisionStripe"] = 0;

                    $mapProveedores = array();
                    //Primer recorrido de lo obtenido en carrito
                    foreach ($rows as $key => $carrito) {
                        # code...
                        $carrito = convertKeysToCamelCase($carrito);
                        $cantidad = $carrito["cantidad"];
                        $precio = $carrito["precio"];

                        $multiplicacion = $cantidad * $precio;

                        //Buscamos el objeto proveedor

                        $productoProveedor = desgloceProductoProveedor($carrito["productoProveedorId"]);
                        $carrito["productoProveedor"] = $productoProveedor;

                        $proveedor = desgloceProveedorByProductoProveedor($carrito["productoProveedorId"]);

                        if (array_key_exists($productoProveedor["proveedorId"], $mapProveedores)) {

                            $mapProveedores[$productoProveedor["proveedorId"]]["total"] = $mapProveedores[$productoProveedor["proveedorId"]]["total"] + floatval($multiplicacion);
                            $mapProveedores[$productoProveedor["proveedorId"]]["totalProductos"] = $mapProveedores[$productoProveedor["proveedorId"]]["totalProductos"] + floatval($multiplicacion);

                            array_push($mapProveedores[$productoProveedor["proveedorId"]]["listCarrito"], $carrito);
                            //array_push($mapProveedores[$productoProveedor["proveedorId"]], $tmp);
                        } else {
                            $mapProveedores[$productoProveedor["proveedorId"]] = array();
                            $tmp = array();
                            $tmp["listCarrito"] = [];
                            $tmp["comisionTransporte"] = 0;
                            $tmp["proveedor"] = desgloceProveedor($proveedor["id"]);
                            $tmp["tiempoEntrega"] = 0;
                            $tmp["total"] = floatval($multiplicacion);
                            $tmp["totalProductos"] = floatval($multiplicacion);
                            array_push($tmp["listCarrito"], $carrito);

                            $mapProveedores[$productoProveedor["proveedorId"]] = $tmp;
                        }

                    }

                    //Objeto final
                    foreach ($mapProveedores as $key => $map) {
                        # code...
                        array_push($objRetorno["listCarritoProveedores"], $map);

                        $objRetorno["totalProductos"] = floatval($objRetorno["totalProductos"]) + floatval($map["totalProductos"]);
                        $objRetorno["totalComisionTransporte"] = floatval($objRetorno["totalComisionTransporte"]) + floatval($map["comisionTransporte"]);
                        $objRetorno["totalSinComisionStripe"] = floatval($objRetorno["totalSinComisionStripe"]) + floatval($map["totalProductos"]) + floatval($map["comisionTransporte"]);
                        $objRetorno["comisionStripe"] = 0.036 * floatval($objRetorno["totalSinComisionStripe"]) + 3;
                        $objRetorno["total"] = floatval($objRetorno["totalSinComisionStripe"]) + floatval($objRetorno["comisionStripe"]);
                    }
                    //echoResponse(400, null);
                    echoResponse(200, $objRetorno);
                } else {
                    $response["status"] = false;
                    $response["message"] = "No tienes artículos en tu carrito de compra";
                    $response["idTransaction"] = time();
                    $response["parameters"] = [];
                    $response["parameters2"] = [];
                    $response["timeRequest"] = date("Y-m-d H:i:s");
                    echoResponse(200, []);
                }
            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->put('/carrito-compras', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $email = $data['email'];
                $cantidad = $data['cantidad'];
                $precio = $data['precio'];
                $productoProveedorId = $data['productoProveedorId'];

                //$email = $app->request()->params('email');
                /*
                cantidad: 4
                precio: 24.8
                productoProveedorId: 4
                 */
                $query = "UPDATE carrito_compra SET cantidad = ? WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)
                AND producto_proveedor_id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $cantidad, PDO::PARAM_STR);
                $sth->bindParam(2, $email, PDO::PARAM_STR);
                $sth->bindParam(3, $productoProveedorId, PDO::PARAM_STR);
                $sth->execute();
                $db->commit();

                $query = "SELECT * FROM carrito_compra WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)
                AND producto_proveedor_id = ?";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->bindParam(2, $productoProveedorId, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
                $rows[0] = convertKeysToCamelCase($rows[0]);
                $productoProveedor = desgloceProductoProveedor($carrito["productoProveedorId"]);
                $rows[0]["productoProveedor"] = $productoProveedor;
                echoResponse(200, $rows[0]);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->post('/carrito-historicos', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $email = $data['email'];
                $nombre = $data['nombre'];

                //$email = $app->request()->params('email');
                /*
                cantidad: 4
                precio: 24.8
                productoProveedorId: 4
                 */

                $query = "SELECT * FROM carrito_compra WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
                //$rows[0] = convertKeysToCamelCase($rows[0]);
                //Primero un insert en carrito historico

                $queryInsert = "INSERT INTO carrito_historico (nombre, cliente_id, fecha_alta) VALUES (?, ?, now())";
                $sthInsert = $db->prepare($queryInsert);
                $sthInsert->bindParam(1, $nombre, PDO::PARAM_STR);
                $sthInsert->bindParam(2, $rows[0]["client_id"], PDO::PARAM_INT);
                $sthInsert->execute();
                $idCarritoHistorico = $db->lastInsertId();

                for ($i = 0; $i < sizeof($rows); $i++) {
                    # code...
                    $rows[$i] = convertKeysToCamelCase($rows[$i]);
                    $queryInsertHistorico = "INSERT INTO carrito_historico_detalle (cantidad, precio, producto_proveedor_id, carrito_historico_id)
                    VALUES (?,?,?,?)";
                    $sthInsertHistorico = $db->prepare($queryInsertHistorico);
                    $sthInsertHistorico->bindParam(1, $rows[$i]["cantidad"], PDO::PARAM_INT);
                    $sthInsertHistorico->bindParam(2, $rows[$i]["precio"], PDO::PARAM_INT);
                    $sthInsertHistorico->bindParam(3, $rows[$i]["productoProveedorId"], PDO::PARAM_INT);
                    $sthInsertHistorico->bindParam(4, $idCarritoHistorico, PDO::PARAM_INT);
                    $sthInsertHistorico->execute();
                }
                $db->commit();

                echoResponse(200, $rows[0]);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/tipo-direcciones', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {
                $query = "SELECT * FROM tipo_direccion";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                echoResponse(200, $rows);
            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/usuario-direcciones', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                $email = $app->request()->params('email');

                $query = "SELECT * FROM usuario_direccion WHERE usuario_id = (SELECT id FROM jhi_user WHERE email = ?)";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
                for ($i = 0; $i < sizeof($rows); $i++) {
                    # code...
                    $rows[$i] = convertKeysToCamelCase($rows[$i]);

                    if ($rows[$i]["direccionId"]) {
                        $queryDireccion = "SELECT * FROM direccion WHERE id = ?";
                        $sthDireccion = $db->prepare($queryDireccion);
                        $sthDireccion->bindParam(1, $rows[$i]["direccionId"], PDO::PARAM_STR);
                        $sthDireccion->execute();
                        $rowsDireccion = $sth->fetchAll(PDO::FETCH_ASSOC);

                        $rows[$i]["direccion"] = convertKeysToCamelCase($rowsDireccion[0]);
                    }
                }

                echoResponse(200, $rows);
            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->post('/pedidos', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);
            $rowsUser = null;
            $p = [];
            $debugger = 0;
            $debugger2 = [];
            $proves = [];
            try {
                $correoContacto = $data['correoContacto'];
                $nombreContacto = $data['nombreContacto'];
                $direccionContacto = $data['direccionContacto'];
                $picking = $data['picking'];
                $telefonoContacto = $data['telefonoContacto'];
                $productos = $data['productos'];
                $email = $data['email'];
                $idEstatus = 7; //Estatus solicitado

                $qUser = "SELECT * FROM jhi_user
                       WHERE email = ?";
                $sthUser = $db->prepare($qUser);
                $sthUser->bindParam(1, $email, PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                $clienteId = $rowsUser[0]["id"];

                $qParams = "SELECT * FROM parametros_aplicacion
                       WHERE clave = ?";
                $s = "maximos_kms";
                $sthParams = $db->prepare($qParams);
                $sthParams->bindParam(1, $s, PDO::PARAM_STR);
                $sthParams->execute();
                $rowsParams = $sthParams->fetchAll(PDO::FETCH_ASSOC);

                $limiteParams = $rowsParams[0]["descripcion"];

                $pedidoDTO = array();
                $pedidoDTO["estatusId"] = $idEstatus;
                $pedidoDTO["clienteId"] = $clienteId;
                $pedidoDTO["usuarioAltaId"] = $clienteId;
                $pedidoDTO["total"] = 0;
                $pedidoDTO["totalSinIva"] = 0;
                $pedidoDTO["comisionTransportista"] = 0;
                $pedidoDTO["comisionPreparador"] = 0;
                $pedidoDTO["nombreContacto"] = $nombreContacto;
                $pedidoDTO["telefonoContacto"] = $telefonoContacto;
                $pedidoDTO["correoContacto"] = $correoContacto;
                $pedidoDTO["totalSinComision"] = 0;
                $pedidoDTO["pedidoProveedores"] = [];
                $pedidoDTO["cliente"] = $rowsUser[0];
                //Logica de pedido
                $mapPprov = array();
                $mapProveedores = array();
                $sumaProveedor = array();
                $sumaSinIvaProveedor = array();

                //Totales
                $total = 0;
                $totalProductos = 0;
                $comisionTransportista = 0;
                $comisionStripe = 0;
                $totalSinIva = 0;
                $totalSinComision = 0;
                $debugger = 11;
                /////
                for ($i = 0; $i < sizeof($productos); $i++) {
                    # code...
                    $producto = $productos[$i];

                    $prodProdDTO = desgloceProductoProveedor(intval($producto["productoProveedorId"]));

                    $proveedorDTO = desgloceProveedorByProductoProveedor($producto["productoProveedorId"]);
                    //array_push($p, $proveedorDTO);
                    if (!array_key_exists($prodProdDTO["proveedorId"], $mapProveedores)) {
                        $mapProveedores[$prodProdDTO["proveedorId"]] = array();
                        $tmp = array();
                        $tmp["estatusId"] = $idEstatus;
                        $tmp["total"] = 0;
                        $tmp["totalSinIva"] = 0;
                        $tmp["comisionTransportista"] = 0;
                        $tmp["comisionPreparador"] = 0;
                        $tmp["usuarioAltaId"] = $clienteId;
                        $tmp["usuarioModificacionId"] = $clienteId;

                        $tmp["proveedorId"] = $proveedorDTO["id"];
                        $tmp["proveedor"] = $proveedorDTO;
                        $tmp["transportistaId"] = $proveedorDTO["transportistaId"];
                        $tmp["pedidoDetalles"] = [];

                        $tmp["identified"] = $prodProdDTO["proveedorId"];

                        array_push($pedidoDTO["pedidoProveedores"], $tmp);

                        $mapProveedores[$prodProdDTO["proveedorId"]] = $tmp;
                        $mapPprov[$prodProdDTO["proveedorId"]] = $tmp;
                        $mapPprov[$prodProdDTO["proveedorId"]]["pedidoProveedores"] = [];
                        $mapPprov[$prodProdDTO["proveedorId"]]["pedidoDetalles"] = [];
                        //array_push($pedidoDTO["pedidoProveedores"], $tmp);
                        //array_push($mapPprov[$prodProdDTO["proveedorId"]]["pedidoProveedores"], $tmp);
                        $sumaProveedor[$prodProdDTO["proveedorId"]] = 0;
                        //$sumaProveedor[$prodProdDTO["proveedorId"]] = floatval($sumaProveedor[$prodProdDTO["proveedorId"]]) + floatval($pedidoDetalleDTO["total"]);

                        $sumaSinIvaProveedor[$prodProdDTO["proveedorId"]] = 0;
                        //$sumaSinIvaProveedor[$prodProdDTO["proveedorId"]] = floatval($sumaSinIvaProveedor[$prodProdDTO["proveedorId"]]) + floatval($pedidoDetalleDTO["totalSinIva"]);
                    }
                    ///////
                    $pedidoDetalleDTO = array();
                    $pedidoDetalleDTO["cantidad"] = $producto["cantidad"];
                    $pedidoDetalleDTO["estatusId"] = $idEstatus;
                    $pedidoDetalleDTO["productoProveedorId"] = $producto["productoProveedorId"];

                    $pedidoDetalleDTO["precio"] = $prodProdDTO["precio"];
                    $pedidoDetalleDTO["precioSinIva"] = $prodProdDTO["precioSinIva"];

                    $pedidoDetalleDTO["total"] = floatval($prodProdDTO["precio"]) * floatval($producto["cantidad"]);
                    $pedidoDetalleDTO["totalSinIva"] = floatval($prodProdDTO["precioSinIva"]) * floatval($producto["cantidad"]);
                    ///////

                    /* $mapProveedores[$prodProdDTO["proveedorId"]] = $tmp;
                    $mapPprov[$prodProdDTO["proveedorId"]] = $tmp;
                     */
                    $sumaProveedor[$prodProdDTO["proveedorId"]] = floatval($sumaProveedor[$prodProdDTO["proveedorId"]]) + floatval($pedidoDetalleDTO["total"]);
                    $sumaSinIvaProveedor[$prodProdDTO["proveedorId"]] = floatval($sumaSinIvaProveedor[$prodProdDTO["proveedorId"]]) + floatval($pedidoDetalleDTO["totalSinIva"]);

                    $totalSinComision = floatval($totalSinComision) + floatval($pedidoDetalleDTO["total"]);
                    $totalProductos = floatval($totalProductos) + floatval($pedidoDetalleDTO["total"]);
                    $totalSinIva = floatval($totalSinIva) + floatval($pedidoDetalleDTO["totalSinIva"]);

                    array_push($mapPprov[$prodProdDTO["proveedorId"]]["pedidoDetalles"], $pedidoDetalleDTO);

                    //array_push($debugger2, $pedidoDetalleDTO);
                    //$debugger2 = $mapPprov[$prodProdDTO["proveedorId"]]["pedidoDetalles"];
                }

                if ($direccionContacto != null) {
                    $direccion = $direccionContacto;
                    if ($direccion["id"] != null && $direccion["id"] > 0) {
                        $pedidoDTO["direccionContactoId"] = $direccion["id"];
                    } else {
                        $queryInsert = "INSERT INTO direccion (direccion, codigo_postal, latitud, longitud, usuario_alta_id, fecha_alta) VALUES (?,?,?,?,?,now())";
                        $sthInsert = $db->prepare($queryInsert);
                        $sthInsert->bindParam(1, $direccion["direccion"], PDO::PARAM_STR);
                        $sthInsert->bindParam(2, $direccion["codigoPostal"], PDO::PARAM_STR);
                        $sthInsert->bindParam(3, $direccion["latitud"], PDO::PARAM_STR);
                        $sthInsert->bindParam(4, $direccion["longitud"], PDO::PARAM_STR);
                        $sthInsert->bindParam(5, $clienteId, PDO::PARAM_STR);
                        $sthInsert->execute();
                        $idDireccion = $db->lastInsertId();
                        $pedidoDTO["direccionContactoId"] = $idDireccion;
                    }
                }

                $debugger = 22;
                ///Se crea pedido

                $queryPedido = "INSERT INTO pedido (estatus_id, cliente_id, total, total_sin_comision, total_sin_iva, comision_transportista, comision_preparador,
                usuario_alta_id, nombre_contacto, telefono_contacto, correo_contacto, direccion_contacto_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
                $sthPedido = $db->prepare($queryPedido);
                $sthPedido->bindParam(1, $pedidoDTO["estatusId"], PDO::PARAM_INT);
                $sthPedido->bindParam(2, $pedidoDTO["clienteId"], PDO::PARAM_INT);
                $sthPedido->bindParam(3, $pedidoDTO["total"], PDO::PARAM_INT);
                $sthPedido->bindParam(4, $pedidoDTO["totalSinComision"], PDO::PARAM_INT);
                $sthPedido->bindParam(5, $pedidoDTO["totalSinIva"], PDO::PARAM_INT);
                $sthPedido->bindParam(6, $pedidoDTO["comisionTransportista"], PDO::PARAM_INT);
                $sthPedido->bindParam(7, $pedidoDTO["comisionPreparador"], PDO::PARAM_INT);
                $sthPedido->bindParam(8, $pedidoDTO["usuarioAltaId"], PDO::PARAM_INT);
                $sthPedido->bindParam(9, $pedidoDTO["nombreContacto"], PDO::PARAM_STR);
                $sthPedido->bindParam(10, $pedidoDTO["telefonoContacto"], PDO::PARAM_STR);
                $sthPedido->bindParam(11, $pedidoDTO["correoContacto"], PDO::PARAM_STR);
                $sthPedido->bindParam(12, $idDireccion, PDO::PARAM_INT);
                $sthPedido->execute();
                $idPedido = $db->lastInsertId();
                $debugger = 33;
                for ($j = 0; $j < sizeof($pedidoDTO["pedidoProveedores"]); $j++) {
                    $pedProv = $pedidoDTO["pedidoProveedores"][$j];
                    $debugger2 = $mapPprov[$pedProv["identified"]]["pedidoDetalles"];
                    $pedidosDetallesFor = $mapPprov[$pedProv["identified"]]["pedidoDetalles"];
                    $pedProv["pedidoId"] = $idPedido;
                    $pedProv["total"] = floatval($pedProv["total"]) + floatval($sumaProveedor[$pedProv["proveedorId"]]);
                    $pedProv["totalSinIva"] = floatval($pedProv["total"]) + floatval($sumaSinIvaProveedor[$pedProv["proveedorId"]]);

                    $pedProv["token"] = generateRandomToken(5);

                    // Si es picking no se calcula comisión de transporte
                    // El usuario recoge el pedido en dirección de proveedor
                    if (!$picking || $picking == "false" || $picking == 0) {
                        $provTmp = $mapProveedores[$pedProv["proveedorId"]]["proveedor"];
                        $provTmp["direccion"] = desgloceDireccion($provTmp["direccionId"]);
                        //lat,lat,long,long
                        /*

                         */
                        array_push($proves, $pedProv);
                        $lat1 = $direccionContacto["latitud"];
                        $lat2 = $provTmp["direccion"]["latitud"];
                        $long1 = $direccionContacto["longitud"];
                        $long2 = $provTmp["direccion"]["longitud"];
                        $distanciaKm = getDrivingDistance($lat1, $lat2, $long1, $long2);
                        $distanciaKm = explode(" ", $distanciaKm["distance"]);
                        $distanciaKm = $distanciaKm[0];
                        $debugger = 2;
                        //Cálculo de tarifa
                        $tarifa = 0;
                        if ($distanciaKm != null && $distanciaKm != "null") {

                            //Buscar transportistas asignados
                            $queryProveedorTransportista = "SELECT * FROM proveedor_transportista";
                            $sthProveedorTransportista = $db->prepare($queryProveedorTransportista);
                            $sthProveedorTransportista->execute();
                            $rowsProveedorTransportista = $sthProveedorTransportista->fetchAll(PDO::FETCH_ASSOC);
                            /////////////////////////////////
                            $tarifa = 0;
                            $transportistasRegla = array();

                            if ($rowsProveedorTransportista) {
                                //Lógica nueva de tomar el costo mayor por distancia
                                //$provTmp["id"]

                                $queryMax = "SELECT tt.*
                                FROM proveedor_transportista pt
                                INNER JOIN transportista_tarifa tt
                                ON (tt.transportista_id = pt.id_transportista)
                                WHERE pt.id_proveedor = ? AND ? BETWEEN rango_minimo AND rango_maximo order by precio desc";
                                $sthMax = $db->prepare($queryMax);
                                $sthMax->bindParam(1, $provTmp["id"], PDO::PARAM_INT);
                                $sthMax->bindParam(2, $distanciaKm, PDO::PARAM_INT);
                                $sthMax->execute();
                                $rowsMax = $sthMax->fetchAll(PDO::FETCH_ASSOC);

                                if ($rowsMax && $rowsMax[0]) {
                                    $tarifa = $rowsMax[0]["precio"];
                                }

                            } else {
                                //Asi es como estaba evaluando al transportista default
                                $queryMax = "SELECT *
                                FROM transportista_tarifa
                                WHERE ? BETWEEN rango_minimo AND rango_maximo
                                order by precio desc";
                                $sthMax = $db->prepare($queryMax);
                                $sthMax->bindParam(1, $distanciaKm, PDO::PARAM_INT);
                                $sthMax->execute();
                                $rowsMax = $sthMax->fetchAll(PDO::FETCH_ASSOC);

                                if ($rowsMax && $rowsMax[0]) {
                                    $tarifa = $rowsMax[0]["precio"];
                                }
                            }

                        }
                    }
                    $debugger = 44;
                    $pedProv["comisionTransportista"] = $tarifa;
                    $pedProv["distanciaKM"] = $distanciaKm;
                    $comisionTransportista = floatval($comisionTransportista) + floatval($tarifa);

                    //Insert de predido proveedor
                    /*
                    INSERT INTO pedido_proveedor (pedido_id, proveedor_id, estatus_id, transportista_id, total, total_sin_iva, comision_preparador,
                    comision_transportista, fecha_alta, usuario_alta_id, fecha_modificacion, usuario_modificacion_id, token) VALUES (?,?,?,?,?,?,?,?, now(), ?, now(), ?,?)
                     */
                    $queryPedidoProveedor = "INSERT INTO pedido_proveedor (pedido_id, proveedor_id, estatus_id, transportista_id, total, total_sin_iva,
                    fecha_alta, usuario_alta_id, fecha_modificacion, usuario_modificacion_id, token, comision_transportista, distancia_entrega_km) VALUES (?,?,?,?,?,?, now(), ?, now(), ?,?,?,?)";
                    $sthPedidoProveedor = $db->prepare($queryPedidoProveedor);
                    $sthPedidoProveedor->bindParam(1, $pedProv["pedidoId"], PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(2, $pedProv["proveedor"]["id"], PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(3, $idEstatus, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(4, $pedProv["proveedor"]["transportistaId"], PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(5, $pedProv["total"], PDO::PARAM_STR);
                    $sthPedidoProveedor->bindParam(6, $pedProv["totalSinIva"], PDO::PARAM_STR);
                    $sthPedidoProveedor->bindParam(7, $clienteId, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(8, $clienteId, PDO::PARAM_INT);
                    $sthPedidoProveedor->bindParam(9, $pedProv["token"], PDO::PARAM_STR);
                    $sthPedidoProveedor->bindParam(10, $pedProv["comisionTransportista"], PDO::PARAM_STR);
                    $sthPedidoProveedor->bindParam(11, $pedProv["distanciaKM"], PDO::PARAM_STR);

                    $sthPedidoProveedor->execute();
                    $idPedidoProveedor = $db->lastInsertId();
                    $debugger = 4;
                    $folioPedidoProveedor = "PV" . str_pad($idPedidoProveedor, 10, "0", STR_PAD_LEFT);

                    $queryUpdatePedidoProveedor = "UPDATE pedido_proveedor SET folio = ? WHERE id = ?";
                    $sthUpdatePedidoProveedor = $db->prepare($queryUpdatePedidoProveedor);
                    $sthUpdatePedidoProveedor->bindParam(1, $folioPedidoProveedor, PDO::PARAM_STR);
                    $sthUpdatePedidoProveedor->bindParam(2, $idPedidoProveedor, PDO::PARAM_STR);
                    $sthUpdatePedidoProveedor->execute();
                    $debugger = 5;
                    $sqlPedidoProveedorHistorico = "INSERT INTO pedido_proveedor_historico (pedido_proveedor_id, estatus_id, usuario_id, fecha)
                                           VALUES (?,?,?,now())";
                    $sthPedidoProveedorHistorico = $db->prepare($sqlPedidoProveedorHistorico);
                    $sthPedidoProveedorHistorico->bindParam(1, $idPedidoProveedor, PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->bindParam(2, $idEstatus, PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->bindParam(3, $clienteId, PDO::PARAM_INT);
                    $sthPedidoProveedorHistorico->execute();
                    $debugger = 55;
                    for ($k = 0; $k < sizeof($pedidosDetallesFor); $k++) {
                        /*
                        $pedidoDetalleDTO["cantidad"] = $producto["cantidad"];
                        $pedidoDetalleDTO["estatusId"] = $idEstatus;
                        $pedidoDetalleDTO["productoProveedorId"] = $producto["productoProveedorId"];

                        $pedidoDetalleDTO["precio"] = $prodProdDTO["precio"];
                        $pedidoDetalleDTO["precioSinIva"] = $prodProdDTO["precioSinIva"];

                        $pedidoDetalleDTO["total"] = floatval($prodProdDTO["precio"]) * floatval($producto["cantidad"]);
                        $pedidoDetalleDTO["totalSinIva"]
                         */
                        $pedDet = $pedidosDetallesFor[$k];
                        $sqlPedidoDetalle = "INSERT INTO pedido_detalle (pedido_proveedor_id, producto_proveedor_id, estatus_id, cantidad, precio_sin_iva, precio, total_sin_iva, total)
                                             VALUES (?,?,?,?,?,?,?,?)";
                        $sthPedidoDetalle = $db->prepare($sqlPedidoDetalle);
                        $sthPedidoDetalle->bindParam(1, $idPedidoProveedor, PDO::PARAM_INT);
                        $sthPedidoDetalle->bindParam(2, $pedDet["productoProveedorId"], PDO::PARAM_INT);
                        $sthPedidoDetalle->bindParam(3, $idEstatus, PDO::PARAM_INT);
                        $sthPedidoDetalle->bindParam(4, $pedDet["cantidad"], PDO::PARAM_INT);
                        $sthPedidoDetalle->bindParam(5, $pedDet["precioSinIva"], PDO::PARAM_STR);
                        $sthPedidoDetalle->bindParam(6, $pedDet["precio"], PDO::PARAM_STR);
                        $sthPedidoDetalle->bindParam(7, $pedDet["totalSinIva"], PDO::PARAM_STR);
                        $sthPedidoDetalle->bindParam(8, $pedDet["total"], PDO::PARAM_STR);
                        $sthPedidoDetalle->execute();
                        $idPedidoDetalle = $db->lastInsertId();
                        //Actualiza pedido detalle pa setear folio

                        $folioPedidoDetalle = "PR" . str_pad($idPedidoDetalle, 10, "0", STR_PAD_LEFT);

                        $queryUpdatePedidoDetalle = "UPDATE pedido_detalle SET folio = ? WHERE id = ?";
                        $sthUpdatePedidoDetalle = $db->prepare($queryUpdatePedidoDetalle);
                        $sthUpdatePedidoDetalle->bindParam(1, $folioPedidoDetalle, PDO::PARAM_STR);
                        $sthUpdatePedidoDetalle->bindParam(2, $idPedidoDetalle, PDO::PARAM_STR);
                        $sthUpdatePedidoDetalle->execute();
                        $debugger = "pedido-2";
                    }
                }
                $debugger = 66;

                //Update de pedido final
                $folioPedido = "P" . str_pad($idPedido, 9, "0", STR_PAD_LEFT);
                $debugger = $folioPedido;
                $totalSinComision = floatval($totalProductos);
                $comisionStripe = floatval($totalSinComision) * floatval(0.036) + floatval(3);
                $total = floatval($totalSinComision) + floatval($comisionStripe) + floatval($comisionTransportista);

                $queryUpdatePedido = "UPDATE pedido SET folio = ?, comision_transportista = ?, total_sin_iva = ?,
                                      total_sin_comision = ?, comision_stripe = ?, total = ? WHERE id = ?";
                $sthUpdatePedido = $db->prepare($queryUpdatePedido);
                $sthUpdatePedido->bindParam(1, $folioPedido, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(2, $comisionTransportista, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(3, $totalSinIva, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(4, $totalSinComision, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(5, $comisionStripe, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(6, $total, PDO::PARAM_STR);
                $sthUpdatePedido->bindParam(7, $idPedido, PDO::PARAM_INT);
                $sthUpdatePedido->execute();
                $debugger = 8;
                //Retornar último objeto
                $db->commit();
                $queryPedido = "SELECT * FROM pedido WHERE id = ?";
                $sthPedido = $db->prepare($queryPedido);
                $sthPedido->bindParam(1, $idPedido, PDO::PARAM_INT);
                $sthPedido->execute();
                $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);
                $debugger = 77;
                if ($rowsPedidos && $rowsPedidos[0]) {
                    $rowsPedidos[0] = convertKeysToCamelCase($rowsPedidos[0]);

                    if ($rowsPedidos[0]["clienteId"]) {
                        $rowsPedidos[0]["cliente"] = desgloceUsuario($rowsPedidos[0]["clienteId"]);
                    }
                    $debugger = 10;
                    $debugger = $rowsPedidos[0];
                    if ($rowsPedidos[0]["direccionContactoId"]) {
                        $rowsPedidos[0]["direccionContacto"] = desgloceDireccion($rowsPedidos[0]["direccionContactoId"]);
                    }
                    $debugger = 11;
                    if ($rowsPedidos[0]["estatusId"]) {
                        $rowsPedidos[0]["estatus"] = desgloceEstatus($rowsPedidos[0]["estatusId"]);
                    }
                    $debugger = 12;
                    $rowsPedidos[0]["pedidoProveedores"] = desglocePedidoProveedores($idPedido);

                    $rowsPedidos[0]["distanciaKM"] = $pedProv["distanciaKM"];
                    //$rowsPedidos[0]["pedidoProveedores2"] = desglocePedidoProveedores($idPedido);
                    //$rowsPedidos[0]["pedidoProveedores"]["pedidoDetalles"] = array();
                    for ($h = 0; $h < sizeof($rowsPedidos[0]["pedidoProveedores"]); $h++) {
                        # code...
                        $rowsPedidos[0]["pedidoProveedores"][$h]["pedidoDetalles"] = desglocePedidoDetallesPlural($rowsPedidos[0]["pedidoProveedores"][$h]["id"]);

                        //Evaluar si la distancia es mucho mayor que la parametrizada
                        if ($limiteParams < $rowsPedidos[0]["pedidoProveedores"][$h]["distanciaEntregaKm"]) {
                            $rowsPedidos[0]["pedidoProveedores"][$h]["envioExterno"] = true;
                        } else {
                            $rowsPedidos[0]["pedidoProveedores"][$h]["envioExterno"] = false;
                        }
                    }
                }

                $r = array();
                $r["uno"] = $rowsPedidos[0];
                $r["dos"] = $proves;
                echoResponse(200, $rowsPedidos[0]);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = [];
                $response["debugger"] = $debugger;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/pedidos', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);
            $rowsUser = null;
            try {

                $email = $app->request()->params('email');

                $qUser = "SELECT * FROM jhi_user
                       WHERE email = ?";
                $sthUser = $db->prepare($qUser);
                $sthUser->bindParam(1, $email, PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                $clienteId = $rowsUser[0]["id"];


                $queriePedidos = "SELECT * FROM pedido WHERE cliente_id = ?";
                $sthParams = $db->prepare($queriePedidos);
                $sthParams->bindParam(1, $clienteId, PDO::PARAM_STR);
                $sthParams->execute();
                $rows = $sthParams->fetchAll(PDO::FETCH_ASSOC);

                for ($i = 0; $i < sizeof($rows); $i++) {
                    $rows[$i] = convertKeysToCamelCase($rows[$i]);
                    if ($rows[$i]["id"]) {
                        $rows[$i] = desglocePedido($rows[$i]["id"]);
                    } 
                }

                
                echoResponse(200, $rows);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $rowsUser;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/pedidos/:id', function ($idPedido) use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);
            try {


                
                echoResponse(200, desglocePedido($idPedido));
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = [];
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/carrito-compras', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {

                $email = $app->request()->params('email');

                $query = "SELECT cc.* FROM carrito_compra cc
                            INNER JOIN producto_proveedor pr
                            ON (cc.producto_proveedor_id = pr.id)
                            WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)
                            ORDER BY pr.proveedor_id ASC, cc.id";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                if ($rows) {
                    for ($i = 0; $i < sizeof($rows); $i++) {
                        $rows[$i] = convertKeysToCamelCase($rows[$i]);
                        if ($rows[$i]["productoProveedorId"]) {
                            $rows[$i]["productoProveedor"] = desgloceProductoProveedor($rows[$i]["productoProveedorId"]);
                        }
                    }
                }

                echoResponse(200, $rows);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->put('/pedidos/pago', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {
                $pedidoId = $data['pedidoId'];
                $token = $data['token'];
                $email = $data['email'];
                $emailStripe = $data['emailStripe'];

                $fcmMessaje = $data['fcmMessage'];

                $pedido = desglocePedido($pedidoId);

                $cardDetails = array();
                $cardDetails["email"] = $emailStripe;
                $cardDetails["token"] = $token;

                $fecha = new DateTime();
                $number = $fecha->getTimestamp();

                $dataInfo = array();
                $dataInfo["amount"] = $pedido["total"];
                $dataInfo["currency_code"] = "MXN";

                $description = "Pago de pedido: " . $pedido["folio"];

                $dataInfo["item_name"] = $description;
                $dataInfo["item_number"] = $number;

                $resultado = chargeAmountFromCard($cardDetails, $dataInfo);

                //Enviar notificación a y eliminar carrito por cliente

                $fcm = new FCMNotification();

                ///

                if ($resultado->status == "succeeded") {
                    //Verificar parametro de KMS
                    $qParams = "SELECT * FROM parametros_aplicacion
                    WHERE clave = ?";
                    $s = "maximos_kms";
                    $sthParams = $db->prepare($qParams);
                    $sthParams->bindParam(1, $s, PDO::PARAM_STR);
                    $sthParams->execute();
                    $rowsParams = $sthParams->fetchAll(PDO::FETCH_ASSOC);

                    $limiteParams = floatval($rowsParams[0]["descripcion"]);
                    //
                    $idEstatus = 11; //Estatus de pedido pagado

                    //Se actualiza pedido con pago efectuado
                    $queryUpdatePedido = "UPDATE pedido SET status_pago = ?, balance_transaction = ?, charge_id = ?, receipt_url = ?, estatus_id = ? WHERE id = ?";
                    $sthUpdatePedido = $db->prepare($queryUpdatePedido);
                    $sthUpdatePedido->bindParam(1, $resultado->status, PDO::PARAM_STR);
                    $sthUpdatePedido->bindParam(2, $resultado->balance_transaction, PDO::PARAM_STR);
                    $sthUpdatePedido->bindParam(3, $resultado->id, PDO::PARAM_STR);
                    $sthUpdatePedido->bindParam(4, $resultado->receipt_url, PDO::PARAM_STR);
                    $sthUpdatePedido->bindParam(5, $idEstatus, PDO::PARAM_INT);
                    $sthUpdatePedido->bindParam(6, $pedidoId, PDO::PARAM_INT);
                    $sthUpdatePedido->execute();

                    $qUser = "SELECT * FROM jhi_user
                       WHERE email = ?";
                    $sthUser = $db->prepare($qUser);
                    $sthUser->bindParam(1, $email, PDO::PARAM_INT);
                    $sthUser->execute();
                    $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

                    $clienteId = $rowsUser[0]["id"];

                    //Busca pedido proveedor
                    $qPP = "SELECT * FROM pedido_proveedor
                       WHERE pedido_id = ?";
                    $sthPedidoProveedores = $db->prepare($qPP);
                    $sthPedidoProveedores->bindParam(1, $pedidoId, PDO::PARAM_INT);
                    $sthPedidoProveedores->execute();
                    $rowsPedidoProveedores = $sthPedidoProveedores->fetchAll(PDO::FETCH_ASSOC);

                    for ($i = 0; $i < sizeof($rowsPedidoProveedores); $i++) {
                        $idTmp = $rowsPedidoProveedores[$i]["id"];
                        $pedidoProv = desglocePedidoProveedor($rowsPedidoProveedores[$i]["id"]);
                        $kmProveedor = floatval($rowsPedidoProveedores[$i]["distancia_entrega_km"]);
                        # code...
                        $queryUpdatePedidoProveedor = "UPDATE pedido_proveedor SET estatus_id = ?, usuario_modificacion_id = ?, fecha_modificacion = now() WHERE id = ?";
                        $sthUpdatePedidoProveedor = $db->prepare($queryUpdatePedidoProveedor);
                        $sthUpdatePedidoProveedor->bindParam(1, $idEstatus, PDO::PARAM_INT);
                        $sthUpdatePedidoProveedor->bindParam(2, $clienteId, PDO::PARAM_INT);
                        $sthUpdatePedidoProveedor->bindParam(3, $idTmp, PDO::PARAM_INT);
                        $sthUpdatePedidoProveedor->execute();

                        $sqlPedidoProveedorHistorico = "INSERT INTO pedido_proveedor_historico (pedido_proveedor_id, estatus_id, usuario_id, fecha)
                                               VALUES (?,?,?,now())";
                        $sthPedidoProveedorHistorico = $db->prepare($sqlPedidoProveedorHistorico);
                        $sthPedidoProveedorHistorico->bindParam(1, $idTmp, PDO::PARAM_INT);
                        $sthPedidoProveedorHistorico->bindParam(2, $idEstatus, PDO::PARAM_INT);
                        $sthPedidoProveedorHistorico->bindParam(3, $clienteId, PDO::PARAM_INT);
                        $sthPedidoProveedorHistorico->execute();

                        $queryUpdatePedidoDetalle = "UPDATE pedido_detalle SET estatus_id = ? WHERE pedido_proveedor_id = ?";
                        $sthUpdatePedidoDetalle = $db->prepare($queryUpdatePedidoDetalle);
                        $sthUpdatePedidoDetalle->bindParam(1, $idEstatus, PDO::PARAM_INT);
                        $sthUpdatePedidoDetalle->bindParam(2, $idTmp, PDO::PARAM_INT);
                        $sthUpdatePedidoDetalle->execute();

                        $title = "Nuevo pedido: " . $pedidoProv["pedido"]["folio"];
                        $body = "El cliente " . $pedidoProv["pedido"]["cliente"]["firstName"] . " " . $pedidoProv["pedido"]["cliente"]["lastName"] . " ha solicitado un pedido ";

                        $data_body = array(
                            'view' => 1,
                            'pedidoId' => intval($rowsPedidoProveedores[$i]["pedido_id"]),
                        );

                        $notification = array(
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                            'click_action' => 'FCM_PLUGIN_ACTIVITY');

                        $arrayToSend = array(
                            'to' => $pedidoProv["proveedor"]["usuario"]["token"],
                            'notification' => $notification,
                            'data' => $data_body,
                            'priority' => 'high');

                        //Modificar el body
                        if ($limiteParams < $kmProveedor) {
                            //El tipo de envio es externo
                            $return = $fcm->sendData($arrayToSend);
                        } else {
                            //Tipo de envio con transportista asignado
                            $return = $fcm->sendData($arrayToSend);
                        }
                        //Una vez que se manda ahora crear registro en tabla notification en DB

                        //ver como obtener parametros
                        $qNot = "INSERT INTO notificacion (usuario_id, view_id, titulo, descripcion, fecha_notificacion, estatus, parametros) VALUES (?, ?, ?, ?, now(), 0, ?)";
                        $sthNot = $db->prepare($qNot);
                        $sthNot->bindParam(1, $pedidoProv["proveedor"]["usuario"]["id"], PDO::PARAM_STR);
                        $sthNot->bindParam(2, $data_body["view"], PDO::PARAM_STR);
                        $sthNot->bindParam(3, $title, PDO::PARAM_STR);
                        $sthNot->bindParam(4, $body, PDO::PARAM_STR);
                        $sthNot->bindParam(5, $idEstatus, PDO::PARAM_INT);
                        $sthNot->execute();
                    }

                    $qDelete = "DELETE FROM carrito_compra WHERE cliente_id = ?";
                    $sthDelete = $db->prepare($qDelete);
                    $sthDelete->bindParam(1, $clienteId, PDO::PARAM_INT);
                    $sthDelete->execute();

                    $db->commit();

                    $pedidoReturn = desglocePedido($pedidoId);

                    echoResponse(200, $pedidoReturn);
                } else {
                    $response["status"] = false;
                    $response["message"] = "Pago no efectuado, revisa tu información";
                    $response["idTransaction"] = time();
                    $response["parameters"] = $resultado;
                    $response["timeRequest"] = date("Y-m-d H:i:s");

                    echoResponse(400, $response);
                }

            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/tarjetas', function () use ($app) {

            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $body = $app->request->getBody();
            $data = json_decode($body, true);

            try {

                $email = $app->request()->params('email');

                $query = "SELECT cc.* FROM carrito_compra cc
                            INNER JOIN producto_proveedor pr
                            ON (cc.producto_proveedor_id = pr.id)
                            WHERE cliente_id = (SELECT id FROM jhi_user WHERE email = ?)
                            ORDER BY pr.proveedor_id ASC, cc.id";
                $sth = $db->prepare($query);
                $sth->bindParam(1, $email, PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                if ($rows) {
                    for ($i = 0; $i < sizeof($rows); $i++) {
                        $rows[$i] = convertKeysToCamelCase($rows[$i]);
                        if ($rows[$i]["productoProveedorId"]) {
                            $rows[$i]["productoProveedor"] = desgloceProductoProveedor($rows[$i]["productoProveedorId"]);
                        }
                    }
                }

                echoResponse(200, $rows);
            } catch (Exception $e) {
                $db->rollBack();
                $response["status"] = false;
                $response["description"] = $e->getMessage();
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }

        });

        $app->get('/desgloce/:id', function ($id) use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                $response["status"] = true;
                $response["description"] = "SUCCESSFUL";
                $response["idTransaction"] = time();
                $response["parameters"] = desgloceProductoProveedor($id);
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $response);

            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });

        $app->get('/cotizaciones', function () use ($app) {
            $response = array();
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();
            $rows = null;
            try {

                $peso = $app->request()->params('peso');
                $alto = $app->request()->params('alto');
                $largo = $app->request()->params('largo');
                $ancho = $app->request()->params('ancho');

                $origen = $app->request()->params('origen');
                $destino = $app->request()->params('destino');
                $servicio = 3;
                try {
                    if ($app->request()->params('servicio')) {
                        $servicio = $app->request()->params('servicio');
                        try {
                            //code...
                            $servicio = intval($servicio);
                            if ($servicio < 0 || $servicio > 2) {
                                $servicio = 3;
                            }
                        } catch (Exception $e) {
                            //throw $th;
                            $servicio = 3;
                        }
                    } else {
                        $servicio = 3;
                    }
                } catch (Exception $e) {
                    $servicio = 3;
                }

                $cotizacion = cotizar($origen, $destino, $ancho, $largo, $alto, $peso, $servicio);
                return $cotizacion;
            } catch (Exception $e) {

                $response["status"] = false;
                $response["description"] = "GENERIC-ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $e->getMessage();
                $response["parameters2"] = $rows;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);
            }
        });
        /* corremos la aplicación */
        $app->run();

        function cotizar($origen = null, $destino = null, $ancho = null, $largo = null, $alto = null, $peso = null, $servicio = 3)
        {
            $data = array();

            $data["origen"] = $origen;
            $data["destino"] = $destino;
            $data["ancho"] = $ancho;
            $data["largo"] = $largo;
            $data["alto"] = $alto;
            $data["peso"] = $peso;

            //Evaluaciones
            $errorInt = 0;
            $error = array();
            if (!$origen) {
                $errorInt++;
                array_push($error, "Falta parametro origen");
            }
            if (!$destino) {
                $errorInt++;
                array_push($error, "Falta parametro destino");
            }
            if (!$ancho) {
                $errorInt++;
                array_push($error, "Falta parametro ancho");
            }
            if (!$largo) {
                $errorInt++;
                array_push($error, "Falta parametro largo");
            }
            if (!$alto) {
                $errorInt++;
                array_push($error, "Falta parametro alto");
            }
            if (!$peso) {
                $errorInt++;
                array_push($error, "Falta parametro peso");
            }
            if (!preg_match("/^[0-9]{5}$/", $origen) || !preg_match("/^[0-9]{5}$/", $destino)) {
                $errorInt++;
                array_push($error, "No es un código postal de origen o destino válido");
            }

            if (sizeof($error) > 0) {
                $response["status"] = false;
                $response["description"] = "ERROR";
                $response["idTransaction"] = time();
                $response["parameters"] = $error;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $response);
            } else {
                $retorno = array();
                if ($servicio == 3 || $servicio == "2" || $servicio == 2) {
                    $retornoEstafeta = array();
                    $retornoEstafetaTmp = [];

                    $estafeta = fromEstafeta($data);
                    if ($estafeta->FrecuenciaCotizadorResult) {
                        if ($estafeta->FrecuenciaCotizadorResult->Respuesta) {
                            if ($estafeta->FrecuenciaCotizadorResult->Respuesta->TipoServicio) {
                                if ($estafeta->FrecuenciaCotizadorResult->Respuesta->TipoServicio->TipoServicio) {
                                    $retornoEstafetaTmp = $estafeta->FrecuenciaCotizadorResult->Respuesta->TipoServicio->TipoServicio;
                                }
                            }
                        }
                    }

                    for ($i = 0; $i < sizeof($retornoEstafetaTmp); $i++) {
                        $obj = $retornoEstafetaTmp[$i];
                        $retornoEstafeta[$i]["costoTotal"] = $obj->CostoTotal;
                        $retornoEstafeta[$i]["error"] = "000";
                        $retornoEstafeta[$i]["errorDescription"] = "";
                        $retornoEstafeta[$i]["plaza"] = $estafeta->FrecuenciaCotizadorResult->Respuesta->Destino->Plaza1;
                        $retornoEstafeta[$i]["producto"] = $obj->DescripcionServicio;
                        $retornoEstafeta[$i]["productoIDGlobal"] = null;
                        $retornoEstafeta[$i]["productoIDLocal"] = null;
                        $retornoEstafeta[$i]["proveedor"] = "Estafeta";
                        $retornoEstafeta[$i]["tarifaBase"] = $obj->TarifaBase;
                    }

                    $retorno["estafeta"] = $retornoEstafeta;
                }

                if ($servicio == 3 || $servicio == "1" || $servicio == 1) {
                    $retornoDHL = array();
                    $retornoDHLTmp = [];

                    $retornoDHLTmp = fromDHL($data);

                    for ($i = 0; $i < sizeof($retornoDHLTmp); $i++) {
                        $obj = $retornoDHLTmp[$i];
                        $retornoDHL[$i]["costoTotal"] = $obj["price"]["amount"];
                        $retornoDHL[$i]["error"] = "000";
                        $retornoDHL[$i]["errorDescription"] = "";
                        $retornoDHL[$i]["plaza"] = null;
                        $output = replaceTree($obj["key"]);
                        $retornoDHL[$i]["producto"] = $output;
                        $retornoDHL[$i]["productoIDGlobal"] = null;
                        $retornoDHL[$i]["productoIDLocal"] = null;
                        $retornoDHL[$i]["proveedor"] = "DHL";
                        $retornoDHL[$i]["tarifaBase"] = $obj["price"]["amount"];
                    }

                    $retorno["dhl"] = $retornoDHL;
                }

                $response["status"] = true;
                $response["description"] = "SUCCESSFUL";
                $response["idTransaction"] = time();
                $response["parameters"] = $retorno;
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(200, $response);
            }
        }

        function replaceTree($palabra)
        {
            $separado = explode("_", $palabra);

            $junto = "";
            for ($i = 0; $i < sizeof($separado); $i++) {
                # code...
                $junto .= $separado[$i] . " ";
            }
            return trim($junto);
        }

        function fromDHL($data)
        {

            $zipCodeOrigin = $data["origen"];
            $zipCodeDestination = $data["destino"];

            $peso = $data["peso"];
            $alto = $data["alto"];
            $ancho = $data["ancho"];
            $largo = $data["largo"];

            $url = 'https://cj-gaq.dhl.com/api/quote?destinationCity=a&destinationCitydisplayValue=a&destinationCountry=MX&destinationUseFallback=false&destinationZip=' . $zipCodeDestination . '&destinationZipdisplayValue=' . $zipCodeDestination . '&originCity=b&originCitydisplayValue=b&originCountry=MX&originUseFallback=false&originZip=' . $zipCodeOrigin . '&originZipdisplayValue=' . $zipCodeDestination . '&receiverAddressType=RESIDENTIAL&receiverType=CONSUMER&senderType=CONSUMER&items(0).weight=' . $peso . '&items(0).height=' . $alto . '&items(0).length=' . $largo . '&items(0).width=' . $ancho . '&items(0).quantity=1&items(0).presetSize=&items(0).unitSystem=METRIC&language=es&marketCountry=MX&selectedSegment=private';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $response = curl_exec($ch);
            curl_close($ch);
            $response_a = json_decode($response, true);

            $url2 = 'https://cj-gaq.dhl.com/api/quote?destinationCity=a&destinationCitydisplayValue=a&destinationCountry=MX&destinationUseFallback=false&destinationZip=' . $zipCodeDestination . '&destinationZipdisplayValue=' . $zipCodeDestination . '&originCity=b&originCitydisplayValue=b&originCountry=MX&originUseFallback=false&originZip=' . $zipCodeOrigin . '&originZipdisplayValue=' . $zipCodeDestination . '&receiverAddressType=RESIDENTIAL&receiverType=CONSUMER&senderType=CONSUMER&items(0).weight=' . $peso . '&items(0).height=' . $alto . '&items(0).length=' . $largo . '&items(0).width=' . $ancho . '&items(0).quantity=1&items(0).presetSize=&items(0).unitSystem=METRIC&language=es&marketCountry=MX&selectedSegment=business';
            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_URL, $url2);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch2, CURLOPT_PROXYPORT, 3128);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, 0);
            $response2 = curl_exec($ch2);
            curl_close($ch2);
            $response_b = json_decode($response2, true);

            return array_merge($response_a["offers"], $response_b["offers"]);
        }

        function fromEstafeta($data)
        {
            $zipCodeOrigin = $data["origen"];
            $zipCodeDestination = $data["destino"];

            $peso = $data["peso"];
            $alto = $data["alto"];
            $ancho = $data["ancho"];
            $largo = $data["largo"];

            $client = new SoapClient('https://frecuenciacotizadorqa.estafeta.com/Service.asmx?WSDL', ['trace' => true, 'cache_wsdl' => WSDL_CACHE_MEMORY]);

            $result = $client->__soapCall("FrecuenciaCotizador", array(array(
                "idusuario" => 1,
                "usuario" => "AdminUser",
                "contra" => ",1,B(vVi",
                "esFrecuencia" => false,
                "esLista" => true,
                "tipoEnvio" => array(
                    "EsPaquete" => true,
                    "Largo" => $largo,
                    "Peso" => $peso,
                    "Alto" => $alto,
                    "Ancho" => $ancho,
                ),
                "datosOrigen" => array($zipCodeOrigin),
                "datosDestino" => array($zipCodeDestination),
            )));
            $resultado = $result;
            return $resultado;
        }

        function getDrivingDistance($lat1, $lat2, $long1, $long2)
        {
            //https://maps.googleapis.com/maps/api/distancematrix/json?units=imperial&origins=Washington,DC&destinations=New+York+City,NY&key=AIzaSyBTzFU__xJrf9DvyWrVToCVfRWoIUIEmx0
            $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . $lat1 . "," . $long1 . "&destinations=" . $lat2 . "," . $long2 . "&mode=driving&key=AIzaSyBTzFU__xJrf9DvyWrVToCVfRWoIUIEmx0";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $response = curl_exec($ch);
            curl_close($ch);
            $response_a = json_decode($response, true);
            $dist = $response_a['rows'][0]['elements'][0]['distance']['text'];
            $time = $response_a['rows'][0]['elements'][0]['duration']['text'];

            return array('distance' => $dist, 'time' => $time);
        }

        function desgloceProveedor($id, $db)
        {

            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queriProveedor = "SELECT * FROM proveedor WHERE id = ?";

            $sthProveedor = $db->prepare($queriProveedor);
            $sthProveedor->bindParam(1, $id, PDO::PARAM_INT);
            $sthProveedor->execute();
            $rowsProveedor = $sthProveedor->fetchAll(PDO::FETCH_ASSOC);
            $rowsProveedor[0] = convertKeysToCamelCase($rowsProveedor[0]);

            $user = "SELECT * FROM jhi_user WHERE id = ?";
            $sthUser = $db->prepare($user);
            $sthUser->bindParam(1, $rowsProveedor[0]["usuarioId"], PDO::PARAM_INT);
            $sthUser->execute();
            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

            $rowsProveedor[0]["usuario"] = convertKeysToCamelCase($rowsUser[0]);

            //transportista
            if ($rowsProveedor[0]["transportistaId"]) {
                $user = "SELECT * FROM transportista WHERE id = ?";
                $sthUser = $db->prepare($user);
                $sthUser->bindParam(1, $rowsProveedor[0]["transportistaId"], PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
                $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                //$rows[0]["transportista"] = $rowsUser[0];

                if ($rowsUser[0]) {
                    $rowsProveedor[0]["transportista"] = $rowsUser[0];

                    $user = "SELECT * FROM jhi_user WHERE id = ?";
                    $sthUser = $db->prepare($user);
                    $sthUser->bindParam(1, $rowsUser[0]["usuarioId"], PDO::PARAM_INT);
                    $sthUser->execute();
                    $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
                    $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                    if ($rowsUser && $rowsUser[0]) {
                        $rowsProveedor[0]["transportista"]["usuario"] = $rowsUser[0];
                    } else {
                        $rowsProveedor[0]["transportista"]["usuario"] = null;
                    }
                } else {
                    $rowsProveedor[0]["transportista"] = null;
                }
            }

            if ($rowsProveedor[0]["empresaId"]) {
                $user = "SELECT * FROM empresa WHERE id = ?";
                $sthUser = $db->prepare($user);
                $sthUser->bindParam(1, $rowsProveedor[0]["empresaId"], PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
                $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                if ($rowsUser && $rowsUser[0]) {
                    $rowsProveedor[0]["empresa"] = $rowsUser[0];
                } else {
                    $rowsProveedor[0]["empresa"] = null;
                }
            } else {
                $rowsProveedor[0]["empresa"] = null;
            }

            if ($rowsProveedor[0]["direccionId"]) {
                $rowsProveedor[0]["direccion"] = desgloceDireccion($rowsProveedor[0]["direccionId"]);
            }

            return $rowsProveedor[0];
        }

        function desgloceUsuario($id, $db)
        {

            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $user = "SELECT * FROM jhi_user WHERE id = ?";
            $sthUser = $db->prepare($user);
            $sthUser->bindParam(1, $id, PDO::PARAM_INT);
            $sthUser->execute();
            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);

            $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);

            if ($rowsUser[0]["adjuntoId"]) {
                $adjunto = "SELECT * FROM adjunto WHERE id = ?";
                $sthAdjunto = $db->prepare($adjunto);
                $sthAdjunto->bindParam(1, $rowsUser[0]["adjuntoId"], PDO::PARAM_INT);
                $sthAdjunto->execute();
                $rowsAdjunto = $sthAdjunto->fetchAll(PDO::FETCH_ASSOC);

                if ($rowsAdjunto && $rowsAdjunto[0]) {
                    $rowsUser[0]["adjunto"] = convertKeysToCamelCase($rowsAdjunto[0]);
                }
            }

            return $rowsUser[0];
        }

        function desgloceTransportista($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $user = "SELECT * FROM transportista WHERE id = ?";
            $sthUser = $db->prepare($user);
            $sthUser->bindParam(1, $id, PDO::PARAM_INT);
            $sthUser->execute();
            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
            $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
            //$rows[0]["transportista"] = $rowsUser[0];

            if ($rowsUser[0]) {
                $user = "SELECT * FROM jhi_user WHERE id = ?";
                $sthUser = $db->prepare($user);
                $sthUser->bindParam(1, $rowsUser[0]["usuarioId"], PDO::PARAM_INT);
                $sthUser->execute();
                $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
                $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                if ($rowsUser && $rowsUser[0]) {
                    $rowsUser[0]["usuario"] = $rowsUser[0];
                } else {
                    $rowsUser[0]["usuario"] = null;
                }
            }
            return $rowsUser[0];
        }

        function desgloceProducto($id, $db))
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $user = "SELECT * FROM producto WHERE id = ?";
            $sthUser = $db->prepare($user);
            $sthUser->bindParam(1, $id, PDO::PARAM_INT);
            $sthUser->execute();
            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
            //$rowsUser = convertKeysToCamelCase($rowsUser);
            if ($rowsUser && $rowsUser[0]) {
                $rowsUser[0] = convertKeysToCamelCase($rowsUser[0]);
                $rowsProductos[0]["producto"] = $rowsUser[0];

                if ($rowsUser[0]["estatusId"]) {
                    $estatus = "SELECT * FROM estatus WHERE id = ?";
                    $sthEstatus = $db->prepare($estatus);
                    $sthEstatus->bindParam(1, $rowsUser[0]["estatusId"], PDO::PARAM_INT);
                    $sthEstatus->execute();
                    $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                    //$rowsEstatus = convertKeysToCamelCase($rowsEstatus);

                    if ($rowsEstatus && $rowsEstatus[0]) {
                        $rowsProductos[0]["producto"]["estatus"] = convertKeysToCamelCase($rowsEstatus[0]);
                    } else {
                        $rowsProductos[0]["producto"]["estatus"] = null;
                    }
                }

                if ($rowsUser[0]["unidadMedidaId"]) {
                    $unidad = "SELECT * FROM unidad_medida WHERE id = ?";
                    $sthUnidad = $db->prepare($unidad);
                    $sthUnidad->bindParam(1, $rowsUser[0]["unidadMedidaId"], PDO::PARAM_INT);
                    $sthUnidad->execute();
                    $rowsUnidad = $sthUnidad->fetchAll(PDO::FETCH_ASSOC);
                    //$rowsUnidad = convertKeysToCamelCase($rowsUnidad);
                    if ($rowsUnidad && $rowsUnidad[0]) {
                        $rowsProductos[0]["producto"]["unidadMedida"] = convertKeysToCamelCase($rowsUnidad[0]);
                    } else {
                        $rowsProductos[0]["producto"]["unidadMedida"] = null;
                    }
                }

                if ($rowsUser[0]["tipoArticuloId"]) {
                    $articulo = "SELECT * FROM tipo_articulo WHERE id = ?";
                    $sthArticulo = $db->prepare($articulo);
                    $sthArticulo->bindParam(1, $rowsUser[0]["tipoArticuloId"], PDO::PARAM_INT);
                    $sthArticulo->execute();
                    $rowsArticulo = $sthArticulo->fetchAll(PDO::FETCH_ASSOC);

                    //$rowsArticulo = convertKeysToCamelCase($rowsArticulo);
                    if ($rowsArticulo && $rowsArticulo[0]) {
                        $rowsArticulo[0] = convertKeysToCamelCase($rowsArticulo[0]);
                        $rowsProductos[0]["producto"]["tipoArticulo"] = $rowsArticulo[0];

                        if ($rowsArticulo[0]["categoriaId"]) {
                            $categoria = "SELECT * FROM categoria WHERE id = ?";
                            $sthCategoria = $db->prepare($categoria);
                            $sthCategoria->bindParam(1, $rowsArticulo[0]["categoriaId"], PDO::PARAM_INT);
                            $sthCategoria->execute();
                            $rowsCategoria = $sthCategoria->fetchAll(PDO::FETCH_ASSOC);
                            //$rowsCategoria = convertKeysToCamelCase($rowsCategoria);
                            if ($rowsCategoria && $rowsCategoria[0]) {
                                $rowsCategoria[0] = convertKeysToCamelCase($rowsCategoria[0]);
                                $rowsProductos[0]["producto"]["tipoArticulo"]["categoria"] = $rowsCategoria[0];

                                if ($rowsCategoria[0]["seccionId"]) {
                                    $seccion = "SELECT * FROM seccion WHERE id = ?";
                                    $sthSeccion = $db->prepare($seccion);
                                    $sthSeccion->bindParam(1, $rowsCategoria[0]["seccionId"], PDO::PARAM_INT);
                                    $sthSeccion->execute();
                                    $rowsSeccion = $sthSeccion->fetchAll(PDO::FETCH_ASSOC);
                                    //$rowsSeccion = convertKeysToCamelCase($rowsSeccion);
                                    if ($rowsSeccion && $rowsSeccion[0]) {
                                        $rowsProductos[0]["producto"]["tipoArticulo"]["categoria"]["seccion"] = convertKeysToCamelCase($rowsSeccion[0]);
                                    } else {
                                        $rowsProductos[0]["producto"]["tipoArticulo"]["categoria"]["seccion"] = null;
                                    }
                                }
                            } else {
                                $rowsProductos[0]["producto"]["tipoArticulo"]["unidadMedida"] = null;
                            }
                        }
                    } else {
                        $rowsProductos[0]["producto"]["tipoArticulo"] = null;
                    }
                }
            } else {
                $rowsProductos[0]["producto"] = null;
            }

            $estatus = "SELECT * FROM estatus WHERE id = ?";
            $sthEstatus = $db->prepare($estatus);
            $sthEstatus->bindParam(1, $rowsProductos[0]["estatusId"], PDO::PARAM_INT);
            $sthEstatus->execute();
            $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
            if ($rowsEstatus && $rowsEstatus[0]) {
                $rowsProductos[0]["estatus"] = convertKeysToCamelCase($rowsEstatus[0]);
            } else {
                $rowsProductos[0]["estatus"] = null;
            }

            return $rowsProductos[0]["producto"];
        }

        function desgloceProductoByTipoArticulo($id, $db)
        {

            $user = "SELECT * FROM producto WHERE tipo_articulo_id = ?";
            $sthUser = $db->prepare($user);
            $sthUser->bindParam(1, $id, PDO::PARAM_INT);
            $sthUser->execute();
            $rowsUser = $sthUser->fetchAll(PDO::FETCH_ASSOC);
            $t = null;
            //$rowsUser = convertKeysToCamelCase($rowsUser);
            //foreach ($rowsUser as $key => $tmp) {
            $prods = array();
            for ($i=0; $i < sizeof($rowsUser); $i++) { 
                $producto = convertKeysToCamelCase($rowsUser[$i]);
                //$rowsProductos[0]["producto"] = $producto;
                //t= convertKeysToCamelCase($rowsUser[$i]);
                if ($producto["estatusId"]) {
                    $estatus = "SELECT * FROM estatus WHERE id = ?";
                    $sthEstatus = $db->prepare($estatus);
                    $sthEstatus->bindParam(1, $producto["estatusId"], PDO::PARAM_INT);
                    $sthEstatus->execute();
                    $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
                    //$rowsEstatus = convertKeysToCamelCase($rowsEstatus);

                    if ($rowsEstatus && $rowsEstatus[0]) {
                        $producto["estatus"] = convertKeysToCamelCase($rowsEstatus[0]);
                    } else {
                        $producto["estatus"] = null;
                    }
                }

                if ($producto["unidadMedidaId"]) {
                    $unidad = "SELECT * FROM unidad_medida WHERE id = ?";
                    $sthUnidad = $db->prepare($unidad);
                    $sthUnidad->bindParam(1, $producto["unidadMedidaId"], PDO::PARAM_INT);
                    $sthUnidad->execute();
                    $rowsUnidad = $sthUnidad->fetchAll(PDO::FETCH_ASSOC);
                    //$rowsUnidad = convertKeysToCamelCase($rowsUnidad);
                    if ($rowsUnidad && $rowsUnidad[0]) {
                        $producto["unidadMedida"] = convertKeysToCamelCase($rowsUnidad[0]);
                    } else {
                        $producto["unidadMedida"] = null;
                    }
                }

                if ($producto["tipoArticuloId"]) {
                    $articulo = "SELECT * FROM tipo_articulo WHERE id = ?";
                    $sthArticulo = $db->prepare($articulo);
                    $sthArticulo->bindParam(1, $producto["tipoArticuloId"], PDO::PARAM_INT);
                    $sthArticulo->execute();
                    $rowsArticulo = $sthArticulo->fetchAll(PDO::FETCH_ASSOC);

                    //$rowsArticulo = convertKeysToCamelCase($rowsArticulo);
                    if ($rowsArticulo && $rowsArticulo[0]) {
                        $rowsArticulo[0] = convertKeysToCamelCase($rowsArticulo[0]);
                        $producto["tipoArticulo"] = $rowsArticulo[0];

                        if ($rowsArticulo[0]["categoriaId"]) {
                            $categoria = "SELECT * FROM categoria WHERE id = ?";
                            $sthCategoria = $db->prepare($categoria);
                            $sthCategoria->bindParam(1, $rowsArticulo[0]["categoriaId"], PDO::PARAM_INT);
                            $sthCategoria->execute();
                            $rowsCategoria = $sthCategoria->fetchAll(PDO::FETCH_ASSOC);
                            //$rowsCategoria = convertKeysToCamelCase($rowsCategoria);
                            if ($rowsCategoria && $rowsCategoria[0]) {
                                $rowsCategoria[0] = convertKeysToCamelCase($rowsCategoria[0]);
                                $producto["tipoArticulo"]["categoria"] = $rowsCategoria[0];

                                if ($rowsCategoria[0]["seccionId"]) {
                                    $seccion = "SELECT * FROM seccion WHERE id = ?";
                                    $sthSeccion = $db->prepare($seccion);
                                    $sthSeccion->bindParam(1, $rowsCategoria[0]["seccionId"], PDO::PARAM_INT);
                                    $sthSeccion->execute();
                                    $rowsSeccion = $sthSeccion->fetchAll(PDO::FETCH_ASSOC);
                                    //$rowsSeccion = convertKeysToCamelCase($rowsSeccion);
                                    if ($rowsSeccion && $rowsSeccion[0]) {
                                        $producto["tipoArticulo"]["categoria"]["seccion"] = convertKeysToCamelCase($rowsSeccion[0]);
                                    } else {
                                        $producto["tipoArticulo"]["categoria"]["seccion"] = null;
                                    }
                                }
                            } else {
                                $producto["tipoArticulo"]["unidadMedida"] = null;
                            }
                        }
                    } else {
                        $producto["tipoArticulo"] = null;
                    }
                }
                array_push($prods, $producto);
            }

            return $prods;
        }

        function desgloceEstatus($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $estatus = "SELECT * FROM estatus WHERE id = ?";
            $sthEstatus = $db->prepare($estatus);
            $sthEstatus->bindParam(1, $id, PDO::PARAM_INT);
            $sthEstatus->execute();
            $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
            $rowsEstatus[0] = convertKeysToCamelCase($rowsEstatus[0]);

            return $rowsEstatus[0];
        }

        function desgloceDireccion($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $estatus = "SELECT * FROM direccion WHERE id = ?";
            $sthEstatus = $db->prepare($estatus);
            $sthEstatus->bindParam(1, $id, PDO::PARAM_INT);
            $sthEstatus->execute();
            $rowsEstatus = $sthEstatus->fetchAll(PDO::FETCH_ASSOC);
            $rowsEstatus[0] = convertKeysToCamelCase($rowsEstatus[0]);

            return $rowsEstatus[0];
        }

        function desgloceProductoProveedor($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryProductos = "SELECT pp.* FROM producto_proveedor pp
                        INNER JOIN producto p
                        ON (p.id = pp.producto_id)
                        INNER JOIN tipo_articulo ta
                        ON (ta.id = p.tipo_articulo_id)
                        INNER JOIN categoria c
                        ON (c.id = ta.categoria_id)
                        WHERE pp.id = ?";
            $sthProductos = $db->prepare($queryProductos);
            $sthProductos->bindParam(1, $id, PDO::PARAM_INT);
            $sthProductos->execute();
            $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsProductos && $rowsProductos[0]) {
                # code...
                $rowsProductos[0] = convertKeysToCamelCase($rowsProductos[0]);
                if ($rowsProductos[0]["productoId"]) {
                    $rowsProductos[0]["producto"] = desgloceProducto($rowsProductos[0]["productoId"]);
                }

                if ($rowsProductos[0]["proveedorId"]) {
                    $rowsProductos[0]["proveedor"] = desgloceProveedor($rowsProductos[0]["proveedorId"]);
                }

                if ($rowsProductos[0]["estatusId"]) {
                    $rowsProductos[0]["estatus"] = desgloceEstatus($rowsProductos[0]["estatusId"]);
                }

            }
            return $rowsProductos[0];
        }

        function desglocePedido($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryPedido = "SELECT * FROM pedido WHERE id = ?";
            $sthPedido = $db->prepare($queryPedido);
            $sthPedido->bindParam(1, $id, PDO::PARAM_INT);
            $sthPedido->execute();
            $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsPedidos && $rowsPedidos[0]) {
                $rowsPedidos[0] = convertKeysToCamelCase($rowsPedidos[0]);
                if ($rowsPedidos[0]["clienteId"]) {
                    $rowsPedidos[0]["cliente"] = desgloceUsuario($rowsPedidos[0]["clienteId"]);
                }
                if ($rowsPedidos[0]["direccionContactoId"]) {
                    $rowsPedidos[0]["direccionContacto"] = desgloceDireccion($rowsPedidos[0]["direccionContactoId"]);
                }
                if ($rowsPedidos[0]["estatusId"]) {
                    $rowsPedidos[0]["estatus"] = desgloceEstatus($rowsPedidos[0]["estatusId"]);
                }

                //$rowsPedidos[0]["pedidoProveedores"] = desglocePedidoProveedores($rowsPedidos[0]["id"]);
            }
            return $rowsPedidos[0];
        }

        function desglocePedidoWithProveedores($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryPedido = "SELECT * FROM pedido WHERE id = ?";
            $sthPedido = $db->prepare($queryPedido);
            $sthPedido->bindParam(1, $id, PDO::PARAM_INT);
            $sthPedido->execute();
            $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsPedidos && $rowsPedidos[0]) {
                $rowsPedidos[0] = convertKeysToCamelCase($rowsPedidos[0]);
                if ($rowsPedidos[0]["clienteId"]) {
                    $rowsPedidos[0]["cliente"] = desgloceUsuario($rowsPedidos[0]["clienteId"]);
                }
                if ($rowsPedidos[0]["direccionContactoId"]) {
                    $rowsPedidos[0]["direccionContacto"] = desgloceDireccion($rowsPedidos[0]["direccionContactoId"]);
                }
                if ($rowsPedidos[0]["estatusId"]) {
                    $rowsPedidos[0]["estatus"] = desgloceEstatus($rowsPedidos[0]["estatusId"]);
                }

                //$rowsPedidos[0]["pedidoProveedores"] = desglocePedidoProveedores($rowsPedidos[0]["id"]);

                $queryProductos = "SELECT * FROM pedido_proveedor WHERE pedido_id = ?";
                $sthProductos = $db->prepare($queryProductos);
                $sthProductos->bindParam(1, $idPedido, PDO::PARAM_INT);
                $sthProductos->execute();
                $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);

                if ($rowsProductos) {
                # code...
                for ($i = 0; $i < sizeof($rowsProductos); $i++) {
                        $rowsProductos[$i] = convertKeysToCamelCase($rowsProductos[$i]);
                        if ($rowsProductos[$i]["proveedorId"]) {
                            $rowsProductos[$i]["proveedor"] = desgloceProveedor($rowsProductos[$i]["proveedorId"]);
                        }

                        if ($rowsProductos[$i]["estatusId"]) {
                            $rowsProductos[$i]["estatus"] = desgloceEstatus($rowsProductos[$i]["estatusId"]);
                        }

                        if ($rowsProductos[$i]["pedidoId"]) {
                            $rowsProductos[$i]["pedido"] = desglocePedido($rowsProductos[$i]["pedidoId"]);
                        }
                    }

                  $rowsPedidos[0]["pedidoProveedores"] =   $rowsProductos;
                }

            }
            return $rowsPedidos[0];
        }

        function desglocePedidoProveedor($idPedido, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryProductos = "SELECT * FROM pedido_proveedor WHERE id = ?";
            $sthProductos = $db->prepare($queryProductos);
            $sthProductos->bindParam(1, $idPedido, PDO::PARAM_INT);
            $sthProductos->execute();
            $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsProductos && $rowsProductos[0]) {
                # code...
                $rowsProductos[0] = convertKeysToCamelCase($rowsProductos[0]);
                if ($rowsProductos[0]["proveedorId"]) {
                    $rowsProductos[0]["proveedor"] = desgloceProveedor($rowsProductos[0]["proveedorId"]);
                }

                if ($rowsProductos[0]["estatusId"]) {
                    $rowsProductos[0]["estatus"] = desgloceEstatus($rowsProductos[0]["estatusId"]);
                }

                if ($rowsProductos[0]["pedidoId"]) {
                    $rowsProductos[0]["pedido"] = desglocePedido($rowsProductos[0]["pedidoId"]);

                    $queryPedidoProveedores2 = "SELECT * FROM pedido_detalle WHERE pedido_proveedor_id = ?";
                    $sthPedidoProveedores2 = $db->prepare($queryPedidoProveedores2);
                    $sthPedidoProveedores2->bindParam(1, $rowsProductos[0]["pedidoId"], PDO::PARAM_INT);
                    $sthPedidoProveedores2->execute();
                    $rowsPedidosDetalles = $sthPedidoProveedores2->fetchAll(PDO::FETCH_ASSOC);

                    if ($rowsPedidosDetalles) {
                        $rowsPedidosDetalles["pedidoDetalles"] = [];
                        for ($i = 0; $i < sizeof($rowsPedidosDetalles); $i++) {
                            # code...
                            array_push($rowsPedidosDetalles["pedidoDetalles"], desglocePedidoDetalle($rowsPedidosDetalles[$i]["id"]));
                        }
                    }
                }

            }
            return $rowsProductos[0];
        }

        function desglocePedidoProveedores($idPedido, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryProductos = "SELECT * FROM pedido_proveedor WHERE pedido_id = ?";
            $sthProductos = $db->prepare($queryProductos);
            $sthProductos->bindParam(1, $idPedido, PDO::PARAM_INT);
            $sthProductos->execute();
            $rowsProductos = $sthProductos->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsProductos) {
                # code...
                for ($i = 0; $i < sizeof($rowsProductos); $i++) {
                    $rowsProductos[$i] = convertKeysToCamelCase($rowsProductos[$i]);
                    if ($rowsProductos[$i]["proveedorId"]) {
                        $rowsProductos[$i]["proveedor"] = desgloceProveedor($rowsProductos[$i]["proveedorId"]);
                    }

                    if ($rowsProductos[$i]["estatusId"]) {
                        $rowsProductos[$i]["estatus"] = desgloceEstatus($rowsProductos[$i]["estatusId"]);
                    }

                    if ($rowsProductos[$i]["pedidoId"]) {
                        $rowsProductos[$i]["pedido"] = desglocePedido($rowsProductos[$i]["pedidoId"]);
                    }
                }
            }
            return $rowsProductos;
        }

        function desglocePedidoDetalle($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryPedido = "SELECT * FROM pedido_detalle WHERE id = ?";
            $sthPedido = $db->prepare($queryPedido);
            $sthPedido->bindParam(1, $id, PDO::PARAM_INT);
            $sthPedido->execute();
            $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsPedidos && $rowsPedidos[0]) {
                $rowsPedidos[0] = convertKeysToCamelCase($rowsPedidos[0]);

                if ($rowsPedidos[0]["estatusId"]) {
                    $rowsPedidos[0]["estatus"] = desgloceEstatus($rowsPedidos[0]["estatusId"]);
                }

                if ($rowsPedidos[0]["productoProveedorId"]) {
                    $rowsPedidos[0]["productoProveedor"] = desgloceProductoProveedor($rowsPedidos[0]["productoProveedorId"]);
                }

            }
            return $rowsPedidos[0];
        }

        function desgloceInventarioByProductoProveedor($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryPedido = "SELECT * FROM inventario WHERE producto_proveedor_id = ?";
            $sthPedido = $db->prepare($queryPedido);
            $sthPedido->bindParam(1, $id, PDO::PARAM_INT);
            $sthPedido->execute();
            $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsPedidos && $rowsPedidos[0]) {
                $rowsPedidos[0] = convertKeysToCamelCase($rowsPedidos[0]);
                return $rowsPedidos[0];
            }else{
                return null;
            }

            
        }

        function desglocePedidoDetallesPlural($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queryPedido = "SELECT * FROM pedido_detalle WHERE pedido_proveedor_id = ?";
            $sthPedido = $db->prepare($queryPedido);
            $sthPedido->bindParam(1, $id, PDO::PARAM_INT);
            $sthPedido->execute();
            $rowsPedidos = $sthPedido->fetchAll(PDO::FETCH_ASSOC);

            if ($rowsPedidos) {
                for ($i = 0; $i < sizeof($rowsPedidos); $i++) {
                    # code...
                    $rowsPedidos[$i] = convertKeysToCamelCase($rowsPedidos[$i]);

                    if ($rowsPedidos[$i]["estatusId"]) {
                        $rowsPedidos[$i]["estatus"] = desgloceEstatus($rowsPedidos[$i]["estatusId"]);
                    }

                    if ($rowsPedidos[$i]["productoProveedorId"]) {
                        $rowsPedidos[$i]["productoProveedor"] = desgloceProductoProveedor($rowsPedidos[$i]["productoProveedorId"]);
                        $rowsPedidos[$i]["inventario"] = desgloceInventarioByProductoProveedor($rowsPedidos[$i]["productoProveedorId"]);
                    }
                }

            }
            return $rowsPedidos;
        }

        function imagenesProvedorProducto($id, $db)
        {
            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $q1 = "SELECT a.*
                    FROM producto_imagen pi
                    INNER JOIN adjunto a
                    ON (a.id = pi.adjunto_id)
                    WHERE pi.producto_proveedor_id = ?";
            $sth1 = $db->prepare($q1);
            $sth1->bindParam(1, $id, PDO::PARAM_INT);
            $sth1->execute();
            $rows = $sth1->fetchAll(PDO::FETCH_ASSOC);

            for ($i = 0; $i < sizeof($rows); $i++) {
                # code...
                $rows[$i]["file"] = base64_encode($rows[$i]["file"]);
            }

            if ($rows && $rows[0]) {
                return $rows;
            } else {
                return null;
            }
        }

        function desgloceProveedorByProductoProveedor($id, $db)
        {

            $dbHandler = new DbHandler();
            $db = $dbHandler->getConnection();
            $db->beginTransaction();

            $queriProveedor = "SELECT * FROM producto_proveedor WHERE id = ?";

            $sthProveedor = $db->prepare($queriProveedor);
            $sthProveedor->bindParam(1, $id, PDO::PARAM_INT);
            $sthProveedor->execute();
            $rowsProveedor = $sthProveedor->fetchAll(PDO::FETCH_ASSOC);
            $rowsProveedor[0] = convertKeysToCamelCase($rowsProveedor[0]);

            $queriProveedor = "SELECT * FROM proveedor WHERE id = ?";

            $sthProveedor = $db->prepare($queriProveedor);
            $sthProveedor->bindParam(1, $rowsProveedor[0]["proveedorId"], PDO::PARAM_INT);
            $sthProveedor->execute();
            $rowsProveedor = $sthProveedor->fetchAll(PDO::FETCH_ASSOC);
            $rowsProveedor[0] = convertKeysToCamelCase($rowsProveedor[0]);

            return $rowsProveedor[0];
        }

        /*********************** USEFULL FUNCTIONS **************************************/

        function convertKeysToCamelCase($array)
        {
            $result = [];

            array_walk_recursive($array, function ($value, &$key) use (&$result) {
                $newKey = preg_replace_callback('/_([a-z])/', function ($matches) {
                    return strtoupper($matches[1]);
                }, $key);

                $result[$newKey] = $value;
            });

            return $result;
        }

        /**
         * Verificando los parametros requeridos en el metodo o endpoint
         */
        function verifyRequiredParams($required_fields)
        {
            $error = false;
            $error_fields = "";
            $request_params = array();
            $request_params = $_REQUEST;
            // Handling PUT request params
            if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
                $app = \Slim\Slim::getInstance();
                parse_str($app->request()->getBody(), $request_params);
            }
            foreach ($required_fields as $field) {
                if (!isset($request_params[$field]) || strlen(trim($request_params[$field])) <= 0) {
                    $error = true;
                    $error_fields .= $field . ', ';
                }
            }

            if ($error) {
                // Required field(s) are missing or empty
                // echo error json and stop the app
                $response = array();
                $app = \Slim\Slim::getInstance();

                $response["status"] = "I";
                $response["description"] = 'Campo(s) Requerido(s) ' . substr($error_fields, 0, -2) . '';
                $response["idTransaction"] = time();
                $response["parameters"] = [];
                $response["timeRequest"] = date("Y-m-d H:i:s");

                echoResponse(400, $response);

                $app->stop();
            }
        }

        /**
         * Validando parametro email si necesario; un Extra ;)
         */
        function validateEmail($email)
        {
            $app = \Slim\Slim::getInstance();
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response["error"] = true;
                $response["message"] = 'Email address is not valid';
                echoResponse(400, $response);

                $app->stop();
            }
        }

        /**
         * Mostrando la respuesta en formato json al cliente o navegador
         * @param String $status_code Http response code
         * @param Int $response Json response
         */
        function echoResponse($status_code, $response)
        {
            $app = \Slim\Slim::getInstance();
            // Http response code
            $app->status($status_code);

            // setting response content type to json
            $app->contentType('application/json');

            echo json_encode($response);
        }

        /**
         * Agregando un leyer intermedio e autenticación para uno o todos los metodos, usar segun necesidad
         * Revisa si la consulta contiene un Header "Authorization" para validar
         */
        function authenticate(\Slim\Route $route)
        {
            // Getting request headers
            $headers = apache_request_headers();
            $response = array();
            $app = \Slim\Slim::getInstance();

            // Verifying Authorization Header
            if (isset($headers['Authorization'])) {
                //$db = new DbHandler(); //utilizar para manejar autenticacion contra base de datos

                // get the api key
                $token = $headers['Authorization'];

                // validating api key
                if (!($token == API_KEY)) { //API_KEY declarada en Config.php

                    // api key is not present in users table
                    $response["error"] = true;
                    $response["message"] = "Acceso denegado. Token inválido";
                    echoResponse(401, $response);

                    $app->stop(); //Detenemos la ejecución del programa al no validar

                } else {
                    //procede utilizar el recurso o metodo del llamado
                }
            } else {
                // api key is missing in header
                $response["error"] = true;
                $response["message"] = "Falta token de autorización";
                echoResponse(400, $response);

                $app->stop();
            }
        }

        function addCustomer($customerDetailsAry)
        {
            $customer = new Customer();

            $customerDetails = $customer->create($customerDetailsAry);

            return $customerDetails;
        }

        /*
         *Función para encriptar contraseñas
         */
        function dec_enc($action, $string)
        {
            $output = false;

            $encrypt_method = "AES-256-CBC";
            $secret_key = 'luegoluegoSharkitTemporal2020';
            $secret_iv = 'luegoluegoSharkitTemporal2020';

            // hash
            $key = hash('sha256', $secret_key);

            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hash('sha256', $secret_iv), 0, 16);

            if ($action == 'encrypt') {
                $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
                $output = base64_encode($output);
            } else if ($action == 'decrypt') {
                $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
            }

            return $output;
        }

        /**
         * Decrypt data from a CryptoJS json encoding string
         *
         * @param mixed $passphrase
         * @param mixed $jsonString
         * @return mixed
         */

        function returnD($data)
        {
            return cryptoJsAesDecrypt('RG5vt457u%$5bj78c452YBBc24432c%#T7&$tv657bu6B&BvH76hvv64', $data);
        }

        function cryptoJsAesDecrypt($passphrase, $jsonString)
        {
            $jsondata = json_decode($jsonString, true);
            $salt = hex2bin($jsondata["s"]);
            $ct = base64_decode($jsondata["ct"]);
            $iv = hex2bin($jsondata["iv"]);
            $concatedPassphrase = $passphrase . $salt;
            $md5 = array();
            $md5[0] = md5($concatedPassphrase, true);
            $result = $md5[0];
            for ($i = 1; $i < 3; $i++) {
                $md5[$i] = md5($md5[$i - 1] . $concatedPassphrase, true);
                $result .= $md5[$i];
            }
            $key = substr($result, 0, 32);
            $data = openssl_decrypt($ct, 'aes-256-cbc', $key, true, $iv);
            return json_decode($data, true);
        }

        function better_crypt($input, $rounds = 7)
        {
            $salt = "";
            $salt_chars = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));
            for ($i = 0; $i < 22; $i++) {
                $salt .= $salt_chars[array_rand($salt_chars)];
            }
            return crypt($input, sprintf('$2a$%02d$', $rounds) . $salt);
        }
        /**
         * Encrypt value to a cryptojs compatiable json encoding string
         *
         * @param mixed $passphrase
         * @param mixed $value
         * @return string
         */
        function cryptoJsAesEncrypt($passphrase, $value)
        {
            $salt = openssl_random_pseudo_bytes(8);
            $salted = '';
            $dx = '';
            while (strlen($salted) < 48) {
                $dx = md5($dx . $passphrase . $salt, true);
                $salted .= $dx;
            }
            $key = substr($salted, 0, 32);
            $iv = substr($salted, 32, 16);
            $encrypted_data = openssl_encrypt(json_encode($value), 'aes-256-cbc', $key, true, $iv);
            $data = array("ct" => base64_encode($encrypted_data), "iv" => bin2hex($iv), "s" => bin2hex($salt));
            return json_encode($data);
        }

        function camelize($input, $separator = '_')
        {
            return str_replace($separator, '', ucwords($input, $separator));
        }

        function generateRandomToken($length = 10)
        {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
            return $randomString;
        }

        function chargeAmountFromCard($cardDetails, $dataInfo)
        {
            $customerDetailsAry = array(
                'email' => $cardDetails['email'],
                'source' => $cardDetails['token'],
            );

            //DEV
            $llave = returnD('{"ct":"KJ2Fku8wHihENlpoMgPVau/6v+/rk3X0aD/nyy8eUC8dt5htcvPWm9EsXN30FZmL","iv":"3ff0461775a79daf6828e667bf7d4bb8","s":"a1c3765da3aeb58c"}');
            //PROD
            //$llave = returnD('{"ct":"HNcigf4VjPrRFFPbfJyrHOTHi6WAh5ivwPNRND+ljzA8kVPldnxbOXXgNvecCc/D","iv":"05bbf0c316fc1668265feec58c158dd9","s":"474eeb53d400b2e4"}');

            \Stripe\Stripe::setApiKey($llave);
            $cardDetailsAry = array(
                'source' => $cardDetails['token'],
                'amount' => intval($dataInfo['amount']) * 100,
                'currency' => $dataInfo['currency_code'],
                'description' => $dataInfo['item_name'],
                'metadata' => array(
                    'order_id' => $dataInfo['item_number'],
                ),
            );

            $charges = \Stripe\Charge::create($cardDetailsAry);

            return $charges;
        }
