<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;
use Phalcon\Http\Response;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

//Defino las variables principales de conexion
$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host, "port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);


$app->post('/autenticacion_autorizacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Cabecera y respuesta
        $response = new Response();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/json');
        $response->setHeaders($headers);

        //Retorno el array
        $array_return = array();

        //Consulto el usuario por username del parametro get        
        $usuario_validar = Usuarios::findFirst("UPPER(username) = '" . strtoupper($this->request->getPost('username')) . "'");

        //Valido si existe el usuario
        if (isset($usuario_validar->id)) {

            //Consulto perfil del usuario
            $perfil = Usuariosperfiles::findFirst("usuario = " . $usuario_validar->id . " AND perfil=29");

            //Valido si existe el perfil asociado
            if (isset($perfil->id)) {
                //Valido si la clave es igual al token del usuario
                if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {

                    //Validar que solo tenga un token para consultar
                    $sql = "
                        SELECT 
                            * 
                        FROM Tokens                            
                        WHERE UPPER(json_extract_path_text(user_current,'username')) = '" . strtoupper($this->request->getPost('username')) . "'";

                    $total_tokens = $app->modelsManager->executeQuery($sql);
                    if (count($total_tokens) > 0) {

                        $array_return["error"] = 5;
                        $array_return["respuesta"] = "Ya cuenta con token de consulta";

                        //Set value return
                        $response->setContent(json_encode($array_return));

                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Ya cuenta con token de consulta en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                        $logger->close();
                        return $response;
                    } else {
                        //Fecha actual
                        $fecha_actual = date("Y-m-d H:i:s");

                        //Consulto y elimino todos los tokens que ya no se encuentren vigentes
                        $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
                        $tokens_eliminar->delete();

                        //Creo el token de acceso para el usuario solicitado, con vigencia diaria hasta la media noche
                        $tokens = new Tokens();
                        $tokens->token = $this->security->hash($usuario_validar->id . "-" . $usuario_validar->tipo_documento . "-" . $usuario_validar->numero_documento);
                        $tokens->user_current = json_encode($usuario_validar);
                        $tokens->date_create = $fecha_actual;
                        $tokens->date_limit = date("Y-m-d") . " 23:59:59";
                        $tokens->save();

                        $array_return["error"] = 0;
                        $array_return["respuesta"] = $tokens->token;

                        //Set value return
                        $response->setContent(json_encode($array_return));

                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se realiza la consulta con éxito en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                        $logger->close();
                        return $response;
                    }
                } else {
                    $array_return["error"] = 4;
                    $array_return["respuesta"] = "La contraseña no es correcta";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"La contraseña no es correcta en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                    $logger->close();
                    return $response;
                }
            } else {
                $array_return["error"] = 3;
                $array_return["respuesta"] = "El usuario no cuenta con el perfil de WS";

                //Set value return
                $response->setContent(json_encode($array_return));

                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El usuario no cuenta con el perfil de WS en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                $logger->close();
                return $response;
            }
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El usuario no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no es correcto en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método autenticacion_autorizacion.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

//convocatorias_publicadas_preview
$app->post('/convocatorias_publicadas_preview', function () use ($app, $config, $logger) {

    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //Retorno el array
    $array_return = array();

    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            //de esta forma recibo parametros
            $limit = $this->request->getPost('cantidad');


            //estado 5 para solo traer los registro de las convocatorias publicadas y tipo_evento 25 para ordenar por la fecha de publicación
            $sql = "SELECT convocatoriaspublicas.id, convocatoriaspublicas.convocatoria, Estados.nombre as estado, Entidades.nombre as entidad, convocatoriaspublicas.anio, Convocatoriascronogramas.fecha_inicio FROM Viewconvocatoriaspublicas convocatoriaspublicas
                    left join Convocatoriascronogramas on Convocatoriascronogramas.convocatoria = convocatoriaspublicas.id
                    left join Estados on convocatoriaspublicas.estado = Estados.id 
                    left join Entidades on convocatoriaspublicas.entidad = Entidades.id
                    where estado = 5 and Convocatoriascronogramas.tipo_evento = 25
                    ORDER BY Convocatoriascronogramas.fecha_inicio DESC LIMIT " . $limit;

            $array = $app->modelsManager->executeQuery($sql);

            $array_return["error"] = 0;

            foreach ($array as $convocatoria) {
                $array_return["respuesta"][] = [
                    "id" => $convocatoria['id'],
                    "convocatoria" => $convocatoria['convocatoria'],
                    "estado" => $convocatoria['estado'],
                    "entidad" => $convocatoria['entidad'],
                    "anio" => $convocatoria['anio'],
                ];
            }

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la consulta con éxito en el controlador intercambio información en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método convocatorias_publicadas_preview.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

//convocatorias publicadas
$app->post('/convocatorias_publicadas', function () use ($app, $config, $logger) {

    //buscar como poner limite al numero de registros que devuelve
    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //array de respuesta
    $array_return = array();

    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            //de esta forma recibo parametros
            $nombre = $this->request->getPost('nombre');
            $anio = $this->request->getPost('anio');
            $tipo_programa = $this->request->getPost('tipo_programa');
            $entidades = $this->request->getPost('entidad');
            $area = $this->request->getPost('area');
            $linea_estrategica = $this->request->getPost('linea_estrategica');
            $enfoque = $this->request->getPost('enfoque');
            $estados = $this->request->getPost('estado');
            $modalidad = $this->request->getPost('modalidad');
            $perfiles = $this->request->getPost('perfil');

            //parametros de orden
            $orden_nombre = $this->request->getPost('orden_nombre');
            $orden_fecha = $this->request->getPost('orden_fecha');

            //offset es desde donde comienza
            $offset = $this->request->getPost('offset');
            //limit es el número de registros que se devuelven
            $limit = $this->request->getPost('limit');

            $where = '';
            if (isset($nombre)) {
                $where = " LOWER(convocatorias.convocatoria) like LOWER('%" . $nombre . "%') ";
            }

            if (isset($anio)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.anio = " . $anio;
                } else {
                    $where .= " convocatorias.anio = " . $anio;
                }
            }

            if (isset($tipo_programa)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.programa = " . $tipo_programa;
                } else {
                    $where .= " convocatorias.programa = " . $tipo_programa;
                }
            }

            if (isset($entidades)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.entidad in (" . implode(", ", $entidades) . ")";
                } else {
                    $where .= " convocatorias.entidad in (" . implode(", ", $entidades) . ")";
                }
            }

            if (isset($area)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.area = " . $area;
                } else {
                    $where .= " convocatorias.area = " . $area;
                }
            }

            if (isset($linea_estrategica)) {
                if (!empty($where)) {
                    $where .= " and linea_estrategica = " . $linea_estrategica;
                } else {
                    $where .= " WHERE linea_estrategica = " . $linea_estrategica;
                }
            }

            if (isset($enfoque)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.enfoque = " . $enfoque;
                } else {
                    $where .= " convocatorias.enfoque = " . $enfoque;
                }
            }

            if (isset($estados)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.estado in (" . implode(", ", $estados) . ")";
                } else {
                    $where .= " convocatorias.estado in (" . implode(", ", $estados) . ")";
                }
            }

            if (isset($modalidad)) {
                if (!empty($where)) {
                    $where .= " and convocatorias.modalidad = " . $modalidad;
                } else {
                    $where .= " convocatorias.modalidad = " . $modalidad;
                }
            }

            if (isset($perfiles)) {
                if (!empty($where)) {
                    $where .= " and c2.tipo_participante in (" . implode(", ", $perfiles) . ")";
                } else {
                    $where .= " c2.tipo_participante in (" . implode(", ", $perfiles) . ")";
                }
            }

            //se agregan los parametros de ordenamiento
            $orderby = '';
            if (isset($orden_nombre)) {
                $orderby .= " order by convocatorias.convocatoria";
            }

            if (isset($orden_fecha)) {
                if (!empty($orderby)) {
                    $orderby .= ", c.fecha_actualizacion";
                } else {
                    $orderby .= " order by c.fecha_actualizacion";
                }
            }

            //se agregan los parametros de limit y offset
            $rango = '';
            if (isset($limit) and isset($offset)) {
                $rango .= " LIMIT " . $limit . " OFFSET " . $offset;
            }

            $sql = "SELECT distinct convocatorias.id, e.nombre as estado, e2.nombre as entidad, convocatorias.anio, convocatorias.convocatoria as nombre, p.nombre as tipo_programa, convocatorias.categoria, a.nombre as area, e3.nombre as enfoque, l.nombre as linea_estrategica, c.fecha_actualizacion FROM Viewconvocatoriaspublicas convocatorias 
                    left join Convocatoriascronogramas c on c.convocatoria = convocatorias.id 
                    left join Estados e on e.id = convocatorias.estado 
                    left join Entidades e2 on e2.id = convocatorias.entidad 
                    left join Programas p on p.id = convocatorias.programa 
                    left join Enfoques e3 on e3.id = convocatorias.enfoque
                    left join Areas a on a.id = convocatorias.area 
                    left join Lineasestrategicas l on l.id = convocatorias.linea_estrategica 
                    left join Convocatoriasparticipantes c2 on c2.convocatoria = convocatorias.id "
                    . "WHERE c.tipo_evento = 25 and " . $where . $orderby . " " . $rango;

            $sql_total_registros = "SELECT count(*) as total FROM Viewconvocatoriaspublicas convocatorias "
                    . "left join Convocatoriascronogramas c on c.convocatoria = convocatorias.id "
                    . "left join Convocatoriasparticipantes c2 on c2.convocatoria = convocatorias.id "
                    . "WHERE c.tipo_evento = 25 and " . $where;

            $convocatorias = $app->modelsManager->executeQuery($sql);
            $total_registros = $app->modelsManager->executeQuery($sql_total_registros);


            $array_return["error"] = 0;
            $array_return["total_registros"] = $total_registros[0]->total;

            $respuesta = array();

            $j = 0;
            foreach ($convocatorias as $convocatoria) {

                $sql_cronogramas = "select t2.nombre as tipo_evento, c.fecha_inicio, c.descripcion from Convocatoriascronogramas c 
                left join Tiposeventos t2 on t2.id = c.tipo_evento 
                where c.convocatoria = " . $convocatoria->id . " and c.active 
                order by fecha_inicio ASC";

                $cronograma = $app->modelsManager->executeQuery($sql_cronogramas);

                $respuesta[$j]['id'] = $convocatoria->id;
                $respuesta[$j]['estado'] = $convocatoria->estado;
                $respuesta[$j]['entidad'] = $convocatoria->entidad;
                $respuesta[$j]['anio'] = $convocatoria->anio;
                $respuesta[$j]['nombre'] = $convocatoria->nombre;
                $respuesta[$j]['tipo_programa'] = $convocatoria->tipo_programa;
                $respuesta[$j]['categoria'] = $convocatoria->categoria;
                $respuesta[$j]['area'] = $convocatoria->area;
                $respuesta[$j]['enfoque'] = $convocatoria->enfoque;
                $respuesta[$j]['linea_estrategica'] = $convocatoria->linea_estrategica;
                $respuesta[$j]['cronograma'] = $cronograma;

                $j++;
            }

            $array_return["error"] = 0;

            $array_return["respuesta"] = $respuesta;

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la conculta con éxito en el controlador Intercambio información en el método total_propuestas_barrios"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método convocatorias_publicadas"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método convocatorias_publicadas." . $ex->getMessage();

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método convocatorias_publicadas"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

