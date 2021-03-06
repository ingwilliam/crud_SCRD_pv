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

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

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

// Recupera todos los registros para cargar la grilla de las convocatorias
$app->get('/busqueda_convocatorias', function () use ($app, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa cargar grilla de convocatorias"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'c.anio',
                1 => 'e.nombre',
                2 => 'a.nombre',
                3 => 'l.nombre',
                4 => 'en.nombre',
                5 => 'c.nombre',
                6 => 'c.descripcion',
                7 => 'p.nombre',
                8 => 'es.nombre',
                9 => 'c.orden',
            );

            //Inicio el where de convocatorias
            $where_convocatorias .= " INNER JOIN Entidades AS e ON e.id=c.entidad";
            $where_convocatorias .= " INNER JOIN Programas AS p ON p.id=c.programa";
            $where_convocatorias .= " LEFT JOIN Areas AS a ON a.id=c.area";
            $where_convocatorias .= " LEFT JOIN Lineasestrategicas AS l ON l.id=c.linea_estrategica";
            $where_convocatorias .= " LEFT JOIN Enfoques AS en ON en.id=c.enfoque";
            $where_convocatorias .= " INNER JOIN Estados AS es ON es.id=c.estado";
            $where_convocatorias .= " LEFT JOIN Convocatorias AS cpad ON cpad.id=c.convocatoria_padre_categoria";
            //$where_convocatorias .= " WHERE es.id IN (5, 6) AND c.active IN (true) ";
            $where_convocatorias .= " WHERE es.id IN (5) AND c.active IN (true) ";


            //Condiciones para la consulta del select del buscador principal
            $estado_actual = "";
            
            $array_json_param = json_decode($request->get("params"));
        
            //Valido que exita un año
            if($array_json_param->anio=="")
            {
               $array_json_param->anio=date("Y"); 
            }
            
            if (!empty($request->get("params"))) {
                foreach ($array_json_param AS $clave => $valor) {
                    if ($clave == "nombre" && $valor != "") {
                        //$where_convocatorias .= " AND ( UPPER(c.nombre) LIKE '%" . strtoupper($valor) . "%' ";
                        //$where_convocatorias .= " OR UPPER(cpad.nombre) LIKE '%" . strtoupper($valor) . "%' )";

                        $where_convocatorias .= " AND ( UPPER(TRANSLATE(c.nombre,'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')) LIKE TRANSLATE(UPPER('%" . $valor . "%'),'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun') ";
                        $where_convocatorias .= " OR UPPER(TRANSLATE(cpad.nombre,'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun')) LIKE TRANSLATE(UPPER('%" . $valor . "%'),'ÁÉÍÓÚÑáéíóúñ','AEIOUNaeioun') )";
                    }

                    if ($valor != "" && $clave != "nombre" && $clave != "estado") {
                        $where_convocatorias = $where_convocatorias . " AND c." . $clave . " = " . $valor;
                    }

                    if ($clave == "estado") {
                        $estado_actual = $valor;
                    }
                }
            }

            //Duplico el where de convocatorias para categorias
            $where_categorias = $where_convocatorias;

            //Creo los WHERE especificios
            $where_convocatorias .= " AND c.convocatoria_padre_categoria IS NULL AND c.tiene_categorias=FALSE";
            $where_categorias .= " AND c.convocatoria_padre_categoria IS NOT NULL AND c.tiene_categorias=TRUE ";

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatorias AS c";

            $sqlTotEstado = "SELECT c.estado,count(c.id) as total FROM Convocatorias AS c";

            $sqlConvocatorias = "SELECT "
                    . "" . $columns[0] . " ,"
                    . "" . $columns[1] . " AS entidad,"
                    . "" . $columns[2] . " AS area,"
                    . "" . $columns[3] . " AS linea_estrategica,"
                    . "" . $columns[4] . " AS enfoque,"
                    . "" . $columns[5] . " AS convocatoria , "
                    . "" . $columns[6] . ","
                    . "" . $columns[7] . " AS programa ,"
                    . "" . $columns[8] . " AS estado ,"
                    . "" . $columns[9] . " ,"
                    . "cpad.nombre AS categoria ,"
                    . "c.tiene_categorias ,"
                    . "c.diferentes_categorias ,"
                    . "cpad.id AS idd ,"
                    . "c.id ,"
                    . "c.modalidad ,"
                    . "c.estado AS id_estado ,"
                    . "concat('<button type=\"button\" class=\"btn btn-warning cargar_cronograma\" data-toggle=\"modal\" data-target=\"#ver_cronograma\" title=\"',c.id,'\"><span class=\"glyphicon glyphicon-calendar\"></span></button>') as ver_cronograma,"
                    . "concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_tipo_convocatoria(',c.modalidad,',',c.id,')\"><span class=\"glyphicon glyphicon-new-window\"></span></button>') as ver_convocatoria FROM Convocatorias AS c";

            $sqlCategorias = "SELECT "
                    . "" . $columns[0] . " ,"
                    . "" . $columns[1] . " AS entidad,"
                    . "" . $columns[2] . " AS area,"
                    . "" . $columns[3] . " AS linea_estrategica,"
                    . "" . $columns[4] . " AS enfoque,"
                    . "" . $columns[5] . " AS categoria , "
                    . "" . $columns[6] . ","
                    . "" . $columns[7] . " AS programa ,"
                    . "" . $columns[8] . " AS estado ,"
                    . "" . $columns[9] . " ,"
                    . "cpad.nombre AS convocatoria ,"
                    . "c.tiene_categorias ,"
                    . "c.diferentes_categorias ,"
                    . "cpad.id AS idd ,"
                    . "c.id ,"
                    . "c.modalidad ,"
                    . "c.estado AS id_estado ,"
                    . "concat('<button type=\"button\" class=\"btn btn-warning cargar_cronograma\" data-toggle=\"modal\" data-target=\"#ver_cronograma\" title=\"',c.id,'\"><span class=\"glyphicon glyphicon-calendar\"></span></button>') as ver_cronograma,"
                    . "concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_tipo_convocatoria(',c.modalidad,',',c.id,')\"><span class=\"glyphicon glyphicon-new-window\"></span></button>') as ver_convocatoria "
                    . "FROM Convocatorias AS c";


            //concatenate search sql if value exist
            if (isset($where_convocatorias) && $where_convocatorias != '') {

                $sqlTot .= $where_convocatorias;
                $sqlTotEstado .= $where_convocatorias;
                $sqlConvocatorias .= $where_convocatorias;
                $sqlCategorias .= $where_categorias;
            }

            //Concateno el orden y el limit para el paginador
            $sqlConvocatorias .= " ORDER BY c.estado  DESC  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            $sqlCategorias .= " ORDER BY c.estado  DESC  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

            //Concateno el group by de estados
            $sqlTotEstado .= " GROUP BY 1";

            //Ejecutamos los sql de convocatorias y de categorias
            $array_convocatorias = $app->modelsManager->executeQuery($sqlConvocatorias);
            $array_categorias = $app->modelsManager->executeQuery($sqlCategorias);

            //ejecuto el total de registros actual
            $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

            $json_convocatorias = array();
            foreach ($array_convocatorias AS $clave => $valor) {

                $cargar_array = TRUE;

                if ($valor->modalidad == 7) {
                    $usuario_convocatoria = Usuariosconvocatorias::findFirst("username='" . $user_current["username"] . "' AND convocatoria=" . $valor->id . " AND active=true");
                    $cargar_array = FALSE;
                    if (isset($usuario_convocatoria->id)) {
                        $cargar_array = TRUE;
                    }
                }

                //Permite cargar el array a mostrar de las convocatorias
                if ($cargar_array == TRUE) {
                    $valor->estado_convocatoria = "<span class=\"span_C" . $valor->estado . "\">" . $valor->estado . "</span>";
                    if ($valor->tiene_categorias == false) {
                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 12 AND active=true");
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $valor->id_estado = 52;
                            $valor->estado_convocatoria = "<span class=\"span_CCerrada\">Cerrada</span>";
                        } else {
                            $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 11 AND active=true");
                            $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                            if ($fecha_actual < $fecha_apertura) {
                                $valor->estado_convocatoria = "<span class=\"span_CPublicada\">Publicada</span>";
                            } else {
                                $valor->id_estado = 51;
                                $valor->estado_convocatoria = "<span class=\"span_CAbierta\">Abierta</span>";
                            }
                        }
                    }

                    //Realizo el filtro de estados
                    if ($estado_actual == "") {
                        $json_convocatorias[] = $valor;
                    } else {
                        if ($estado_actual == $valor->id_estado) {
                            $json_convocatorias[] = $valor;
                        }
                    }
                }
            }

            foreach ($array_categorias AS $clave => $valor) {
                $cargar_array = TRUE;

                if ($valor->modalidad == 7) {
                    $usuario_convocatoria = Usuariosconvocatorias::findFirst("username='" . $user_current["username"] . "' AND convocatoria=" . $valor->id . " AND active=true");
                    $cargar_array = FALSE;
                    if (isset($usuario_convocatoria->id)) {
                        $cargar_array = TRUE;
                    }
                }

                //Permite cargar el array a mostrar de las convocatorias
                if ($cargar_array == TRUE) {
                    $valor->estado_convocatoria = "<span class=\"span_C" . $valor->estado . "\">" . $valor->estado . "</span>";
                    if ($valor->tiene_categorias == true && $valor->diferentes_categorias == true) {
                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 12 AND active=true");
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $valor->id_estado = 52;
                            $valor->estado_convocatoria = "<span class=\"span_CCerrada\">Cerrada</span>";
                        } else {
                            $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->id . " AND tipo_evento = 11 AND active=true");
                            $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                            if ($fecha_actual < $fecha_apertura) {
                                $valor->estado_convocatoria = "<span class=\"span_CPublicada\">Publicada</span>";
                            } else {
                                $valor->id_estado = 51;
                                $valor->estado_convocatoria = "<span class=\"span_CAbierta\">Abierta</span>";
                            }
                        }
                    }

                    if ($valor->tiene_categorias == true && $valor->diferentes_categorias == false) {
                        $valor->ver_cronograma = "<button type=\"button\" class=\"btn btn-warning cargar_cronograma\" data-toggle=\"modal\" data-target=\"#ver_cronograma\" title=\"" . $valor->idd . "\"><span class=\"glyphicon glyphicon-calendar\"></span></button>";
                        //$valor->ver_convocatoria = "<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_tipo_convocatoria('".$valor->modalidad."','".$valor->idd."')\"><span class=\"glyphicon glyphicon-new-window\"></span></button>";                                        

                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->idd . " AND tipo_evento = 12 AND active=true");
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $valor->id_estado = 52;
                            $valor->estado_convocatoria = "<span class=\"span_CCerrada\">Cerrada</span>";
                        } else {
                            $fecha_apertura_real = Convocatoriascronogramas::findFirst("convocatoria=" . $valor->idd . " AND tipo_evento = 11 AND active=true");
                            $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                            if ($fecha_actual < $fecha_apertura) {
                                $valor->estado_convocatoria = "<span class=\"span_CPublicada\">Publicada</span>";
                            } else {
                                $valor->id_estado = 51;
                                $valor->estado_convocatoria = "<span class=\"span_CAbierta\">Abierta</span>";
                            }
                        }
                    }


                    //Realizo el filtro de estados
                    if ($estado_actual == "") {
                        $json_convocatorias[] = $valor;
                    } else {
                        if ($estado_actual == $valor->id_estado) {
                            $json_convocatorias[] = $valor;
                        }
                    }
                }
            }

            array_sort_by($json_convocatorias, 'convocatoria', $order = SORT_ASC);
            
            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($totalRecords["total"]),
                "recordsFiltered" => intval($totalRecords["total"]),
                "dataEstados" => $app->modelsManager->executeQuery($sqlTotEstado),
                "data" => $json_convocatorias   // total data array
            );

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorna grilla de convocatorias"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();

            //retorno el array en json
            echo json_encode($json_data);
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();

            echo json_encode("error_token");
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo busqueda_convocatorias ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Crea el formulario de busqueda para la convocatorias
$app->get('/formulario_convocatorias', function () use ($app, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a buscar convocatorias"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            $array = array();
            for ($i = date("Y"); $i >= 2016; $i--) {
                $array["anios"][] = $i;
            }
            $array["entidades"] = Entidades::find("active = true");
            $array["areas"] = Areas::find("active = true");
            $array["lineas_estrategicas"] = Lineasestrategicas::find("active = true");
            $array["programas"] = Programas::find("active = true");
            $array["enfoques"] = Enfoques::find("active = true");
            $array["modalidades"] = Modalidades::find("active = true");
            $array["estados"] = Estados::find(
                            array(
                                "tipo_estado = 'convocatorias' AND active = true AND id IN (5,6)",
                                "order" => "orden"
                            )
            );

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorna formulario de busqueda de las convocatorias"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();

            echo json_encode($array);
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo formulario_convocatorias ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Cargar cronograma de cada convocatoria
$app->post('/cargar_cronograma/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a cargar cronograma de la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Consulto el cronograma de la convocatoria
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_cronogramas = Convocatoriascronogramas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                        'bind' => $conditions,
                        'order' => 'fecha_inicio',
            ]));

            //Creo el cronograma
            foreach ($consulta_cronogramas as $evento) {
                //Solo cargo los eventos publicos
                if ($evento->getTiposeventos()->publico) {
                    $array_evento = array();
                    $array_evento["tipo_evento"] = $evento->getTiposeventos()->nombre;
                    if ($evento->getTiposeventos()->periodo) {
                        $array_evento["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y h:i:s a');
                    } else {
                        $array_evento["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a');
                    }
                    $array_evento["descripcion"] = $evento->descripcion;
                    $cronogramas[] = $array_evento;
                }
            }

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorna cronograma de la concovatoria"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo json_encode($cronogramas);
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_cronograma ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Valida el acceso a la convocatoria
//Que este antes de la fecha de cierre
//Confirmar el total de posibles numero de propuesta inscritas por la convocatoria
//Verificar que no tenga mas de 2 estimulos ganados
$app->post('/validar_acceso/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPost('token'));

    //Validar array del usuario
    $user_current = json_decode($token_actual->user_current, true);

    try {

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //consulto si la propuesta se debe habilutar por medio
            //soporte tecnico, solo se activa si la propuesta
            //esta en estado Guardada - No Inscrita o Anulada
            $conditions = ['id' => $request->getPost('p'), 'active' => true];
            $propuesta_habilitada = Propuestas::findFirst(([
                        'conditions' => 'id=:id: AND active=:active: AND estado IN (7,20) AND habilitar=TRUE AND NOW() BETWEEN habilitar_fecha_inicio AND habilitar_fecha_fin',
                        'bind' => $conditions,
            ]));

            if (isset($propuesta_habilitada->id)) {
                //Registro la accion en el log de convocatorias                                            
                $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Selecciono la convocatoria (' . $id . '), donde se habilito para realizar la inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "ingresar";
            } else {
                //Consulto la propuesta solicitada
                //Para determinar si mostramos los datos o
                //Mostramos el formulario inactivo
                $conditions = ['id' => $request->getPost('p'), 'active' => true];
                $propuesta_formulario = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active: AND estado IN (8,20,21,22,23,24,31,33,34,44)',
                            'bind' => $conditions,
                ]));

                if (isset($propuesta_formulario->id)) {
                    echo "ingresar";
                } else {
                    //Consulto la convocatoria solicitada
                    $convocatoria_solicitada = Convocatorias::findFirst($id);

                    //Valido cuantas propuestas estan permitidas por convocatoria                   
                    $propuestas_permitidas = $convocatoria_solicitada->propuestas_permitidas;

                    //Valido la modalidad de la convocatoria
                    $modalidad_convocatoria = $convocatoria_solicitada->modalidad;

                    //Tipo de consulta la solicitada o la padre
                    $consultar_solicitada = true;

                    //Realizo el in para las propuestas con las categorias
                    $in_convocatorias = $id;

                    if ($convocatoria_solicitada->convocatoria_padre_categoria != null) {
                        //Consulto la convocatoria padre
                        $convocatoria_padre = $convocatoria_solicitada->getConvocatorias();

                        //Seteo el numero de propuestas y la modalidad
                        //Valido cuantas propuestas estan permitidas por convocatoria                   
                        $propuestas_permitidas = $convocatoria_solicitada->getConvocatorias()->propuestas_permitidas;
                        //Valido la modalidad de la convocatoria
                        $modalidad_convocatoria = $convocatoria_solicitada->getConvocatorias()->modalidad;

                        //Si la convocatoria seleccionada es categoria 
                        //y no es especial invierto los id                    
                        if ($convocatoria_padre->tiene_categorias == true && $convocatoria_padre->diferentes_categorias == false) {
                            $consultar_solicitada = false;
                            $id = $convocatoria_solicitada->getConvocatorias()->id;
                        }

                        //Consulto todas las convocatorias hijas
                        $convocatorias_hijas = Convocatorias::find("convocatoria_padre_categoria=" . $convocatoria_padre->id . "");
                        $in_convocatorias = "";
                        foreach ($convocatorias_hijas as $convocatoria_hija) {
                            $in_convocatorias = $in_convocatorias . $convocatoria_hija->id . ",";
                        }
                        $in_convocatorias = substr($in_convocatorias, 0, -1);
                    }

                    //Valido si el total de propuestas permitidas es null
                    if ($propuestas_permitidas == null) {
                        $propuestas_permitidas = 1;
                    }

                    //Consulto la fecha de cierre del cronograma de la convocatoria                
                    if ($consultar_solicitada) {
                        $conditions = ['convocatoria' => $convocatoria_solicitada->id, 'active' => true, 'tipo_evento' => 12];
                    } else {
                        $conditions = ['convocatoria' => $convocatoria_padre->id, 'active' => true, 'tipo_evento' => 12];
                    }
                    $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                                'bind' => $conditions,
                    ]));

                    //Genero los datetime con el fin de comparar la
                    //Fecha de cierre con la fecha actual actual
                    $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                    $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());

                    if ($fecha_actual > $fecha_cierre) {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, La convocatoria (' . $id . ') esta cerrada, la fecha de cierre es (' . $fecha_cierre_real->fecha_fin . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_fecha_cierre";
                    } else {
                        //Consulto la fecha de apertura del cronograma de la convocatoria                    
                        if ($consultar_solicitada) {
                            $conditions = ['convocatoria' => $convocatoria_solicitada->id, 'active' => true, 'tipo_evento' => 11];
                        } else {
                            $conditions = ['convocatoria' => $convocatoria_padre->id, 'active' => true, 'tipo_evento' => 11];
                        }
                        $fecha_apertura_real = Convocatoriascronogramas::findFirst(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                                    'bind' => $conditions,
                        ]));

                        //Genero los datetime con el fin de comparar la
                        //Fecha de apertura con la fecha actual actual
                        $fecha_apertura = strtotime($fecha_apertura_real->fecha_fin, time());
                        if ($fecha_actual < $fecha_apertura) {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, La convocatoria (' . $id . ') no esta abierta, la fecha de apertura es (' . $fecha_apertura_real->fecha_fin . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_fecha_apertura";
                        } else {
                            //Solo puede ingresar si la modalidad es de jurados
                            if ($modalidad_convocatoria == 2) {
                                echo "ingresar";
                            } else {
                                //Valido que envien id de la propuesta
                                //se valida que sea numerico                            
                                if (is_numeric($request->getPost('p'))) {
                                    if ($request->getPost('p') == 0) {

                                        //Consulto los perfiles del usuario
                                        $usuario_perfiles = Usuariosperfiles::find("usuario=" . $user_current["id"] . "");
                                        $array_usuarios_perfiles = "";
                                        foreach ($usuario_perfiles as $perfil) {
                                            $array_usuarios_perfiles = $array_usuarios_perfiles . $perfil->id . ",";
                                        }
                                        $array_usuarios_perfiles = substr($array_usuarios_perfiles, 0, -1);

                                        //Consulto los participantes del usuario
                                        $participantes = Participantes::find("usuario_perfil IN (" . $array_usuarios_perfiles . ") AND tipo='Participante'");
                                        $array_participantes = "";
                                        foreach ($participantes as $participante) {
                                            $array_participantes = $array_participantes . $participante->id . ",";
                                        }
                                        $array_participantes = substr($array_participantes, 0, -1);

                                        //Valido si el participante cuenta con un perfil para iniciar
                                        //El proceso de inscripcion
                                        if ($array_participantes == "") {
                                            //Registro la accion en el log de convocatorias                                        
                                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, No ha creado el perfil con el cual desea participar en la convocatoria (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                            $logger->close();
                                            echo "error_participante";
                                        } else {
                                            //Consulto las propuestas de los participantes
                                            //con el estado Registrada, Inscrita,Por Subsanar, Subsanación Recibida, Rechazada, Habilitada
                                            $propuestas = Propuestas::find("participante IN (" . $array_participantes . ") AND convocatoria IN (" . $in_convocatorias . ") AND estado IN (7,8,21,22,23,24,31,33,34,44)");
                                            if (count($propuestas) < $propuestas_permitidas) {
                                                //Registro la accion en el log de convocatorias                                            
                                                $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Selecciono la convocatoria (' . $id . ') en la validacion del proceso de inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                                $logger->close();
                                                echo "ingresar";
                                            } else {
                                                //Registro la accion en el log de convocatorias                                            
                                                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Supera el máximo permitido de propuestas de la convocatoria (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                                $logger->close();
                                                echo "error_maximo";
                                            }
                                        }
                                    } else {
                                        //Consulto la propuesta solicitada
                                        $conditions = ['id' => $request->getPost('p'), 'active' => true];
                                        $propuesta = Propuestas::findFirst(([
                                                    'conditions' => 'id=:id: AND active=:active:',
                                                    'bind' => $conditions,
                                        ]));

                                        if (isset($propuesta->id)) {
                                            //Registro la accion en el log de convocatorias                                        
                                            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Selecciono la convocatoria (' . $id . ') en la validacion del proceso de inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                            $logger->close();
                                            echo "ingresar";
                                        } else {
                                            //Registro la accion en el log de convocatorias                                        
                                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, El código de la propuesta no es el correcto de la convocatoria (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                            $logger->close();
                                            echo "error_propuesta";
                                        }
                                    }
                                } else {

                                    //Consulto los perfiles del usuario
                                    $usuario_perfiles = Usuariosperfiles::find("usuario=" . $user_current["id"] . "");
                                    $array_usuarios_perfiles = "";
                                    foreach ($usuario_perfiles as $perfil) {
                                        $array_usuarios_perfiles = $array_usuarios_perfiles . $perfil->id . ",";
                                    }
                                    $array_usuarios_perfiles = substr($array_usuarios_perfiles, 0, -1);

                                    //Consulto los participantes del usuario
                                    $participantes = Participantes::find("usuario_perfil IN (" . $array_usuarios_perfiles . ") AND tipo='Participante'");
                                    $array_participantes = "";
                                    foreach ($participantes as $participante) {
                                        $array_participantes = $array_participantes . $participante->id . ",";
                                    }
                                    $array_participantes = substr($array_participantes, 0, -1);

                                    //Valido si puede ingresar a terminos y condiciones de participación
                                    if ($array_participantes == "") {
                                        //Registro la accion en el log de convocatorias                                    
                                        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Selecciono la convocatoria (' . $id . ') en el fomulario de busqueda"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                        $logger->close();
                                        echo "ingresar";
                                    } else {
                                        //Consulto las propuestas de los participantes
                                        //con el estado Registrada, Inscrita,Por Subsanar, Subsanación Recibida, Rechazada, Habilitada
                                        $propuestas = Propuestas::find("participante IN (" . $array_participantes . ") AND convocatoria IN (" . $in_convocatorias . ") AND estado IN (7,8,21,22,23,24,31,33,34,44)");

                                        //Valido si ya tiene el maximo de propuestas permitidas
                                        if (count($propuestas) < $propuestas_permitidas) {
                                            //Registro la accion en el log de convocatorias                                        
                                            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Selecciono la convocatoria (' . $id . ') en la validacion del proceso de inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                            $logger->close();
                                            echo "ingresar";
                                        } else {
                                            //Registro la accion en el log de convocatorias                                        
                                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, Supera el máximo permitido de propuestas de la convocatoria (' . $id . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                            $logger->close();
                                            echo "error_maximo";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Valida el acceso a la convocatoria para cambio de integrante

$app->post('/validar_acceso_cambio_integrante/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
//Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

//Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPost('token'));

//Validar array del usuario
    $user_current = json_decode($token_actual->user_current, true);

    try {

//Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

//Valido que envien id de la propuesta
            if (is_numeric($request->getPost('p'))) {

//Consulto la propuesta solicitada
                $conditions = ['id' => $request->getPost('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));

                if (isset($propuesta->id)) {
//verificar que el estado de la propuesta sea 34
//si no es 34 mostrar mensaje mostrando que no se puede hacer cambio de integrante por que la propuesta no es ganadora

                    if ($propuesta->estado != 34) {
                        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso_cambio_integrante, Selecciono la convocatoria (' . $id . ') el estado de la propuesta no es ganadora."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        return "error_estado";
                    }

//Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestaBusquedasConvocatorias en el método validar_acceso_cambio_integrante, Selecciono la convocatoria (' . $id . ') en la validacion del proceso de inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "ingresar";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"ERROR PROPUESTA NO VALIDA (' . $id . ') en la validacion del proceso de inscripción"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
//log
//retornar error codigo de propuesta no existe
                    return "error_propuesta";
                }
            }
        } else {
//Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
//Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestaBusquedasConvocatorias en el método validar_acceso, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}

function array_sort_by(&$arrIni, $col, $order = SORT_ASC) {
    $arrAux = array();
    foreach ($arrIni as $key => $row) {
        $arrAux[$key] = is_object($row) ? $arrAux[$key] = $row->$col : $row[$col];
        $arrAux[$key] = strtolower($arrAux[$key]);
    }
    array_multisort($arrAux, $order, $arrIni);
}

?>