$app->post('/liberar_token', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Cabecera y respuesta
        $response = new Response();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/json');
        $response->setHeaders($headers);

        //Retorno el array
        $array_return = array();

        //Consulto el usuario por username del parametro get        
        $usuario_validar = Usuarios::findFirst("UPPER(username) = '" . strtoupper($this->request->getPost('username')) . "'");

        //Valido si existe el usuario
        if (isset($usuario_validar->id)) {

            //Consulto perfil del usuario
            $perfil = Usuariosperfiles::findFirst("usuario = " . $usuario_validar->id . " AND perfil=29");

            //Valido si existe el perfil asociado
            if (isset($perfil->id)) {
                //Valido si la clave es igual al token del usuario
                if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {

                    //Elimino todos los tokens relacionados con el usuario de consulta ws
                    $sql = "
                        DELETE FROM Tokens
                        WHERE UPPER(json_extract_path_text(user_current,'username')) = '" . strtoupper($this->request->getPost('username')) . "'";

                    $app->modelsManager->executeQuery($sql);

                    $array_return["error"] = 0;
                    $array_return["respuesta"] = "Token liberado";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Se libera el token éxito en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                    $logger->close();
                    return $response;
                } else {
                    $array_return["error"] = 4;
                    $array_return["respuesta"] = "La contraseña no es correcta";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"La contraseña no es correcta en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                    $logger->close();
                    return $response;
                }
            } else {
                $array_return["error"] = 3;
                $array_return["respuesta"] = "El usuario no cuenta con el perfil de WS";

                //Set value return
                $response->setContent(json_encode($array_return));

                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El usuario no cuenta con el perfil de WS en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                $logger->close();
                return $response;
            }
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El usuario no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no es correcto en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método liberar_token.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);


//Metodo que permite consultar toda la convocatoria con el fin de publicarla
$app->post('/convocatoria/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            //Si existe consulto la convocatoria y creo el objeto
            $convocatoria = Convocatorias::findFirst($id);
            $array_convocatoria["id_programa"] = $convocatoria->programa;
            $array_convocatoria["programa"] = $convocatoria->getProgramas()->nombre;
            $array_convocatoria["convocatoria"] = $convocatoria->nombre;
            $array_convocatoria["entidad"] = $convocatoria->getEntidades()->nombre;
            $array_convocatoria["descripcion"] = $convocatoria->descripcion;
            $array_convocatoria["estado"] = $convocatoria->getEstados()->nombre;
            $array_convocatoria["linea"] = $convocatoria->getLineasestrategicas()->nombre;
            $array_convocatoria["area"] = $convocatoria->getAreas()->nombre;
            $array_convocatoria["tiene_categorias"] = $convocatoria->tiene_categorias;
            $array_convocatoria["diferentes_categorias"] = $convocatoria->diferentes_categorias;
            $array_convocatoria["numero_estimulos"] = $convocatoria->numero_estimulos;
            $array_convocatoria["valor_total_estimulos"] = "$ " . number_format($convocatoria->valor_total_estimulos, 0, '', '.');
            $array_convocatoria["bolsa_concursable"] = $convocatoria->bolsa_concursable;
            $array_convocatoria["descripcion_bolsa"] = $convocatoria->descripcion_bolsa;
            $array_convocatoria["objeto"] = $convocatoria->objeto;
            $array_convocatoria["no_pueden_participar"] = $convocatoria->no_pueden_participar;
            $array_convocatoria["derechos_ganadores"] = $convocatoria->derechos_ganadores;
            $array_convocatoria["deberes_ganadores"] = $convocatoria->deberes_ganadores;

            //generar las siglas del programa
            if ($convocatoria->programa == 1) {
                $siglas_programa = "pde";
            }
            if ($convocatoria->programa == 2) {
                $siglas_programa = "pdac";
            }
            if ($convocatoria->programa == 3) {
                $siglas_programa = "pdsc";
            }

            $condiciones_participancion = Tablasmaestras::findFirst("active=true AND nombre='condiciones_participacion_" . $siglas_programa . "_" . date("Y") . "'");

            //Valido si la convocatoria cuenta con información de
            //condiciones especificas
            if ($convocatoria->link_condiciones != "") {
                $array_convocatoria["condiciones_participacion"] = $convocatoria->link_condiciones;
            } else {
                $array_convocatoria["condiciones_participacion"] = $condiciones_participancion->valor;
            }


            $tipo_convocatoria = "";

            //Valido que la convocatorias no tenga categorias            
            if ($convocatoria->tiene_categorias == false) {
                $tipo_convocatoria = "general";
                //Verifico si el bolsa y su dritribucion
                if ($convocatoria->bolsa_concursable) {
                    //Si es Dinero
                    if ($convocatoria->tipo_estimulo == 1) {
                        $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                    'tipo_recurso = :tipo_recurso:',
                                    'bind' => [
                                        'tipo_recurso' => 'Bolsa'
                                    ],
                                    'order' => 'orden ASC',
                        ]));
                    }

                    //Si es especie
                    if ($convocatoria->tipo_estimulo == 2) {
                        $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                    'tipo_recurso = :tipo_recurso:',
                                    'bind' => [
                                        'tipo_recurso' => 'Especie'
                                    ],
                                    'order' => 'orden ASC',
                        ]));
                    }

                    //Si es mixta
                    if ($convocatoria->tipo_estimulo == 3) {
                        $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos());
                    }
                }

                //creo los listado de la convocatoria general
                $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
                $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                $conditions = ['convocatoria' => $id, 'active' => true];
                $listados = Convocatoriasanexos::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                            'bind' => $conditions,
                            'order' => 'orden ASC',
                ]));


                //Se crea todo el array de las rondas de evaluacion
                $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                            'bind' => $conditions,
                ]));

                foreach ($consulta_rondas_evaluacion as $ronda) {
                    $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                    $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Ronda:</b> " . $ronda->nombre_ronda;
                    $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                    $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                    [
                                        "convocatoria_ronda = " . $ronda->id . " AND active=true",
                                        "order" => 'orden'
                                    ]
                    );
                }
            } else {
                //Array para consultar las convocatorias generales
                $conditions = ['convocatoria_padre_categoria' => $id, 'active' => true];
                $categorias = Convocatorias::find([
                            'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                            'bind' => $conditions,
                            "order" => 'orden',
                ]);

                //Creo el in de categorias
                $array_categorias = "";
                foreach ($categorias as $categoria) {
                    $array_categorias = $array_categorias . $categoria->id . ",";
                }
                $array_categorias = $array_categorias . $id;

                //Valido que la convocatorias tenga categorias generales
                if ($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == false) {

                    $tipo_convocatoria = "general";

                    //Se crea todo el array de las rondas de evaluacion
                    foreach ($categorias as $categoria) {
                        $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                        $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                    'bind' => $conditions,
                        ]));

                        foreach ($consulta_rondas_evaluacion as $ronda) {
                            $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                            $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre . " <br/><b>Ronda:</b> " . $ronda->nombre_ronda;
                            $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                            $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                            [
                                                "convocatoria_ronda = " . $ronda->id . " AND active=true",
                                                "order" => 'orden'
                                            ]
                            );
                        }
                    }

                    //Se crea todo el array de las listados por categorias
                    foreach ($categorias as $categoria) {
                        //consulto los tipos anexos listados
                        $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
                        $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                        $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                        $consulta_listados = Convocatoriasanexos::find(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                                    'bind' => $conditions,
                                    'order' => 'orden ASC',
                        ]));

                        foreach ($consulta_listados as $listado) {
                            $listados[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                            $listados[$categoria->orden]["listados"][] = $listado;
                        }
                    }
                } else {
                    //Valido que la convocatorias tenga categorias especiales            
                    if ($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == true) {
                        $tipo_convocatoria = "especial";
                        if ($convocatoria->diferentes_categorias) {

                            //Recorro todas las categorias especiales
                            foreach ($categorias as $categoria) {

                                //Creo el array del estimulo
                                $categorias_estimulos[$categoria->id]["categoria"] = $categoria->nombre;
                                $categorias_estimulos[$categoria->id]["numero_estimulos"] = $categoria->numero_estimulos;
                                $categorias_estimulos[$categoria->id]["valor_total_estimulos"] = "$ " . number_format($categoria->valor_total_estimulos, 0, '', '.');
                                $categorias_estimulos[$categoria->id]["bolsa_concursable"] = $categoria->bolsa_concursable;
                                $categorias_estimulos[$categoria->id]["descripcion_bolsa"] = $categoria->descripcion_bolsa;
                                //Verifico si el bolsa y su dritribucion
                                if ($categoria->bolsa_concursable) {
                                    //Si es Dinero
                                    if ($categoria->tipo_estimulo == 1) {
                                        $categorias_estimulos[$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos([
                                                    'tipo_recurso = :tipo_recurso:',
                                                    'bind' => [
                                                        'tipo_recurso' => 'Bolsa'
                                                    ],
                                                    'order' => 'orden ASC',
                                        ]));
                                    }

                                    //Si es especie
                                    if ($categoria->tipo_estimulo == 2) {
                                        $categorias_estimulos[$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos([
                                                    'tipo_recurso = :tipo_recurso:',
                                                    'bind' => [
                                                        'tipo_recurso' => 'Especie'
                                                    ],
                                                    'order' => 'orden ASC',
                                        ]));
                                    }

                                    //Si es mixta
                                    if ($categoria->tipo_estimulo == 3) {
                                        $categorias_estimulos[$categoria->id]["numero_estimulos"] = count($categoria->getConvocatoriasrecursos());
                                    }
                                }

                                //Consulto el cronograma por categoria
                                $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                                $consulta_cronogramas = Convocatoriascronogramas::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                            'bind' => $conditions,
                                            'order' => 'fecha_inicio ASC',
                                ]));

                                //Creo el cronograma de las categorias especiales
                                $i = 0;
                                foreach ($consulta_cronogramas as $evento) {
                                    if ($evento->getTiposeventos()->publico) {
                                        $cronogramas[$categoria->id]["categoria"] = $categoria->nombre;
                                        $cronogramas[$categoria->id]["eventos"][$i]["tipo_evento"] = $evento->getTiposeventos()->nombre;
                                        if ($evento->getTiposeventos()->periodo) {
                                            $cronogramas[$categoria->id]["eventos"][$i]["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y');
                                        } else {
                                            if ($evento->tipo_evento == 12) {
                                                $cronogramas[$categoria->id]["eventos"][$i]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y H:i:s');
                                            } else {
                                                $cronogramas[$categoria->id]["eventos"][$i]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y');
                                            }
                                        }
                                        $cronogramas[$categoria->id]["eventos"][$i]["descripcion"] = $evento->descripcion;
                                        $cronogramas[$categoria->id]["eventos"][$i]["convocatoria"] = $categoria->id;
                                        $i++;
                                    }
                                }

                                //Se crea todo el array de participantes por convocatoria
                                $consulta_participantes = Convocatoriasparticipantes::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                                            'bind' => $conditions,
                                ]));
                                foreach ($consulta_participantes as $participante) {
                                    $participantes[$categoria->id]["categoria"] = $categoria->nombre;
                                    $participantes[$categoria->id]["participantes"][$participante->id]["participante"] = $participante->getTiposParticipantes()->nombre;
                                    $participantes[$categoria->id]["participantes"][$participante->id]["descripcion"] = $participante->descripcion_perfil;
                                }

                                //consulto los tipos anexos listados
                                $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
                                $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                                $consulta_listados = Convocatoriasanexos::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                                            'bind' => $conditions,
                                            'order' => 'orden ASC',
                                ]));
                                foreach ($consulta_listados as $listado) {
                                    $listados[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                                    $listados[$categoria->orden]["listados"][] = $listado;
                                }

                                //consulto los tipos anexos avisos
                                $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='avisos'");
                                $tipo_documento_avisos = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                                $consulta_avisos = Convocatoriasanexos::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_avisos . ')',
                                            'bind' => $conditions,
                                            'order' => 'orden ASC',
                                ]));
                                foreach ($consulta_avisos as $listado) {
                                    $avisos[$categoria->orden]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre;
                                    $avisos[$categoria->orden]["avisos"][] = $listado;
                                }

                                //Se crea todo el array de documentos administrativos y tecnicos
                                $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                            'bind' => $conditions,
                                            'order' => 'orden ASC',
                                ]));
                                foreach ($consulta_documentos_administrativos as $documento) {
                                    if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                                        $documentos_administrativos[$categoria->id]["categoria"] = $categoria->nombre;
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["descripcion"] = $documento->descripcion;
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["orden"] = $documento->orden;
                                        $documentos_administrativos[$categoria->id]["administrativos"][$documento->orden]["convocatoria"] = $id;
                                    }

                                    if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                                        $documentos_tecnicos[$categoria->id]["categoria"] = $categoria->nombre;
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["descripcion"] = $documento->descripcion;
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["orden"] = $documento->orden;
                                        $documentos_tecnicos[$categoria->id]["administrativos"][$documento->orden]["convocatoria"] = $id;
                                    }
                                }

                                //Se crea todo el array de las rondas de evaluacion
                                $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                            'bind' => $conditions,
                                ]));

                                foreach ($consulta_rondas_evaluacion as $ronda) {
                                    $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                                    $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Categoría:</b> " . $categoria->nombre . " <br/><b>Ronda:</b> " . $ronda->nombre_ronda;
                                    $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                                    $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                                    [
                                                        "convocatoria_ronda = " . $ronda->id . " AND active=true",
                                                        "order" => 'orden'
                                                    ]
                                    );
                                }
                            }
                        }
                    }
                }
            }


            if ($tipo_convocatoria == "general") {
                //Se crea todo el array del cronograma de actividades de la convocatoria simple            
                $conditions = ['convocatoria' => $id, 'active' => true];
                $consulta_cronogramas = Convocatoriascronogramas::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                            'bind' => $conditions,
                            'order' => 'fecha_inicio ASC',
                ]));
                $i = 0;
                foreach ($consulta_cronogramas as $evento) {
                    if ($evento->getTiposeventos()->publico) {
                        $cronogramas[$i]["tipo_evento"] = $evento->getTiposeventos()->nombre;
                        if ($evento->getTiposeventos()->periodo) {
                            $cronogramas[$i]["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y');
                        } else {
                            if ($evento->tipo_evento == 12) {
                                $cronogramas[$i]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y H:i:s');
                            } else {
                                $cronogramas[$i]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y');
                            }
                        }
                        $cronogramas[$i]["descripcion"] = $evento->descripcion;
//                        $cronogramas[$i]["convocatoria"] = $id;
                        $i++;
                    }
                }

                //Se crea todo el array de participantes
                $conditions = ['convocatoria' => $id, 'active' => true];
                $consulta_participantes = Convocatoriasparticipantes::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                            'bind' => $conditions,
                ]));
                foreach ($consulta_participantes as $participante) {

                    $participantes[] = [
                        "participante" => $participante->getTiposParticipantes()->nombre,
                        "descripcion" => $participante->descripcion_perfil
                    ];


//                    $participantes[]["participante"] = $participante->getTiposParticipantes()->nombre;
//                    $participantes[]["descripcion"] = $participante->descripcion_perfil;
//                    $participantes[$participante->id]["convocatoria"] = $id;
                }

                //Se crea todo el array de documentos administrativos y tecnicos
                $conditions = ['convocatoria' => $id, 'active' => true];
                $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                            'bind' => $conditions,
                            'order' => 'orden ASC',
                ]));
                foreach ($consulta_documentos_administrativos as $documento) {
                    if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                        $documentos_administrativos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                        $documentos_administrativos[$documento->orden]["descripcion"] = $documento->descripcion;
                        $documentos_administrativos[$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                        $documentos_administrativos[$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                        $documentos_administrativos[$documento->orden]["orden"] = $documento->orden;
//                        $documentos_administrativos[$documento->orden]["convocatoria"] = $id;
                    }

                    if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                        $documentos_tecnicos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                        $documentos_tecnicos[$documento->orden]["descripcion"] = $documento->descripcion;
                        $documentos_tecnicos[$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                        $documentos_tecnicos[$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                        $documentos_tecnicos[$documento->orden]["orden"] = $documento->orden;
//                        $documentos_tecnicos[$documento->orden]["convocatoria"] = $id;
                    }
                }


                //consulto los tipos anexos avisos
                $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='avisos'");
                $tipo_documento_avisos = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
                $conditions = ['convocatoria' => $id, 'active' => true];
                $avisos = Convocatoriasanexos::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_avisos . ')',
                            'bind' => $conditions,
                            'order' => 'orden ASC',
                ]));
            }

            //consulto los tipos anexos documentacion, aplica para las convocatorias sencillas, categorias generales y especiales
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='documentacion'");
            $tipo_documento_documentacion = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
//            $documentacion = Convocatoriasanexos::find(([
//                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_documentacion . ')',
//                        'bind' => $conditions,
//                        'order' => 'orden ASC',
//            ]));

            $resoluciones = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento = "Resolución"',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));

            $formatos = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento = "Formato"',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));

            $anexos = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento = "Anexo"',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));

            //Creo todos los array del registro
            $respuesta["error"] = 0;

            // $array["informacion_basica"] = $array_convocatoria;
            $array["informacion_basica"] = [
                "nombre" => $array_convocatoria["convocatoria"],
                "descripcion" => $array_convocatoria["descripcion"],
                "linea_estrategica" => $array_convocatoria["linea"],
                "area" => $array_convocatoria["area"],
                "estado" => $array_convocatoria["estado"],
                "tipo_programa" => $array_convocatoria["programa"],
                "entidad" => $array_convocatoria["entidad"],
                    //"total_recursos" => $array_convocatoria["valor_total_estimulos"],
                    //"descripcion_general_recursos_a_otorgar" => $array_convocatoria["descripcion_bolsa"]
            ];
            $array["cronograma"] = $cronogramas;
            $array["objeto"] = $array_convocatoria['objeto'];
            $array["tipo_de_participante"] = $participantes;
            $array["quienes_no_pueden_participar"] = $array_convocatoria["no_pueden_participar"];
            $array["documentos_administrativos"] = $documentos_administrativos;
            $array["documentos_tecnicos"] = $documentos_tecnicos;
            $array["criterios_evaluacion"] = $rondas_evaluacion;
            $array["derechos_especificos_ganadores"] = $array_convocatoria["derechos_ganadores"];
            $array["deberes_especificos_ganadores"] = $array_convocatoria["deberes_ganadores"];
            if (!$convocatoria->diferentes_categorias) {
                $array["distribucion_estimulos"] = [
//                    $convocatoria->id => [
//                        "estimulos" => [
                    $convocatoria->id => [
                        "numero_estimulos" => $convocatoria->numero_estimulos,
                        "valor_total_estimulos" => "$ " . number_format($convocatoria->valor_total_estimulos, 0, '', '.'),
                        "bolsa_concursable" => $convocatoria->bolsa_concursable,
                        "descripcion_bolsa" => $convocatoria->descripcion_bolsa
                    ]
//                        ]
//                    ]
                ];
            } else {
                $array["distribucion_estimulos"] = $categorias_estimulos;
            }


            //documentos
            $array["anexos"] = $anexos;
            $array["formatos"] = $formatos;
            $array["resoluciones"] = $resoluciones;
            $array["listados"] = $listados;
            $array["avisos_modificatorios"] = $avisos;

            $respuesta["respuesta"] = $array;

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message": en el controlador DrupalWS en el método search"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();

            //Retorno el array
            echo json_encode($respuesta);
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método liberar_token.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método search"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
});

//cierre_convocatoria
$app->post('/cierre_convocatoria', function () use ($app, $config, $logger) {

    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //Retorno el array
    $array_return = array();

    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            //de esta forma recibo el parametro fecha
            $fecha = $this->request->getPost('fecha');
            $fechaComoEntero = strtotime($fecha);
            $anio = date("Y", $fechaComoEntero);
            $mes = date("m", $fechaComoEntero);
            $dia = date("d", $fechaComoEntero);

            $fechaTruncada = $anio . "-" . $mes . "-" . $dia;

            $sql = "SELECT convocatoriaspublicas.id, convocatoriaspublicas.convocatoria, Estados.nombre as estado, Entidades.nombre as entidad, convocatoriaspublicas.anio, Convocatoriascronogramas.fecha_fin FROM Viewconvocatoriaspublicas convocatoriaspublicas
                    left join Convocatoriascronogramas on Convocatoriascronogramas.convocatoria = convocatoriaspublicas.id_diferente
                    left join Estados on convocatoriaspublicas.estado = Estados.id 
                    left join Entidades on convocatoriaspublicas.entidad = Entidades.id
                    where estado = 5 and Convocatoriascronogramas.tipo_evento = 12 and Convocatoriascronogramas.fecha_fin > '" . $fecha . "' and date_trunc('day', Convocatoriascronogramas.fecha_fin) = '" . $fechaTruncada . "'";

            $array = $app->modelsManager->executeQuery($sql);

            $array_return["error"] = 0;
            foreach ($array as $convocatoria) {
                $array_return["respuesta"][] = [
                    "id" => $convocatoria['id'],
                    "convocatoria" => $convocatoria['convocatoria'],
                    "estado" => $convocatoria['estado'],
                    "entidad" => $convocatoria['entidad'],
                    "anio" => $convocatoria['anio'],
                ];
            }

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la consulta con éxito en el controlador intercambio información en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método cierre_convocatoria.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

//cierre_convocatoria_mes
$app->post('/cierre_convocatoria_mes', function () use ($app, $config, $logger) {

    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //Retorno el array
    $array_return = array();

    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            //de esta forma recibo el parametro fecha
            $fecha = $this->request->getPost('fecha');
            $fechaComoEntero = strtotime($fecha);
            $anio = date("Y", $fechaComoEntero);
            $mes = date("m", $fechaComoEntero);
            //$dia = date("d", $fechaComoEntero);
            //$fechaTruncada = $anio . "-" . $mes . "-" . $dia;

            $primerDiaSiguienteMes = date("Y-m-d", strtotime($anio . "-" . $mes . "-01" . "+ 1 month"));

            $sql = "SELECT distinct convocatoriaspublicas.id, convocatoriaspublicas.convocatoria, Estados.nombre as estado, Entidades.nombre as entidad, convocatoriaspublicas.anio, Convocatoriascronogramas.fecha_fin as fecha_fin FROM Viewconvocatoriaspublicas convocatoriaspublicas
                    left join Convocatoriascronogramas on Convocatoriascronogramas.convocatoria = convocatoriaspublicas.id_diferente
                    left join Estados on convocatoriaspublicas.estado = Estados.id 
                    left join Entidades on convocatoriaspublicas.entidad = Entidades.id
                    where estado = 5 and Convocatoriascronogramas.tipo_evento = 12 and fecha_fin > '" . $fecha .
                    "' and date_trunc('day', fecha_fin) < '" . $primerDiaSiguienteMes . "'";

            $array = $app->modelsManager->executeQuery($sql);

            $array_return["error"] = 0;
            foreach ($array as $convocatoria) {
                $array_return["respuesta"][] = [
                    "id" => $convocatoria['id'],
                    "convocatoria" => $convocatoria['convocatoria'],
                    "estado" => $convocatoria['estado'],
                    "entidad" => $convocatoria['entidad'],
                    "anio" => $convocatoria['anio'],
                    "fecha_cierre" => $convocatoria['fecha_fin']
                ];
            }

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la consulta con éxito en el controlador intercambio información en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método cierre_convocatoria.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método convocatorias_publicadas_preview"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

$app->post('/download_file', function () use ($app, $config, $logger) {

    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    //$headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        if (isset($token_validar->id)) {
            //Instancio los objetos que se van a manejar
            $request = new Request();
            $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

            echo $chemistry_alfresco->download($request->getPost('cod'));
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método download_file"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método cierre_convocatoria.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método download_file"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);

$app->post('/datos_cifras', function () use ($app, $config, $logger) {

    //buscar como poner limite al numero de registros que devuelve
    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //Retorno el array
    $array_return = array();

    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {


            //de esta forma recibo parametros
            $anio = $this->request->getPost('anio');
            $tipo_programa = $this->request->getPost('tipo_programa');
            $entidades = $this->request->getPost('entidad');
            $tipos_graficas = $this->request->getPost('tipo_grafica');

            //Inicio el where
            $where = "vwc.anio=" . date("Y");

            //Valido el anio
            if ($anio != "") {
                $where = "vwc.anio=" . $anio;
            }

            //Valido el programa
            if ($tipo_programa != "") {
                $where = "vwc.programa=" . $tipo_programa;
            }

            //Valido las entidades
            $in_entidades = "";
            foreach ($entidades as $entidad) {
                $in_entidades = $in_entidades . $entidad . ",";
            }
            $in_entidades = trim($in_entidades, ",");

            //Reemplazo la consulta del programa
            $where2 = str_replace("vwc.programa", "vwp.programa", $where);

            //Creo las graficas para las respuestas
            $respuesta = array();

            //(tabla_estados_convocatoria_anio) Convocatorias publicadas
            if (in_array("tabla_estados_convocatoria_anio", $tipos_graficas)) {
                $sql_propuestas = "
                    SELECT 
                        vwc.nombre_entidad AS label,
                        count(vwc.id) AS total_propuestas
                    FROM 
                        Viewconvocatoriascifras AS vwc
                    WHERE
                        " . $where . " AND vwc.estado IN (5,6,32,43,45) AND vwc.entidad IN (" . $in_entidades . ") AND UPPER(vwc.convocatoria) NOT LIKE '%JURADOS%' 
                    GROUP BY 1
                    ORDER BY 2 DESC
                    ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["tabla_estados_convocatoria_anio"] = $convocatorias_anio;
            }

            //(tabla_convocatoria_propuestas_anio) Estado de convocatorias
            if (in_array("tabla_convocatoria_propuestas_anio", $tipos_graficas)) {

                $sql_propuestas = "
                    SELECT 
                        vwc.estado_convocatoria AS label,
                        count(vwc.id) AS total_propuestas
                    FROM 
                        Viewconvocatoriascifras AS vwc
                    WHERE
                        " . $where . " AND vwc.estado IN (5,6,32,43,45) AND vwc.entidad IN (" . $in_entidades . ") AND UPPER(vwc.convocatoria) NOT LIKE '%JURADOS%' 
                    GROUP BY 1
                    ORDER BY 2 DESC
                    ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["tabla_convocatoria_propuestas_anio"] = $convocatorias_anio;
            }

            //(tabla_propuestas_entidad_anio) Estado de convocatorias
            if (in_array("tabla_propuestas_entidad_anio", $tipos_graficas)) {

                $sql_propuestas = "
                    SELECT 
                            vwc.nombre_entidad AS label,
                            COUNT(vwc.id_propuesta) AS total_propuestas
                    FROM 
                            Viewpropuestas AS vwc 
                    INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                    WHERE 
                            " . $where2 . " AND vwp.estado IN (5,6,32,43,45) AND vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                    GROUP BY 1
                    ORDER BY 2
                    ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["tabla_propuestas_entidad_anio"] = $convocatorias_anio;
            }

            //(tabla_propuestas_rango_etareo_anio) Rango etario del representante
            if (in_array("tabla_propuestas_rango_etareo_anio", $tipos_graficas)) {


                $sql_propuestas = "
                SELECT
                    vwc.rango AS label,
                    SUM(vwc.count) AS total_propuestas
                FROM Viewrangosetareos AS vwc
                WHERE " . $where . " AND vwc.entidad IN (" . $in_entidades . ")
                GROUP BY 1
                ORDER BY 2            
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["tabla_propuestas_rango_etareo_anio"] = $convocatorias_anio;
            }

            //(table_propuestas_area_anio) Propuestas Inscritas por área
            if (in_array("table_propuestas_area_anio", $tipos_graficas)) {

                $sql_propuestas = "
                SELECT 
                        vwc.area AS label,
                        COUNT(vwc.id_propuesta) AS total_propuestas
                FROM 
                        Viewpropuestas AS vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                WHERE 
                        " . $where2 . " and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                GROUP BY 1
                ORDER BY 2
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_propuestas_area_anio"] = $convocatorias_anio;
            }

            //(table_propuestas_lineaestrategica_anio) Propuestas Inscritas por línea estratégica
            if (in_array("table_propuestas_lineaestrategica_anio", $tipos_graficas)) {

                $sql_propuestas = "
                SELECT 
                        vwc.lineaestrategica AS label,
                        COUNT(vwc.id_propuesta) AS total_propuestas
                FROM 
                        Viewpropuestas AS vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                WHERE 
                        " . $where2 . " and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%'
                GROUP BY 1
                ORDER BY 2
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_propuestas_lineaestrategica_anio"] = $convocatorias_anio;
            }

            //(table_propuestas_enfoque_anio) Propuestas Inscritas por enfoque
            if (in_array("table_propuestas_enfoque_anio", $tipos_graficas)) {

                $sql_propuestas = "
                SELECT 
                        vwc.enfoque AS label,
                        COUNT(vwc.id_propuesta) AS total_propuestas
                FROM 
                        Viewpropuestas AS vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                WHERE 
                        " . $where2 . " and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                GROUP BY 1
                ORDER BY 2
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_propuestas_enfoque_anio"] = $convocatorias_anio;
            }

            //(table_propuestas_tipoparticipante_anio) Propuestas Inscritas por tipo de participante
            if (in_array("table_propuestas_tipoparticipante_anio", $tipos_graficas)) {

                $sql_propuestas = "
                SELECT 
                        per.nombre AS label,
                        COUNT(vwc.id_propuesta) as total_propuestas
                FROM 
                        Viewpropuestas AS vwc
                INNER JOIN Propuestas AS p ON p.id=vwc.id_propuesta
                INNER JOIN Participantes AS par ON par.id=p.participante
                INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
                INNER JOIN Perfiles AS per ON per.id=up.perfil	
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                WHERE 
                        " . $where2 . " and vwc.id_estado NOT IN (7,20) AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                GROUP BY 1
                ORDER BY 2
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_propuestas_tipoparticipante_anio"] = $convocatorias_anio;
            }

            //(table_propuestas_localidadeje_anio) Propuestas Inscritas por localidad de ejecución
            if (in_array("table_propuestas_localidadeje_anio", $tipos_graficas)) {

                $sql_propuestas = "
                select 
                        vwc.localidad_ejecucion_propuesta as label,
                        COUNT(vwc.id_convocatoria) as total_propuestas
                from 
                        Viewpropuestas as vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                where 
                        " . $where2 . " and vwc.id_estado NOT IN (7,20)  AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                group by 1
                ORDER BY 2
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_propuestas_localidadeje_anio"] = $convocatorias_anio;
            }

            //(table_valor_localidadeje_anio) recursos adjudicados por localidad
            if (in_array("table_valor_localidadeje_anio", $tipos_graficas)) {

                $sql_propuestas = "
                select 
                        vwc.localidad_ejecucion_propuesta as label,
                        count(vwc.id_propuesta) as total_entidad,
                        sum(vwc.monto_asignado) as total_propuestas
                from 
                        Viewpropuestas as vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                where 
                        " . $where2 . " and vwc.estado_propuesta IN ('Ganadora') and vwc.localidad_ejecucion_propuesta is not null  AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                group by 1
                order by 3 ASC
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_valor_localidadeje_anio"] = $convocatorias_anio;
            }

            //(table_valor_eje_tipo_participante) recursos adjudicados por tipo de participante
            if (in_array("table_valor_eje_tipo_participante", $tipos_graficas)) {

                $sql_propuestas = "
                select 
                        per.nombre as label,
                        count(vwc.id_propuesta) as total_entidad,
                        sum(vwc.monto_asignado) as total_propuestas
                from 
                        Viewpropuestas as vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita
                INNER JOIN Propuestas AS p ON p.id=vwc.id_propuesta
                INNER JOIN Participantes AS par ON par.id=p.participante
                INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil
                INNER JOIN Perfiles AS per ON per.id=up.perfil
                where 
                        " . $where2 . " and vwc.estado_propuesta IN ('Ganadora') and vwc.localidad_ejecucion_propuesta is not null  AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                group by 1
                order by 3 ASC
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_valor_eje_tipo_participante"] = $convocatorias_anio;
            }

            //(table_valor_eje_area) recursos adjudicados por area
            if (in_array("table_valor_eje_area", $tipos_graficas)) {

                $sql_propuestas = "
                select 
                        a.nombre as label,
                        count(vwc.id_propuesta) as total_entidad,
                        sum(vwc.monto_asignado) as total_propuestas
                from 
                        Viewpropuestas as vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita
                INNER JOIN Areas AS a ON a.id=vwp.area
                where 
                        " . $where2 . " and vwc.estado_propuesta IN ('Ganadora') and vwc.localidad_ejecucion_propuesta is not null  AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                group by 1
                order by 3 ASC
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_valor_eje_area"] = $convocatorias_anio;
            }

            //(table_valor_entidadeje_anio) recursos adjudicados por entidad
            if (in_array("table_valor_entidadeje_anio", $tipos_graficas)) {

                $sql_propuestas = "
                select 
                        vwc.nombre_entidad as label,
                        count(vwc.id_propuesta) as total_entidad,
                        sum(vwc.monto_asignado) as total_propuestas
                from 
                        Viewpropuestas as vwc 
                INNER JOIN Viewconvocatorias AS vwp ON vwp.id_categoria=vwc.id_convocatoria_propuesta_inscrita               
                where 
                        " . $where2 . " and vwc.estado_propuesta IN ('Ganadora') AND vwc.id_entidad IN (" . $in_entidades . ") AND UPPER(vwp.convocatoria) NOT LIKE '%JURADOS%' 
                group by 1
                order by 3 ASC
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_valor_entidadeje_anio"] = $convocatorias_anio;
            }

            //(table_valor_ofertado_entidad) recursos asignados por entidad
            if (in_array("table_valor_ofertado_entidad", $tipos_graficas)) {


                $sql_propuestas = "
                select 
                        vwc.nombre_entidad as label,
                        SUM(vwc.sum) as total_propuestas
                from 
                        Viewofertado as vwc 
                where 
                        " . $where . " AND vwc.id_entidad IN (" . $in_entidades . ")
                GROUP BY 1
                order by 2 ASC
                ";

                $convocatorias_anio = $app->modelsManager->executeQuery($sql_propuestas);

                //Agrego la respuesta de la tabla
                $respuesta[]["table_valor_ofertado_entidad"] = $convocatorias_anio;
            }

            $array_return["error"] = 0;

            $array_return["respuesta"] = $respuesta;

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la conculta con éxito en el controlador DrupalWS en el método datos_cifras"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método datos_cifras"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método datos_cifras." . $ex->getMessage();

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"' . $ex->getMessage() . ' en el controlador DrupalWS en el método datos_cifras"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
        $logger->close();
        return $response;
    }
}
);



try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>